<?php

require_once __DIR__ . '/Careers.php';
require_once __DIR__ . '/CBFEngine.php';

/**
 * Links a career a student typed on the Career Worksheet to an MMCL program.
 *
 * The student types freely; this compares the words they used with each active
 * program's careers list and title (lightly stemmed, ignoring filler and very
 * generic words like "manager"/"engineer" unless that is all they typed). When
 * several programs match equally well, the one that fits the student's own
 * RIASEC scores best wins. If nothing is close enough there is no link: the
 * career is kept as typed, with no program, so the worksheet bonus and the
 * letter-by-letter match simply don't apply.
 */
class CareerMatcher
{
    /** A program must cover at least this share of the typed career's words. */
    private const MIN_SCORE = 0.5;

    private const STOPWORDS = ['of', 'the', 'and', 'in', 'for', 'to', 'a', 'an', 'with', 'major', 'bs', 'ba', 'at', 'on', 'or', 'as', 'i', 'want', 'be'];

    /** Job-title words too generic to prove two careers are related. */
    private const GENERIC = [
        'manager', 'engineer', 'specialist', 'officer', 'analyst', 'assistant', 'coordinator', 'consultant',
        'technician', 'developer', 'administrator', 'executive', 'head', 'senior', 'junior', 'associate',
        'professional', 'worker', 'expert', 'staff', 'designer', 'design',
    ];

    private const SUFFIXES = ['ing', 'ists', 'ist', 'ers', 'er', 'ors', 'or', 'ies', 'es', 's', 'y', 'e'];

    private static function stem(string $word): string
    {
        // Strip suffixes until none apply, so "engineer" and "engineering"
        // (and "nurse"/"nursing", "pharmacist"/"pharmacy") reach the same stem.
        do {
            $before = $word;
            foreach (self::SUFFIXES as $suffix) {
                $len = strlen($suffix);
                if (strlen($word) - $len >= 4 && substr($word, -$len) === $suffix) {
                    $word = substr($word, 0, -$len);
                    break;
                }
            }
        } while ($word !== $before);
        return $word;
    }

    /** @return array<int,string> stemmed words, filler removed */
    private static function words(string $text): array
    {
        $text = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text));
        $out = [];
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            if ($word === '' || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $word;
        }
        return $out;
    }

    /**
     * Words that carry the meaning: generic job-title words dropped, unless
     * dropping them would leave nothing.
     *
     * @return array<int,string>
     */
    private static function keywords(string $text): array
    {
        $all = self::words($text);
        $informative = array_values(array_filter($all, fn($w) => !in_array($w, self::GENERIC, true)));
        $use = $informative !== [] ? $informative : $all;
        return array_values(array_unique(array_map([self::class, 'stem'], $use)));
    }

    /**
     * @param array<int,array{id:int,title:string,careers:array<int,string>}> $programs
     * @return array<int,array{id:int,score:float,exact:bool}> best first; programs below the threshold are left out
     */
    public static function rank(string $typed, array $programs): array
    {
        $typedKey = implode(' ', self::words($typed));
        $typedWords = self::keywords($typed);
        if ($typedKey === '' || $typedWords === []) {
            return [];
        }

        $ranked = [];
        foreach ($programs as $program) {
            $candidates = array_merge($program['careers'], [$program['title']]);
            $best = 0.0;
            $exact = false;
            foreach ($candidates as $candidate) {
                if ($typedKey === implode(' ', self::words($candidate))) {
                    $best = 1.0;
                    $exact = true;
                    break;
                }
                $candidateWords = self::keywords($candidate);
                if ($candidateWords === []) {
                    continue;
                }
                $score = count(array_intersect($typedWords, $candidateWords)) / count($typedWords);
                $best = max($best, $score);
            }
            if ($best >= self::MIN_SCORE) {
                $ranked[] = ['id' => (int) $program['id'], 'score' => $best, 'exact' => $exact];
            }
        }

        usort($ranked, fn($a, $b) => [$b['exact'], $b['score']] <=> [$a['exact'], $a['score']]);
        return $ranked;
    }

    /**
     * Picks the single best program for a typed career, breaking ties with the
     * student's RIASEC fit. Null when nothing is close enough.
     *
     * @param array<int,array{id:int,title:string,careers:array<int,string>,hollandCode:string}> $programs
     * @param array<string,int>|null $studentScores RIASEC scores keyed R,I,A,S,E,C, if the student has them
     */
    public static function resolve(string $typed, array $programs, ?array $studentScores): ?int
    {
        $ranked = self::rank($typed, $programs);
        if ($ranked === []) {
            return null;
        }

        $top = array_values(array_filter($ranked, fn($r) => $r['exact'] === $ranked[0]['exact'] && abs($r['score'] - $ranked[0]['score']) < 1e-9));
        if (count($top) === 1 || $studentScores === null) {
            return $top[0]['id'];
        }

        $byId = [];
        foreach ($programs as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $studentVec = CBFEngine::scoresToVector($studentScores);
        $bestId = $top[0]['id'];
        $bestCosine = -1.0;
        foreach ($top as $candidate) {
            $cosine = CBFEngine::cosineSimilarity($studentVec, CBFEngine::hollandCodeToVector($byId[$candidate['id']]['hollandCode']));
            if ($cosine > $bestCosine) {
                $bestCosine = $cosine;
                $bestId = $candidate['id'];
            }
        }
        return $bestId;
    }

    /**
     * Cleans what the student typed. Null when it isn't usable.
     */
    public static function sanitize(string $typed): ?string
    {
        $career = trim(preg_replace('/\s+/u', ' ', $typed));
        if (mb_strlen($career) < 2 || mb_strlen($career) > 80) {
            return null;
        }
        if (!preg_match('/^[\p{L}\p{N} \-\/&\'.,()]+$/u', $career)) {
            return null;
        }
        if (!preg_match('/\p{L}/u', $career)) {
            return null;
        }
        return $career;
    }

    /**
     * Loads active programs in the shape rank()/resolve() want.
     *
     * @return array<int,array{id:int,title:string,careers:array<int,string>,hollandCode:string,collegeCode:string,collegeName:string}>
     */
    public static function loadPrograms(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT p.id, p.title_enc, p.holland_code_enc, p.careers, c.code AS college_code, c.name AS college_name
             FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.status = 'Active' ORDER BY p.id"
        )->fetchAll();
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'title' => Crypto::dec($r['title_enc']),
            'careers' => Careers::parse($r['careers']),
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
        ], $rows);
    }

    /** @return array<string,int>|null the student's latest RIASEC scores, keyed R,I,A,S,E,C */
    public static function latestScores(PDO $pdo, int $studentId): ?array
    {
        $stmt = $pdo->prepare('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE student_id = ? AND is_latest = TRUE');
        $stmt->execute([$studentId]);
        $a = $stmt->fetch();
        if (!$a) {
            return null;
        }
        return [
            'R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
            'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c'],
        ];
    }
}
