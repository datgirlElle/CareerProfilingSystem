<?php
/**
 * Adds the WEKA J48 prediction (config/prediction_model.json) to each student's current
 * recommendation, for results saved before the prediction model was switched on.
 *
 *   php db/backfill_predictions.php            fill in every current recommendation
 *   php db/backfill_predictions.php --dry-run  show what would be saved, save nothing
 *
 * Only prediction_program_ids and model_version change. The CBF result, the Preferred
 * Course, the match status and the Monitoring flags stay as they are, and no new
 * recommendation rows are added (unlike db/recompute_recommendations.php).
 * Safe to run again: it always writes the current model's prediction.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../lib/RecommendationPipeline.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();
$programs = CBFData::activePrograms($pdo);
$titles = array_column($programs, 'title', 'id');

// The row api/recommendations.php shows: the newest one for the student's latest assessment.
$rows = $pdo->query(
    "SELECT DISTINCT ON (r.student_id) r.id, s.school_id, a.score_r, a.score_i, a.score_a, a.score_s, a.score_e, a.score_c
     FROM recommendations r
     JOIN assessments a ON a.id = r.source_assessment_id AND a.is_latest = TRUE
     JOIN students s ON s.user_id = r.student_id
     ORDER BY r.student_id, r.computed_at DESC"
)->fetchAll();

$update = $pdo->prepare('UPDATE recommendations SET prediction_program_ids = ?, model_version = ? WHERE id = ?');
$counts = ['available' => 0, 'no_prediction' => 0, 'unavailable' => 0];
foreach ($rows as $r) {
    $scores = ['R' => (int) $r['score_r'], 'I' => (int) $r['score_i'], 'A' => (int) $r['score_a'],
               'S' => (int) $r['score_s'], 'E' => (int) $r['score_e'], 'C' => (int) $r['score_c']];
    $p = RecommendationPipeline::predictionRecommendation($scores, $programs);
    $counts[$p['status']]++;
    printf("%-15s %s%s\n", $r['school_id'], $p['status'],
        $p['courseIds'] ? ': ' . $titles[$p['courseIds'][0]] : ($p['reason'] ? " ({$p['reason']})" : ''));
    if (!$dryRun) {
        $update->execute([
            $p['status'] === 'unavailable' ? null : CBFData::intArrayLiteral($p['courseIds']),
            $p['modelVersion'] ?? null,
            (int) $r['id'],
        ]);
    }
}

printf("\n%s %d recommendations: %d predicted, %d with no prediction, %d unavailable.\n",
    $dryRun ? 'Dry run (nothing saved):' : 'Updated', count($rows), $counts['available'], $counts['no_prediction'], $counts['unavailable']);
