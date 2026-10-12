<?php
/**
 * Export every student's latest RIASEC result in the adviser's CSV layout:
 *
 *   StudentID,R,I,A,S,E,C,course
 *   2023151302,,x,,,x,x,
 *
 * "x" = one of the student's top 3 RIASEC types (the same rule the CBF uses),
 * blank = not in the top 3. The course column is left EMPTY on purpose: it is
 * the ground truth (the course the student actually takes) and must be filled
 * in from enrolment records, never from the system's own recommendation.
 *
 *   php db/export_riasec_csv.php > evaluation/student_profiles.csv
 *
 * The StudentID is the school ID. Replace it with a code (S001, S002, ...)
 * before sharing the file outside the research team.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CBFEngine.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$rows = Database::get()->query(
    'SELECT s.school_id, a.score_r, a.score_i, a.score_a, a.score_s, a.score_e, a.score_c
     FROM assessments a JOIN students s ON s.user_id = a.student_id
     WHERE a.is_latest = TRUE ORDER BY s.school_id'
)->fetchAll();

$out = fopen('php://stdout', 'w');
fputcsv($out, ['StudentID', ...CBFEngine::DIMENSIONS, 'course']);
foreach ($rows as $r) {
    $scores = [
        'R' => (int) $r['score_r'], 'I' => (int) $r['score_i'], 'A' => (int) $r['score_a'],
        'S' => (int) $r['score_s'], 'E' => (int) $r['score_e'], 'C' => (int) $r['score_c'],
    ];
    $top = CBFEngine::topRiasecLetters($scores, CBFEngine::config()['student_top_n'] ?? 3);
    fputcsv($out, [$r['school_id'], ...array_map(fn($d) => in_array($d, $top, true) ? 'x' : '', CBFEngine::DIMENSIONS), '']);
}
fwrite(STDERR, count($rows) . " students exported. Fill in the course column from enrolment records.\n");
