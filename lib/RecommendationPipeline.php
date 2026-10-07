<?php

require_once __DIR__ . '/CBFEngine.php';
require_once __DIR__ . '/PredictionModel.php';

/**
 * The recommendation process, as approved by the adviser:
 *
 *   Stage 1  Student profile   RIASEC scores (+ strand/electives) and the Preferred Course
 *                              from the Career Electives Worksheet
 *   Stage 2  CBF               cosine similarity between the student's RIASEC profile and
 *                              every program's Holland code -> CBF match set
 *   Stage 3  Prediction model  decision tree trained in WEKA -> predicted course
 *   Stage 4  Combination       Top Matches = CBF matches ∩ prediction
 *                              (no common course -> the CBF matches are shown, and the
 *                              student is referred to the Guidance Office)
 *   Stage 5  Preferred course  is it among the Top Matches? (shown in its own section;
 *                              it never changes the Top Matches)
 *   Stage 6  Output            finalIds = the one Top Matches list
 *
 * No weights: the CBF uses binary RIASEC vectors and cosine similarity only, and the
 * preferred course has no numeric weight. Lists keep the program-list order; they are
 * sets, not rankings.
 */
class RecommendationPipeline
{
    /**
     * Runs stages 2-6 for one student.
     *
     * @param array $profile CBFData::studentProfile()
     * @param array $programs CBFData::activePrograms()
     * @param int[] $preferredIds Preferred Course(s) from the worksheet, in the order entered
     * @param array|null $model imported prediction model (default: config/prediction_model.json)
     */
    public static function run(array $profile, array $programs, array $preferredIds, ?array $config = null, ?array $model = null): array
    {
        $config ??= CBFEngine::config();

        // Stage 2: CBF (the stated program gets no weight: final_score.stated_program = 0)
        $cbf = CBFEngine::recommend($profile, $programs, $preferredIds[0] ?? null, $config);
        $cbfIds = array_map('intval', array_column($cbf['top3'], 'id'));

        // Stage 3: prediction model (only when enabled; null = not available)
        $predictionIds = null;
        if ($config['decision_tree']['enabled'] ?? false) {
            $top = CBFEngine::topRiasecLetters($profile['riasec'] ?? [], (int) ($config['student_top_n'] ?? 3));
            $predictionIds = PredictionModel::predict($top, $programs, $model);
        }

        // Stages 4-6
        return ['cbf' => $cbf, 'modelVersion' => $predictionIds !== null ? (($model ?? PredictionModel::load())['version'] ?? null) : null]
            + self::combine($cbfIds, $predictionIds, $preferredIds, $config);
    }

    /**
     * Stages 4-6 (pure; unit-tested in tests/pipeline_test.php).
     *
     * @param int[]      $cbfIds        CBF match set
     * @param int[]|null $predictionIds prediction model output (null = model not available)
     * @param int[]      $preferredIds  Preferred Course(s), in the order entered on the worksheet
     * @return array{cbfIds:int[], predictionIds:?array, commonIds:?array, finalIds:int[], usedFallback:bool,
     *               preferred:array<int,array{id:int,inTopMatches:bool}>, status:string, reason:?string, rule:string}
     */
    public static function combine(array $cbfIds, ?array $predictionIds, array $preferredIds, ?array $config = null): array
    {
        $config ??= CBFEngine::config();
        $cbfIds = array_values(array_unique(array_map('intval', $cbfIds)));
        $useModel = ($config['decision_tree']['enabled'] ?? false) && $predictionIds !== null;
        $rule = $useModel ? ($config['mismatch']['definition'] ?? 'no_common_course') : ($config['mismatch']['interim'] ?? 'preferred_not_matched');

        $commonIds = null;
        $usedFallback = false;
        if ($useModel) {
            $predictionIds = array_values(array_unique(array_map('intval', $predictionIds)));
            // Stage 4: common courses, in CBF (program-list) order
            $commonIds = array_values(array_filter($cbfIds, fn($id) => in_array($id, $predictionIds, true)));
            $finalIds = $commonIds ?: $cbfIds;
            $usedFallback = !$commonIds && (bool) $cbfIds;
        } else {
            $finalIds = $cbfIds; // until the model is connected, the Top Matches are the CBF matches
        }

        // Stage 5: Preferred Course(s) — status only, the Top Matches are not changed.
        $preferred = [];
        foreach (array_values(array_unique(array_map('intval', $preferredIds))) as $id) {
            $preferred[] = ['id' => $id, 'inTopMatches' => in_array($id, $finalIds, true)];
        }

        // Match / mismatch (team definition)
        if (!$cbfIds) {
            [$status, $reason] = ['mismatch', 'no_cbf_match'];
        } elseif ($rule === 'no_common_course') {
            [$status, $reason] = $commonIds ? ['match', null] : ['mismatch', 'no_common_course'];
        } elseif ($preferred && !$preferred[0]['inTopMatches']) {
            [$status, $reason] = ['mismatch', 'preferred_not_matched'];
        } else {
            [$status, $reason] = ['match', null];
        }

        return [
            'cbfIds' => $cbfIds,
            'predictionIds' => $useModel ? $predictionIds : null,
            'commonIds' => $commonIds,
            'finalIds' => $finalIds,
            'usedFallback' => $usedFallback,
            'preferred' => $preferred,
            'status' => $status,
            'reason' => $reason,
            'rule' => $rule,
        ];
    }
}
