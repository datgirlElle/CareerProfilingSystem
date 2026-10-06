<?php

/**
 * Content-Based Filtering (CBF) recommendation engine using Cosine Similarity.
 *
 * Process (one call to recommend() runs all of it):
 *   1. Student profile   — the six RIASEC scores from the student's assessment
 *   2. Student vector    — [R, I, A, S, E, C]: currently 1 for the student's top 3
 *                           RIASEC types and 0 for the rest (config student_vector;
 *                           alternative: the six scores scaled 0-1) (studentFeatures)
 *   3. Course vectors    — each course's final Holland code encoded per letter
 *                           position (config holland_rank_weights). Currently
 *                           binary: letter in the code = 1, absent = 0
 *                           (programFeatures / hollandCodeToVector)
 *   4. Cosine similarity  — cos(S, C) = (S · C) / (||S|| × ||C||) between the
 *                           student vector and EVERY course vector
 *   5. Ranking            — all courses sorted by similarity, highest first; the
 *                           student's mapped courses are every course tied at the
 *                           highest similarity (config match_rule, selectMatches)
 *   6. Explanation        — which of the student's top RIASEC types the course shares
 *
 * Weights, encoding, scaling and N all live in config/cbf.php. The engine also
 * supports optional strand/elective blocks and a stated-program bonus; the
 * current methodology sets those to 0 so the ranking is pure RIASEC cosine.
 *
 * Why unit-length blocks scaled by sqrt(weight)? Because then, when both sides
 * have every block filled in, the cosine of the joined vectors is exactly
 *     Σ weight_k × cosine_k
 * i.e. a weighted average of the per-block similarities. The score stays ONE
 * true cosine similarity, yet each block's share of it can be reported
 * (the "contribution" values), which is what makes results explainable.
 * A block that is missing on either side is all zeros: it adds nothing to the
 * dot product and never causes a division by zero.
 *
 * The engine only uses the student's own profile and the program dataset — no
 * other students' data or ratings — which is what makes it content-based.
 */
class CBFEngine
{
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    public const RIASEC_LABELS = [
        'R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic',
        'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional',
    ];

    private static ?array $config = null;

    /** The settings in config/cbf.php (loaded once per request). */
    public static function config(): array
    {
        return self::$config ??= require __DIR__ . '/../config/cbf.php';
    }

    /** Every elective in the configured clusters, in a fixed order (= vector dimension order). */
    public static function electiveVocabulary(?array $config = null): array
    {
        $config ??= self::config();
        return array_values(array_unique(array_merge(...array_values($config['elective_clusters']))));
    }

    /** Human-readable name of every dimension, per block — used by the debug views. */
    public static function featureLabels(?array $config = null): array
    {
        $config ??= self::config();
        return [
            'riasec' => self::DIMENSIONS,
            'strand' => $config['strands'],
            'electives' => self::electiveVocabulary($config),
        ];
    }

    // ------------------------------------------------------------------
    // Step 2: feature extraction — attribute values -> numeric vectors
    // ------------------------------------------------------------------

    /**
     * A program's Holland code (e.g. "RIC") -> 6-dim RIASEC vector by rank:
     * primary=3, secondary=2, tertiary=1, absent=0 → "RIC" = [3,2,0,0,0,1].
     */
    public static function hollandCodeToVector(string $code, ?array $rankWeights = null): array
    {
        $rankWeights ??= self::config()['holland_rank_weights'];
        $weights = [];
        $letters = str_split(strtoupper($code));
        foreach (array_slice($letters, 0, count($rankWeights)) as $i => $letter) {
            $weights[$letter] = $rankWeights[$i];
        }
        return array_map(fn($d) => $weights[$d] ?? 0, self::DIMENSIONS);
    }

    /** @param array<string,int> $scores keyed by R,I,A,S,E,C */
    public static function scoresToVector(array $scores): array
    {
        return array_map(fn($d) => $scores[$d] ?? 0, self::DIMENSIONS);
    }

    /** Binary (1/0) vector: 1 where the vocabulary item is present in $values. */
    public static function multiHot(array $vocabulary, array $values): array
    {
        $present = array_flip($values);
        return array_map(fn($item) => isset($present[$item]) ? 1 : 0, $vocabulary);
    }

    /**
     * Student profile -> feature blocks.
     *
     * @param array{riasec?: ?array<string,int>, strand?: ?string, electives?: ?array<string>} $profile
     *        Any attribute may be missing/null; its block is then all zeros.
     * @return array{riasec: array, strand: array, electives: array}
     */
    public static function studentFeatures(array $profile, ?array $config = null): array
    {
        $config ??= self::config();

        $riasec = array_fill(0, 6, 0);
        if (!empty($profile['riasec']) && ($config['student_vector'] ?? 'scores') === 'top_binary') {
            // Top-N binary: 1 ("x") for the student's N highest RIASEC types, 0 otherwise.
            $top = self::topRiasecLetters($profile['riasec'], $config['student_top_n'] ?? 3);
            $riasec = self::multiHot(self::DIMENSIONS, $top);
        } elseif (!empty($profile['riasec'])) {
            // Raw totals (10..50) -> 0-1 scale. Dividing by the maximum does not
            // change cosine values; subtracting the floor does (see config/cbf.php).
            $riasec = self::scoresToVector($profile['riasec']);
            $max = $config['riasec_max'];
            if ($config['riasec_subtract_floor']) {
                $floor = $config['riasec_floor'];
                $riasec = array_map(fn($v) => max(0, $v - $floor) / (float) ($max - $floor), $riasec);
            } else {
                $riasec = array_map(fn($v) => $v / (float) $max, $riasec);
            }
        }

        return [
            'riasec' => $riasec,
            'strand' => self::multiHot($config['strands'], isset($profile['strand']) ? [$profile['strand']] : []),
            'electives' => self::multiHot(self::electiveVocabulary($config), $profile['electives'] ?? []),
        ];
    }

    /**
     * Program (career/course) record -> feature blocks, in the same layout as
     * studentFeatures() so the two can be compared dimension by dimension.
     *
     * @param array{hollandCode?: ?string, relatedStrands?: ?array<string>, collegeCode?: ?string} $program
     */
    public static function programFeatures(array $program, ?array $config = null): array
    {
        $config ??= self::config();
        $hollandCode = $program['hollandCode'] ?? '';

        return [
            'riasec' => $hollandCode !== ''
                ? self::hollandCodeToVector($hollandCode, $config['holland_rank_weights'])
                : array_fill(0, 6, 0),
            'strand' => self::multiHot($config['strands'], $program['relatedStrands'] ?? []),
            'electives' => self::multiHot(
                self::electiveVocabulary($config),
                $config['college_electives'][$program['collegeCode'] ?? ''] ?? []
            ),
        ];
    }

    // ------------------------------------------------------------------
    // Step 3: weighting — join the blocks into one comparable vector
    // ------------------------------------------------------------------

    /** Configured block weights rescaled to sum to 1 (blocks weighted 0 are dropped). */
    public static function normalizedWeights(?array $config = null): array
    {
        $config ??= self::config();
        $weights = array_filter($config['weights'], fn($w) => $w > 0);
        $total = array_sum($weights);
        return $total > 0 ? array_map(fn($w) => $w / $total, $weights) : [];
    }

    private static function magnitude(array $v): float
    {
        return sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
    }

    /**
     * Scale each block to unit length, multiply it by sqrt(weight), and join
     * all blocks into one flat vector. An all-zero block stays all zeros.
     */
    public static function weightedVector(array $blocks, array $weights): array
    {
        $vector = [];
        foreach ($weights as $name => $w) {
            $block = $blocks[$name];
            $mag = self::magnitude($block);
            foreach ($block as $x) {
                $vector[] = $mag > 0 ? ($x / $mag) * sqrt($w) : 0.0;
            }
        }
        return $vector;
    }

    // ------------------------------------------------------------------
    // Step 4: cosine similarity
    // ------------------------------------------------------------------

    /**
     * cos(A, B) = (A · B) / (||A|| × ||B||). Returns 0 (no similarity) when
     * either vector has zero magnitude, instead of dividing by zero.
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0;
            $dot += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }
        if ($magA <= 0 || $magB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Compare one student with one program: overall cosine plus each block's
     * own cosine and its contribution to the overall value
     * (contribution_k = weight_k × cosine_k / (||A|| × ||B||); they sum to the overall cosine).
     */
    public static function compare(array $studentBlocks, array $programBlocks, ?array $config = null): array
    {
        $weights = self::normalizedWeights($config);
        $studentVector = self::weightedVector($studentBlocks, $weights);
        $programVector = self::weightedVector($programBlocks, $weights);
        $cosine = self::cosineSimilarity($studentVector, $programVector);

        $denominator = self::magnitude($studentVector) * self::magnitude($programVector);
        $blocks = [];
        foreach ($weights as $name => $w) {
            $blockCosine = self::cosineSimilarity($studentBlocks[$name], $programBlocks[$name]);
            $blocks[$name] = [
                'weight' => round($w, 4),
                'cosine' => round($blockCosine, 4),
                'contribution' => $denominator > 0 ? round($w * $blockCosine / $denominator, 4) : 0.0,
            ];
        }

        return [
            'cosine' => $cosine,
            'blocks' => $blocks,
            'studentVector' => $studentVector,
            'programVector' => $programVector,
        ];
    }

    // ------------------------------------------------------------------
    // Step 7: explainability — the actual attribute values that matched
    // ------------------------------------------------------------------

    /** Student's top N RIASEC letters (highest score first; ties keep R,I,A,S,E,C order). */
    public static function topRiasecLetters(array $scores, int $n = 3): array
    {
        // Put the scores in R,I,A,S,E,C order first, so the stable sort breaks ties by that order.
        $ranked = array_intersect_key(array_merge(array_flip(self::DIMENSIONS), $scores), array_flip(self::DIMENSIONS));
        arsort($ranked);
        return array_slice(array_keys($ranked), 0, $n);
    }

    /** Which strand / RIASEC types / electives the student and program share. */
    public static function matchingFeatures(array $profile, array $program, ?array $config = null): array
    {
        $weights = self::normalizedWeights($config);
        $matches = ['strand' => [], 'riasec' => [], 'electives' => []];

        if (isset($weights['strand']) && !empty($profile['strand'])
            && in_array($profile['strand'], $program['relatedStrands'] ?? [], true)) {
            $matches['strand'] = [$profile['strand']];
        }

        if (isset($weights['riasec']) && !empty($profile['riasec']) && !empty($program['hollandCode'])) {
            $studentTop = self::topRiasecLetters($profile['riasec']);
            foreach (str_split(strtoupper($program['hollandCode'])) as $letter) {
                if (in_array($letter, $studentTop, true)) {
                    $matches['riasec'][] = self::RIASEC_LABELS[$letter];
                }
            }
        }

        if (isset($weights['electives'])) {
            $config ??= self::config();
            $programElectives = $config['college_electives'][$program['collegeCode'] ?? ''] ?? [];
            $matches['electives'] = array_values(array_intersect($profile['electives'] ?? [], $programElectives));
        }

        return $matches;
    }

    /** Plain-language sentence built only from the features that really matched. */
    public static function explain(array $matches, ?array $config = null): string
    {
        $join = function (array $items): string {
            if (count($items) <= 1) {
                return implode('', $items);
            }
            return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
        };

        $parts = [];
        if ($matches['strand']) {
            $parts[] = 'your ' . $matches['strand'][0] . ' strand';
        }
        if ($matches['riasec']) {
            $parts[] = 'your ' . $join($matches['riasec']) . ' interest' . (count($matches['riasec']) > 1 ? 's' : '');
        }
        if ($matches['electives']) {
            $parts[] = 'your ' . $join($matches['electives']) . ' elective' . (count($matches['electives']) > 1 ? 's' : '');
        }

        if (!$parts) {
            // Name only the criteria that are actually used (weight > 0).
            $labels = ['riasec' => 'top RIASEC types', 'strand' => 'strand', 'electives' => 'chosen electives'];
            $active = array_values(array_intersect_key($labels, self::normalizedWeights($config)));
            $list = count($active) > 1
                ? implode(', ', array_slice($active, 0, -1)) . ', or ' . end($active)
                : implode('', $active);
            return 'This program shares none of your ' . $list . ', so its match is low.';
        }
        // Groups are separated with an Oxford comma, since the groups themselves contain "and".
        $sentence = count($parts) > 2
            ? implode(', ', array_slice($parts, 0, -1)) . ', and ' . end($parts)
            : $join($parts);
        return 'Recommended because this program matches ' . $sentence . '.';
    }

    // ------------------------------------------------------------------
    // Steps 5-6: score every program and rank them
    // ------------------------------------------------------------------

    /**
     * @param array{riasec?: ?array<string,int>, strand?: ?string, electives?: ?array<string>} $profile
     * @param array<int,array{id:int,hollandCode:string,relatedStrands?:array,collegeCode?:string}> $programs
     *        the active program dataset — new programs are picked up automatically
     * @param int|null $statedProgramId the program the student picked on the worksheet, if any
     * @return array{all: array, top3: array, statedOutsideTop3: ?array}
     */
    public static function recommend(array $profile, array $programs, ?int $statedProgramId, ?array $config = null): array
    {
        $config ??= self::config();
        $studentBlocks = self::studentFeatures($profile, $config);
        $wSimilarity = $config['final_score']['similarity'];
        $wStated = $config['final_score']['stated_program'];

        $scored = array_map(function ($program) use ($profile, $studentBlocks, $statedProgramId, $config, $wSimilarity, $wStated) {
            $comparison = self::compare($studentBlocks, self::programFeatures($program, $config), $config);
            $indicator = ($statedProgramId !== null && $program['id'] === $statedProgramId) ? 1.0 : 0.0;
            $matches = self::matchingFeatures($profile, $program, $config);
            return $program + [
                'cosine' => round($comparison['cosine'], 4),
                'indicator' => $indicator,
                'score' => round($wSimilarity * $comparison['cosine'] + $wStated * $indicator, 4),
                'blocks' => $comparison['blocks'],
                'matches' => $matches,
                'explanation' => self::explain($matches, $config),
            ];
        }, $programs);

        // Highest Final Match Score first; equal scores fall back to the higher
        // cosine, then the lower program id, so the order is always reproducible.
        usort($scored, fn($a, $b) => [$b['score'], $b['cosine'], $a['id']] <=> [$a['score'], $a['cosine'], $b['id']]);

        // 'top3' holds the student's mapped courses (config match_rule; see selectMatches).
        $top3 = self::selectMatches($scored, 'score', $config);
        $statedInTop3 = $statedProgramId !== null && in_array($statedProgramId, array_column($top3, 'id'), true);

        $statedEntry = null;
        if ($statedProgramId !== null && !$statedInTop3) {
            foreach ($scored as $s) {
                if ($s['id'] === $statedProgramId) {
                    $statedEntry = $s;
                    break;
                }
            }
        }

        return ['all' => $scored, 'top3' => $top3, 'statedOutsideTop3' => $statedEntry];
    }

    /**
     * The student's mapped courses from a list already sorted by score (highest first).
     *   match_rule 'highest': every entry tied at the highest score; none if that score is 0.
     *   match_rule 'top_n'  : the first top_n entries.
     * Used by recommend() and by api/recommendations.php on saved results, so both agree.
     */
    public static function selectMatches(array $sorted, string $scoreKey = 'score', ?array $config = null): array
    {
        $config ??= self::config();
        if (($config['match_rule'] ?? 'top_n') !== 'highest') {
            return array_slice($sorted, 0, $config['top_n'] ?? 3);
        }
        $best = $sorted ? (float) $sorted[0][$scoreKey] : 0.0;
        if ($best <= 0) {
            return [];
        }
        return array_values(array_filter($sorted, fn($e) => abs((float) $e[$scoreKey] - $best) < 1e-9));
    }

    /**
     * Developer/thesis view of one recommendation run: every intermediate value
     * (raw feature blocks, weighted vectors, per-block cosines, final score) for
     * the student and each program, in ranked order. Used by api/cbf-debug.php
     * and db/cbf_debug.php — never shown to students.
     */
    public static function trace(array $profile, array $programs, ?int $statedProgramId, ?array $config = null): array
    {
        $config ??= self::config();
        $weights = self::normalizedWeights($config);
        $studentBlocks = self::studentFeatures($profile, $config);
        $ranked = self::recommend($profile, $programs, $statedProgramId, $config)['all'];

        $round = fn(array $v) => array_map(fn($x) => round($x, 4), $v);

        return [
            'config' => [
                'weights' => array_map(fn($w) => round($w, 4), $weights),
                'finalScore' => $config['final_score'],
                'riasecSubtractFloor' => $config['riasec_subtract_floor'],
                'studentVector' => $config['student_vector'] ?? 'scores',
                'studentTopN' => $config['student_top_n'] ?? 3,
            ],
            'featureLabels' => array_intersect_key(self::featureLabels($config), $weights),
            'student' => [
                'profile' => $profile,
                'featureBlocks' => array_intersect_key($studentBlocks, $weights),
                'vector' => $round(self::weightedVector($studentBlocks, $weights)),
            ],
            'programs' => array_map(function ($entry, $rank) use ($config, $weights, $round) {
                $programBlocks = self::programFeatures($entry, $config);
                return [
                    'rank' => $rank + 1,
                    'id' => $entry['id'],
                    'title' => $entry['title'] ?? null,
                    'hollandCode' => $entry['hollandCode'],
                    'relatedStrands' => $entry['relatedStrands'] ?? [],
                    'collegeCode' => $entry['collegeCode'] ?? null,
                    'featureBlocks' => array_intersect_key($programBlocks, $weights),
                    'vector' => $round(self::weightedVector($programBlocks, $weights)),
                    'cosine' => $entry['cosine'],
                    'blocks' => $entry['blocks'],
                    'indicator' => $entry['indicator'],
                    'score' => $entry['score'],
                    'matches' => $entry['matches'],
                    'explanation' => $entry['explanation'],
                ];
            }, $ranked, array_keys($ranked)),
        ];
    }
}
