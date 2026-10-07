<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../lib/ResultEmail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can submit the Career Electives Worksheet.'], 403);
}

$body = readJsonBody();
$programId = (int) ($body['programId'] ?? 0);
$electives = $body['electives'] ?? [];

if ($programId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Please select your Preferred Course.'], 400);
}
if (!is_array($electives) || count($electives) === 0) {
    jsonResponse(['success' => false, 'error' => 'Please select at least one elective.'], 400);
}
$electives = array_values(array_map('strval', $electives));

$pdo = Database::get();
$studentId = (int) $user['id'];

$programCheck = $pdo->prepare("SELECT id FROM programs WHERE id = ? AND status = 'Active'");
$programCheck->execute([$programId]);
if (!$programCheck->fetch()) {
    jsonResponse(['success' => false, 'error' => 'Selected program is not available.'], 400);
}

$assessmentStmt = $pdo->prepare(
    'SELECT id, attempt_number, score_r, score_i, score_a, score_s, score_e, score_c, top_types
     FROM assessments WHERE student_id = ? AND is_latest = TRUE'
);
$assessmentStmt->execute([$studentId]);
$assessment = $assessmentStmt->fetch();
if (!$assessment) {
    jsonResponse(['success' => false, 'error' => 'Complete the RIASEC assessment before submitting the worksheet.'], 400);
}

// Student profile = strand + latest RIASEC scores + the electives being submitted now.
$profile = CBFData::studentProfile($pdo, $studentId, $electives);

$pdo->beginTransaction();
try {
    $attemptNumber = (int) $assessment['attempt_number'];

    $worksheetInsert = $pdo->prepare(
        'INSERT INTO worksheets (student_id, attempt_number, stated_program_id, electives, top_types)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (student_id, attempt_number) DO UPDATE
         SET stated_program_id = EXCLUDED.stated_program_id, electives = EXCLUDED.electives,
             top_types = EXCLUDED.top_types, submitted_at = NOW()
         RETURNING id'
    );
    $worksheetInsert->execute([
        $studentId, $attemptNumber, $programId, CBFData::textArrayLiteral($electives), $assessment['top_types'],
    ]);
    $worksheetId = (int) $worksheetInsert->fetchColumn();

    $saved = CBFData::saveRecommendation($pdo, $studentId, $profile, $programId, (int) $assessment['id'], $worksheetId);
    $recommendationId = $saved['recommendationId'];
    $topScore = $saved['topScore'];

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to save worksheet. Please try again.'], 500);
}

AuditLogger::log($studentId, 'student', 'submit_worksheet', 'worksheet', (string) $worksheetId, "Top match score: $topScore");

// Automatic result email: Best RIASEC Match and Alternative Courses, or (mismatch) only a referral to the Guidance Office.
$emailSent = ResultEmail::send($pdo, $studentId, $saved['status'], $saved['bestRiasecMatchTitles'], $saved['alternativeTitles']);

jsonResponse(['success' => true, 'worksheetId' => $worksheetId, 'recommendationId' => $recommendationId, 'emailSent' => $emailSent]);
