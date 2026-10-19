<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AssessmentProgress.php';

// The RIASEC page saves the student's answers here as they go (lib/AssessmentProgress.php), so a
// sign-out, a crash or a different PC loses nothing. Each save is also a request, which keeps the
// student signed in while they work. A student only ever reads and writes their own row.

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Students only.'], 403);
}
$pdo = Database::get();
$studentId = (int) $user['id'];

// Before the database update has run: the page keeps answers in the browser, as before.
if (!AssessmentProgress::available($pdo)) {
    jsonResponse(['success' => true, 'available' => false]);
}

$access = AssessmentProgress::access($pdo, $studentId);
if (!$access['unlocked'] && empty($_SESSION['assessmentUnlocked'])) {
    jsonResponse(['success' => false, 'unlocked' => false, 'error' => 'Enter the assessment access code first.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $saved = AssessmentProgress::load($pdo, $studentId);
    jsonResponse([
        'success' => true,
        'available' => true,
        'unlocked' => true,
        'retake' => $access['retake'],
        'endsAt' => $access['endsAt'],
        'submitUntil' => $access['submitUntil'],
        'serverNow' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
        'set' => $saved['set'] ?? null,
        'answers' => $saved['answers'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Unlocked only by the session flag (from before this update): nothing to save on the server.
    if (!$access['unlocked']) {
        jsonResponse(['success' => true, 'stored' => false]);
    }
    $body = readJsonBody();
    $set = AssessmentProgress::activeSet($pdo);
    if (($body['set'] ?? null) !== $set) {
        jsonResponse(['success' => false, 'error' => 'The questions were updated while you were answering. Please start the assessment again.'], 409);
    }
    $answers = AssessmentProgress::clean($body['answers'] ?? null, $set === '' ? 0 : substr_count($set, ',') + 1);
    if ($answers === null) {
        jsonResponse(['success' => false, 'error' => 'Each answer must be a whole number from 1 to 5.'], 400);
    }
    AssessmentProgress::save($pdo, $studentId, $set, $answers);
    jsonResponse(['success' => true, 'stored' => true]);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
