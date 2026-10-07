<?php
/**
 * Recompute every student's recommendation with the CURRENT settings
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
require_once __DIR__ . '/../lib/RecommendationPipeline.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();
$students = $pdo->query(
    "SELECT s.user_id, s.school_id, a.id AS assessment_id,
            (SELECT w.id FROM worksheets w WHERE w.student_id = s.user_id ORDER BY w.submitted_at DESC LIMIT 1) AS worksheet_id
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

    if ($dryRun) {
        $programs = CBFData::activePrograms($pdo);
        $r = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
        $titles = array_column($programs, 'title', 'id');
        printf("%s %s%s  Best RIASEC Match: %s\n", $label, $r['status'], $r['reason'] ? " ({$r['reason']})" : '',
            implode(', ', array_map(fn($id) => $titles[$id], $r['bestRiasecMatchIds'])) ?: '-');
        $done++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $saved = CBFData::saveRecommendation(
            $pdo, (int) $st['user_id'], $profile, $profile['statedProgramId'], (int) $st['assessment_id'], (int) $st['worksheet_id']
        );
        $pdo->commit();
        printf("%s %s%s  Best RIASEC Match: %s\n", $label, $saved['status'], $saved['reason'] ? " ({$saved['reason']})" : '', implode(', ', $saved['bestRiasecMatchTitles']) ?: '-');
        $done++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        printf("%s FAILED: %s\n", $label, $e->getMessage());
    }
}

echo ($dryRun ? "Dry run: $done students would be recomputed (nothing saved).\n" : "$done students recomputed with the current CBF settings (config/cbf.php).\n");
