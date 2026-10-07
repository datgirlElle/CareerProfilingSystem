<?php
/**
 * Command-line trace of the CBF recommendation engine for one real student —
 * for development and for Chapter 4 (Results and Discussion) tables.
 *
 *   php db/cbf_debug.php <school_id>            readable report
 *   php db/cbf_debug.php <school_id> --csv      ranking as CSV (paste into Excel/Word)
 *   php db/cbf_debug.php <school_id> --json     full trace, same as api/cbf-debug.php
 *
 * Read-only: recomputes from the student's current profile and the Active
 * program dataset; nothing is written to the database.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../lib/RecommendationPipeline.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$schoolId = $argv[1] ?? null;
$format = $argv[2] ?? '';
if ($schoolId === null) {
    fwrite(STDERR, "Usage: php db/cbf_debug.php <school_id> [--csv|--json]\n");
    exit(1);
}

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT user_id FROM students WHERE LOWER(school_id) = LOWER(?)');
$stmt->execute([$schoolId]);
$studentId = (int) $stmt->fetchColumn();
$profile = $studentId > 0 ? CBFData::studentProfile($pdo, $studentId) : null;
if ($profile === null) {
    fwrite(STDERR, "No student with school ID $schoolId.\n");
    exit(1);
}

$trace = CBFEngine::trace($profile, CBFData::activePrograms($pdo), $profile['statedProgramId']);

if ($format === '--json') {
    echo json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

$blockNames = array_keys($trace['config']['weights']);

if ($format === '--csv') {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, array_merge(
        ['Rank', 'Program', 'Holland Code', 'Related Strands'],
        array_map(fn($b) => "cos_$b", $blockNames),
        ['Cosine Similarity', 'Stated Indicator', 'Final Match Score', 'Explanation']
    ));
    foreach ($trace['programs'] as $p) {
        fputcsv($out, array_merge(
            [$p['rank'], $p['title'], $p['hollandCode'], implode(' ', $p['relatedStrands'])],
            array_map(fn($b) => $p['blocks'][$b]['cosine'], $blockNames),
            [$p['cosine'], $p['indicator'], $p['score'], $p['explanation']]
        ));
    }
    exit;
}

// Readable report: only the non-zero dimensions of each block are listed, as label=value.
$nonZero = function (array $blocks) use ($trace): string {
    $parts = [];
    foreach ($blocks as $name => $values) {
        $items = [];
        foreach ($values as $i => $v) {
            if ($v != 0) {
                $items[] = $trace['featureLabels'][$name][$i] . '=' . $v;
            }
        }
        $parts[] = "$name: " . ($items ? implode(', ', $items) : '(none)');
    }
    return implode("\n    ", $parts);
};

echo "=== CBF trace for student $schoolId ===\n";
echo 'Weights: ' . json_encode($trace['config']['weights']) . '   Final score: ' . json_encode($trace['config']['finalScore']) . "\n\n";
echo "Student profile\n";
echo '  Strand: ' . ($profile['strand'] ?? '—') . "\n";
echo '  RIASEC: ' . ($profile['riasec'] ? json_encode($profile['riasec']) : '(no assessment)') . "\n";
echo '  Electives: ' . ($profile['electives'] ? implode('; ', $profile['electives']) : '(none)') . "\n";
echo "  Feature blocks:\n    " . $nonZero($trace['student']['featureBlocks']) . "\n";
echo '  Weighted student vector A = [' . implode(', ', $trace['student']['vector']) . "]\n\n";

foreach ($trace['programs'] as $p) {
    printf("#%d  %s  (%s, %s)\n", $p['rank'], $p['title'], $p['hollandCode'], $p['collegeCode']);
    echo "    " . $nonZero($p['featureBlocks']) . "\n";
    echo '    Weighted program vector B = [' . implode(', ', $p['vector']) . "]\n";
    foreach ($p['blocks'] as $name => $b) {
        printf("    %-9s weight %.4f  cosine %.4f  contribution %.4f\n", $name, $b['weight'], $b['cosine'], $b['contribution']);
    }
    printf("    Cosine Similarity = %.4f   Final Match Score = %.2f x %.4f + %.2f x %d = %.4f\n",
        $p['cosine'], $trace['config']['finalScore']['similarity'], $p['cosine'],
        $trace['config']['finalScore']['stated_program'], $p['indicator'], $p['score']);
    echo '    ' . $p['explanation'] . "\n\n";
}

// Recommendation pipeline (lib/RecommendationPipeline.php): stages 2-6.
$programs = CBFData::activePrograms($pdo);
$titles = array_column($programs, 'title', 'id');
$names = fn(?array $ids) => $ids === null ? '(model not enabled)' : ($ids ? implode(', ', array_map(fn($id) => $titles[$id], $ids)) : 'none');
$run = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
echo "Recommendation pipeline\n";
echo '  CBF match set     : ' . $names($run['cbfIds']) . "\n";
echo '  Prediction model  : ' . $names($run['predictionIds']) . ($run['modelVersion'] ? " ({$run['modelVersion']})" : '') . "\n";
echo '  Common courses    : ' . ($run['commonIds'] === null ? '-' : $names($run['commonIds'])) . "\n";
echo '  Top Matches       : ' . $names($run['finalIds']) . ($run['usedFallback'] ? '  (no common course: CBF match set shown)' : '') . "\n";
foreach ($run['preferred'] as $pref) {
    echo '  Preferred Course  : ' . $titles[$pref['id']] . ($pref['inTopMatches'] ? ' (in Top Matches)' : ' (not in Top Matches)') . "\n";
}
echo "  Status            : {$run['status']}" . ($run['reason'] ? " ({$run['reason']})" : '') . "\n";
