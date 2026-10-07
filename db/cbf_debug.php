<?php
/**
 * Command-line view of the CBF calculation for one student: student vector, every
 * course's vector, dot product, magnitudes and cosine similarity, and the sources.
 *
 *   php db/cbf_debug.php <school_id>            readable report
 *   php db/cbf_debug.php <school_id> --csv      all courses as CSV (paste into Excel/Word)
 *   php db/cbf_debug.php <school_id> --json     everything as JSON
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

$programs = CBFData::activePrograms($pdo);
$titles = array_column($programs, 'title', 'id');
$named = fn(array $ids) => $ids ? implode(', ', array_map(fn($id) => $titles[$id], $ids)) : 'none';
$run = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
$cbf = $run['cbf'];

if ($format === '--json') {
    echo json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}
if ($cbf['status'] !== 'available') {
    fwrite(STDERR, "CBF not computed: {$cbf['reason']}\n");
    exit(1);
}

if ($format === '--csv') {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['Course', 'RIASEC', 'Course Vector [R,I,A,S,E,C]', 'Dot Product', '||S||', '||C||', 'Cosine Similarity', 'CBF Candidate']);
    foreach ($cbf['results'] as $r) {
        fputcsv($out, [$r['title'], $r['hollandCode'], '[' . implode(', ', $r['courseVector']) . ']', $r['dotProduct'],
            $cbf['studentMagnitude'], $r['courseMagnitude'], $r['cosine'], in_array($r['id'], $cbf['candidateIds'], true) ? 'yes' : '']);
    }
    exit;
}

echo "CBF calculation for $schoolId   cos(S, C) = (S · C) / (||S|| x ||C||)\n\n";
echo 'Student RIASEC totals : ' . implode('  ', array_map(fn($d) => "$d={$cbf['studentScores'][$d]}", CBFEngine::DIMENSIONS)) . "\n";
echo 'Student vector S      : [' . implode(', ', $cbf['studentVector']) . "]  (mean item score = total / 10, order R,I,A,S,E,C)\n";
echo "||S||                 : {$cbf['studentMagnitude']}\n\n";
printf("%-62s %-6s %-20s %8s %7s %8s\n", 'Course', 'RIASEC', 'Course vector C', 'S·C', '||C||', 'Cosine');
foreach ($cbf['results'] as $r) {
    printf("%-62s %-6s %-20s %8s %7s %8.4f%s\n", substr($r['title'], 0, 62), $r['hollandCode'], '[' . implode(',', $r['courseVector']) . ']',
        $r['dotProduct'], $r['courseMagnitude'], $r['cosine'], in_array($r['id'], $cbf['candidateIds'], true) ? '  <- CBF candidate' : '');
}
foreach ($cbf['excluded'] as $x) {
    echo "EXCLUDED: {$x['title']} ({$x['hollandCode']}): {$x['reason']}\n";
}
echo "\nRecommendation sources\n";
echo '  CBF candidates   : ' . $named($cbf['candidateIds']) . "\n";
echo '  Worksheet        : ' . ($run['worksheet']['status'] === 'available' ? $named($run['worksheet']['courseIds']) : 'no worksheet result') . "\n";
echo "  Prediction model : {$run['prediction']['reason']}\n";
echo '  Best Match       : ' . $named($run['bestMatchIds']) . "\n";
echo '  Alternatives     : ' . $named($run['alternativeIds']) . "\n";
echo "  Status           : {$run['status']}" . ($run['reason'] ? " ({$run['reason']})" : '') . "  (prediction model pending: not a final three-source result)\n";
