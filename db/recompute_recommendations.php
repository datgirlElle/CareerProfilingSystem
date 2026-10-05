<?php
/**
 * Recompute every student's recommendation with the CURRENT CBF settings
 * (config/cbf.php). Results are saved only when a student submits the Career
 * Worksheet, so after changing the method, existing students keep showing
 * their old scores until this is run.
 *
 *   php db/recompute_recommendations.php            recompute everyone who has a worksheet
 *   php db/recompute_recommendations.php --dry-run  show what would change, save nothing
 *
 * Uses each student's latest RIASEC assessment and latest worksheet (stated
 * program and electives). A new `recommendations` row is added per student;
 * older rows stay as history.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();
$students = $pdo->query(
    "SELECT s.user_id, s.school_id, a.id AS assessment_id,
            (SELECT w.id FROM worksheets w WHERE w.student_id = s.user_id ORDER BY w.submitted_at DESC LIMIT 1) AS worksheet_id,
            (SELECT r.top_score FROM recommendations r WHERE r.student_id = s.user_id ORDER BY r.computed_at DESC LIMIT 1) AS old_top_score
     FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     ORDER BY s.school_id"
)->fetchAll();

$done = 0;
foreach ($students as $st) {
    if ($st['worksheet_id'] === null) {
        continue; // no worksheet yet -> no recommendation to refresh
    }
    $profile = CBFData::studentProfile($pdo, (int) $st['user_id']);
    $label = sprintf('%-15s', $st['school_id']);
    $old = $st['old_top_score'] !== null ? sprintf('%.0f%%', $st['old_top_score'] * 100) : '—';

    if ($dryRun) {
        $r = CBFEngine::recommend($profile, CBFData::activePrograms($pdo), $profile['statedProgramId']);
        printf("%s top score %s -> %.0f%%  (%s)\n", $label, $old, $r['top3'][0]['score'] * 100, $r['top3'][0]['title']);
        $done++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $saved = CBFData::saveRecommendation(
            $pdo, (int) $st['user_id'], $profile, $profile['statedProgramId'], (int) $st['assessment_id'], (int) $st['worksheet_id']
        );
        $pdo->commit();
        printf("%s top score %s -> %.0f%%\n", $label, $old, $saved['topScore'] * 100);
        $done++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        printf("%s FAILED: %s\n", $label, $e->getMessage());
    }
}

echo ($dryRun ? "Dry run: $done students would be recomputed (nothing saved).\n" : "$done students recomputed with the current CBF settings.\n");
