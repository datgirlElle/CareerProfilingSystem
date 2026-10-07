<?php

require_once __DIR__ . '/CBFEngine.php';

/**
 * Recommendation sources, kept separate.
 *
 *   Source 1  CBF                "Which courses have RIASEC characteristics most similar to
 *                                 this student's RIASEC profile?" -> cosine similarity
 *                                 (lib/CBFEngine.php):
 *                                   Best RIASEC Match = courses tied at the highest similarity
 *                                   Alternative Courses = courses at the next-highest similarity
 *   Source 2  Worksheet          "Which course did the student select?" -> the Preferred Course.
 *                                 Shown on its own; it never changes, forces or replaces the CBF
 *                                 result, and never enters the cosine calculation.
 *   Source 3  Prediction model   WEKA decision tree. NOT AVAILABLE in this version: the real
 *                                 dataset does not exist yet, so this source is always reported
 *                                 as unavailable and never produces a course. The existing
 *                                 WEKA code (lib/PredictionModel.php) is not called.
 *
 *   Final Best Match (CBF + prediction model + worksheet) is NOT computed yet: it needs the
 *   prediction model and an adviser-approved combination rule. finalBestMatchIds is null and
 *   isComplete is false until then.
 *
 * No source is given a numeric weight.
 */
class RecommendationPipeline
{
    /**
     * Runs every source for one student.
     *
     * @param array $profile CBFData::studentProfile() (uses 'riasec')
     * @param array $programs CBFData::activePrograms()
     * @param int[] $preferredIds Preferred Course(s) from the worksheet, in the order entered
     */
    public static function run(array $profile, array $programs, array $preferredIds, ?array $config = null): array
    {
        $cbf = self::cbfRecommendation($profile['riasec'] ?? null, $programs, $config);
        $worksheet = self::worksheetRecommendation($preferredIds, $programs);
        $prediction = self::predictionRecommendation();
        return ['cbf' => $cbf, 'worksheet' => $worksheet, 'prediction' => $prediction]
            + self::finalRecommendation($cbf, $worksheet, $prediction);
    }

    /** Source 1: CBF. status 'unavailable' (with reason) if the RIASEC profile is missing/invalid. */
    public static function cbfRecommendation(?array $riasecScores, array $programs, ?array $config = null): array
    {
        try {
            $r = CBFEngine::compute($riasecScores, $programs, $config);
        } catch (InvalidArgumentException $e) {
            return ['status' => 'unavailable', 'reason' => $e->getMessage(), 'candidateIds' => [], 'results' => [], 'excluded' => []];
        }
        return ['status' => 'available', 'reason' => null, 'candidateIds' => $r['candidates']] + $r;
    }

    /** Source 2: Worksheet: the Preferred Course(s) that are active programs; 'empty' if none. */
    public static function worksheetRecommendation(array $preferredIds, array $programs): array
    {
        $active = array_map('intval', array_column($programs, 'id'));
        $ids = array_values(array_filter(array_unique(array_map('intval', $preferredIds)), fn($id) => in_array($id, $active, true)));
        return ['status' => $ids ? 'available' : 'empty', 'courseIds' => $ids];
    }

    /**
     * Source 3: Prediction model: unavailable until the real dataset and trained model exist.
     * Deliberately returns no course (no placeholder or sample prediction).
     */
    public static function predictionRecommendation(): array
    {
        return ['status' => 'unavailable', 'courseIds' => [],
            'reason' => 'Prediction model (WEKA) not yet available: excluded from this version.'];
    }

    /**
     * Combines the sources for display (pure; tested in tests/pipeline_test.php). The CBF
     * result is passed through unchanged; the Preferred Course is only compared with it.
     *
     * @return array{bestRiasecMatchIds:int[], alternativeIds:int[], preferred:array, status:string, reason:?string,
     *               finalBestMatchIds:null, sourcesUsed:string[], pendingSources:string[], isComplete:bool}
     */
    public static function finalRecommendation(array $cbf, array $worksheet, array $prediction): array
    {
        $available = $cbf['status'] === 'available';
        $best = $available ? $cbf['candidateIds'] : [];
        $alternatives = $available ? ($cbf['alternatives'] ?? []) : [];

        $preferred = array_map(fn($id) => ['id' => $id, 'inBestRiasecMatch' => in_array($id, $best, true)], $worksheet['courseIds']);

        // Does the student's preference agree with the RIASEC-based (CBF) result?
        if (!$available) {
            [$status, $reason] = ['mismatch', 'no_cbf_result'];
        } elseif (!$best) {
            [$status, $reason] = ['mismatch', 'no_cbf_match'];
        } elseif ($worksheet['status'] !== 'available') {
            [$status, $reason] = ['mismatch', 'no_worksheet'];
        } elseif (!$preferred[0]['inBestRiasecMatch']) {
            [$status, $reason] = ['mismatch', 'preferred_not_matched'];
        } else {
            [$status, $reason] = ['match', null];
        }

        $sourcesUsed = $worksheet['status'] === 'available' ? ['cbf', 'worksheet'] : ['cbf'];
        // FUTURE (prediction model): once $prediction['status'] === 'available' and the adviser
        // has approved the combination rule, compute the final Best Match here from the CBF,
        // the prediction model and the worksheet, and set isComplete = true.
        $pending = $prediction['status'] === 'available' ? [] : ['prediction'];

        return [
            'bestRiasecMatchIds' => $best,
            'alternativeIds' => $alternatives,
            'preferred' => $preferred,
            'status' => $status,
            'reason' => $reason,
            'finalBestMatchIds' => null,
            'sourcesUsed' => $sourcesUsed,
            'pendingSources' => $pending,
            'isComplete' => false,
        ];
    }
}
