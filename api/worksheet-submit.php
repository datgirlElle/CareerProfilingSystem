<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/Careers.php';
require_once __DIR__ . '/../lib/CareerMatcher.php';
require_once __DIR__ . '/../lib/ResultEmail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can submit the Career Worksheet.'], 403);
}

$body = readJsonBody();
$career = CareerMatcher::sanitize((string) ($body['career'] ?? ''));
$electives = $body['electives'] ?? [];

if ($career === null) {
    jsonResponse(['success' => false, 'error' => 'Please type the career you want to take (letters and numbers only, up to 80 characters).'], 400);
}
if (!is_array($electives) || count($electives) === 0) {
    jsonResponse(['success' => false, 'error' => 'Please select at least one elective.'], 400);
}
$electives = array_values(array_map('strval', $electives));

$pdo = Database::get();
$studentId = (int) $user['id'];

$assessmentStmt = $pdo->prepare(
    'SELECT id, attempt_number, score_r, score_i, score_a, score_s, score_e, score_c, top_types
     FROM assessments WHERE student_id = ? AND is_latest = TRUE'
);
$assessmentStmt->execute([$studentId]);
$assessment = $assessmentStmt->fetch();
if (!$assessment) {
    jsonResponse(['success' => false, 'error' => 'Complete the RIASEC assessment before submitting the worksheet.'], 400);
}

$scores = [
    'R' => (int) $assessment['score_r'], 'I' => (int) $assessment['score_i'], 'A' => (int) $assessment['score_a'],
    'S' => (int) $assessment['score_s'], 'E' => (int) $assessment['score_e'], 'C' => (int) $assessment['score_c'],
];

$allPrograms = CareerMatcher::loadPrograms($pdo);
$activePrograms = array_map(fn($p) => ['id' => $p['id'], 'hollandCode' => $p['hollandCode']], $allPrograms);

// Link the typed career to a program (null if nothing is close enough: then
// there is no worksheet bonus and the recommendation is interest-fit only).
$programId = CareerMatcher::resolve($career, $allPrograms, $scores);

$recommendation = CBFEngine::recommend($scores, $activePrograms, $programId);
$topProgramId = (int) $recommendation['top3'][0]['id'];
$topScore = (float) $recommendation['top3'][0]['score'];

$scoresForStorage = array_map(fn($s) => [
    'programId' => $s['id'], 'cosine' => $s['cosine'], 'indicator' => $s['indicator'], 'score' => $s['score'],
], $recommendation['all']);

$pdo->beginTransaction();
try {
    $attemptNumber = (int) $assessment['attempt_number'];

    $worksheetInsert = $pdo->prepare(
        'INSERT INTO worksheets (student_id, attempt_number, stated_program_id, stated_career, electives, top_types)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (student_id, attempt_number) DO UPDATE
         SET stated_program_id = EXCLUDED.stated_program_id, stated_career = EXCLUDED.stated_career,
             electives = EXCLUDED.electives, top_types = EXCLUDED.top_types, submitted_at = NOW()
         RETURNING id'
    );
    $worksheetInsert->execute([
        $studentId, $attemptNumber, $programId, $career, Careers::toLiteral($electives), $assessment['top_types'],
    ]);
    $worksheetId = (int) $worksheetInsert->fetchColumn();

    $recInsert = $pdo->prepare(
        'INSERT INTO recommendations (student_id, stated_program_id, scores, top_program_id, top_score, source_assessment_id, source_worksheet_id)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $recInsert->execute([
        $studentId, $programId, json_encode($scoresForStorage), $topProgramId, $topScore,
        (int) $assessment['id'], $worksheetId,
    ]);
    $recommendationId = (int) $recInsert->fetchColumn();

    $policyRows = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
    $threshold = $policyRows !== false ? (float) $policyRows : 0.50;

    if ($topScore < $threshold) {
        $pendingCheck = $pdo->prepare(
            "SELECT id FROM monitoring_flags WHERE student_id = ? AND reason = 'low_confidence' AND status = 'pending'"
        );
        $pendingCheck->execute([$studentId]);
        if (!$pendingCheck->fetch()) {
            $flagInsert = $pdo->prepare(
                "INSERT INTO monitoring_flags (student_id, recommendation_id, reason, status)
                 VALUES (?, ?, 'low_confidence', 'pending')"
            );
            $flagInsert->execute([$studentId, $recommendationId]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to save worksheet. Please try again.'], 500);
}

AuditLogger::log($studentId, 'student', 'submit_worksheet', 'worksheet', (string) $worksheetId, "Top match score: $topScore");

// Automatic result email, no staff action: a thank-you with the Top Matches, or a request to see the
// Guidance Office when the program they chose isn't among them (lib/Mismatch.php).
$titlesById = array_column($allPrograms, 'title', 'id');
$topTitles = array_values(array_filter(array_map(fn($p) => $titlesById[$p['id']] ?? null, $recommendation['top3'])));
ResultEmail::send($pdo, $studentId, Mismatch::isMismatch($programId, $scoresForStorage), $topTitles);

jsonResponse(['success' => true, 'worksheetId' => $worksheetId, 'recommendationId' => $recommendationId]);
