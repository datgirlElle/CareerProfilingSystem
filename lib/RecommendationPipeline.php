<?php

require_once __DIR__ . '/CBFEngine.php';

/**
 * Recommendation sources and the final recommendation.
 *
 *   Source 1  CBF                "Which courses have RIASEC characteristics most similar to
 *                                 this student's RIASEC profile?" -> cosine similarity
 *                                 (lib/CBFEngine.php) -> CBF candidates
 *   Source 2  Worksheet          "Which course does the student's Career Electives Worksheet
 *                                 indicate?" -> the Preferred Course
 *   Source 3  Prediction model   WEKA decision tree. NOT AVAILABLE in this version: the real
 *                                 dataset does not exist yet, so this source is always reported
 *                                 as unavailable and never produces a course. The existing
 *                                 WEKA code (lib/PredictionModel.php) is not called.
 *
 *   Final     Best Match = the course(s) common to the AVAILABLE sources (now: CBF candidates
 *             ∩ worksheet). Alternative Courses = the other CBF candidates.
 *             The result is marked incomplete (isComplete = false) because the prediction
 *             model is still pending; once it exists, its courses join the intersection in
 *             finalRecommendation() (see the marked place below).
 *
 * The sources are kept separate: the worksheet never enters the cosine calculation, and no
 * source is given a numeric weight.
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
     * Final recommendation from the available sources (pure; tested in tests/pipeline_test.php).
     *
     * @return array{bestMatchIds:int[], alternativeIds:int[], commonIds:int[], preferred:array,
     *               status:string, reason:?string, sourcesUsed:string[], pendingSources:string[], isComplete:bool}
     */
    public static function finalRecommendation(array $cbf, array $worksheet, array $prediction): array
    {
        $candidates = $cbf['status'] === 'available' ? $cbf['candidateIds'] : [];
        $sourcesUsed = ['cbf'];
        $common = $candidates;
        if ($worksheet['status'] === 'available') {
            $sourcesUsed[] = 'worksheet';
            $common = array_values(array_filter($common, fn($id) => in_array($id, $worksheet['courseIds'], true)));
        } else {
            $common = []; // no worksheet: nothing to combine with yet
        }
        // FUTURE (prediction model): when $prediction['status'] === 'available', add 'prediction' to
        // $sourcesUsed and keep only the courses also in $prediction['courseIds'] here.
        $pending = $prediction['status'] === 'available' ? [] : ['prediction'];

        $bestMatchIds = $common;
        $alternativeIds = array_values(array_filter($candidates, fn($id) => !in_array($id, $bestMatchIds, true)));

        $preferred = array_map(fn($id) => [
            'id' => $id,
            'inCbfCandidates' => in_array($id, $candidates, true),
            'isBestMatch' => in_array($id, $bestMatchIds, true),
        ], $worksheet['courseIds']);

        if ($cbf['status'] !== 'available') {
            [$status, $reason] = ['mismatch', 'no_cbf_result'];
        } elseif (!$candidates) {
            [$status, $reason] = ['mismatch', 'no_cbf_match'];
        } elseif ($worksheet['status'] !== 'available') {
            [$status, $reason] = ['mismatch', 'no_worksheet'];
        } elseif (!$bestMatchIds) {
            [$status, $reason] = ['mismatch', 'preferred_not_matched'];
        } else {
            [$status, $reason] = ['match', null];
        }

        return [
            'bestMatchIds' => $bestMatchIds,
            'alternativeIds' => $alternativeIds,
            'commonIds' => $common,
            'preferred' => $preferred,
            'status' => $status,
            'reason' => $reason,
            'sourcesUsed' => $sourcesUsed,
            'pendingSources' => $pending,
            'isComplete' => !$pending,
        ];
    }
}
