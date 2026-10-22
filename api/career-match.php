<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CareerMatcher.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['error' => 'Only students can use the Career Worksheet.'], 403);
}

// Live preview for the worksheet: which MMCL program would the career the
// student is typing be linked to? Same logic worksheet-submit.php re-runs
// server-side on submit, so the page never decides the link itself.
$typed = CareerMatcher::sanitize((string) ($_GET['q'] ?? ''));
if ($typed === null) {
    jsonResponse(['career' => null, 'matched' => false]);
}

// A failure here must not leave the worksheet stuck: log the cause and report
// "no match" so the student can still pick from every elective and submit.
try {
    $pdo = Database::get();
    $programs = CareerMatcher::loadPrograms($pdo);
    $programId = CareerMatcher::resolve($typed, $programs, CareerMatcher::latestScores($pdo, (int) $user['id']));
} catch (Throwable $e) {
    error_log('[career-match] failed: ' . $e->getMessage());
    jsonResponse(['career' => $typed, 'matched' => false]);
}

if ($programId === null) {
    jsonResponse(['career' => $typed, 'matched' => false]);
}

foreach ($programs as $p) {
    if ($p['id'] === $programId) {
        jsonResponse([
            'career' => $typed,
            'matched' => true,
            'program' => [
                'id' => $p['id'],
                'title' => $p['title'],
                'collegeCode' => $p['collegeCode'],
                'collegeName' => $p['collegeName'],
            ],
        ]);
    }
}
jsonResponse(['career' => $typed, 'matched' => false]);
