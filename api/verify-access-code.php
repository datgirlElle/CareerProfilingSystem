<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/ExamSchedule.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students take the RIASEC assessment.'], 403);
}

// 3 attempts per 5 minutes, keyed per-student — matches the scale of the
// login lockout policy. A genuine student mistyping a code a couple times
// is unaffected; a script grinding through the ~16.7 million possible
// 6-character codes is not.
if (RateLimiter::tooMany('access-code:' . $user['id'], 3, 5)) {
    AuditLogger::log($user['id'], 'student', 'access_code_throttled', 'assessment', null, 'Too many attempts, temporarily blocked');
    jsonResponse(['success' => false, 'error' => 'Too many incorrect attempts. Please wait a few minutes and try again.'], 429);
}

$body = readJsonBody();
$submitted = strtoupper(trim((string) ($body['code'] ?? '')));

if ($submitted === '') {
    jsonResponse(['success' => false, 'error' => 'Enter the assessment access code.'], 400);
}

$pdo = Database::get();

// Prefer the code from the Exam Schedule that actually matches this
// student's group (strand/section/grade/AY, NULL columns = wildcard) over
// the old single blanket code — this is what makes Exam Scheduling a real
// gate instead of a purely informational panel. Falls back to the global
// security_policies code only when this student's group has no exam
// schedule created for it at all, so access doesn't hard-lock the moment
// this feature ships into a database with no schedules yet (as of this
// change, exam_schedules is empty on the live DB).
$studentStmt = $pdo->prepare('SELECT strand, section, grade_level, academic_year FROM students WHERE user_id = ?');
$studentStmt->execute([$user['id']]);
$student = $studentStmt->fetch();

$candidates = [];
if ($student && $student['academic_year']) {
    $stmt = $pdo->prepare(
        'SELECT id, access_code, exam_date, end_time FROM exam_schedules
         WHERE academic_year = ?
           AND (grade_level IS NULL OR grade_level = ?)
           AND (strand IS NULL OR strand = ?)
           AND (section IS NULL OR section = ?)'
    );
    $stmt->execute([$student['academic_year'], $student['grade_level'], $student['strand'], $student['section']]);
    $candidates = $stmt->fetchAll();
}

$matchedScheduleId = null;
if ($candidates) {
    // This student's group has at least one scheduled exam — the code
    // must match one of those now, not the old blanket code. Looped and
    // hash_equals-compared in PHP (never in SQL) for the same
    // timing-safe-comparison reason as the global code below.
    //
    // A schedule whose exam has already ended is archived and its code is
    // discarded: it never unlocks the assessment, even though it's the right
    // code. (Past schedules do NOT fall back to the global code either — that
    // one is only for groups that have no exam scheduled at all.)
    $expiredMatch = false;
    foreach ($candidates as $c) {
        if (!hash_equals((string) $c['access_code'], $submitted)) {
            continue;
        }
        if (ExamSchedule::hasEnded($c['exam_date'], $c['end_time'])) {
            $expiredMatch = true;
            continue;
        }
        $matchedScheduleId = (int) $c['id'];
        break;
    }
    if ($matchedScheduleId === null) {
        if ($expiredMatch) {
            AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Expired assessment access code (exam already ended)');
            jsonResponse(['success' => false, 'error' => 'This access code has expired because the exam has already ended. Please ask your guidance counselor for the current schedule and code.'], 403);
        }
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
        jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
    }
} else {
    // No exam schedule exists yet for this student's group — fall back to
    // the single global code, same behavior as before this change.
    $actual = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'assessment.accessCode'")->fetchColumn();
    if ($actual === '' || !hash_equals($actual, $submitted)) {
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
        jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
    }
}

$_SESSION['assessmentUnlocked'] = true;
AuditLogger::log(
    $user['id'],
    'student',
    'access_code_verified',
    'assessment',
    null,
    $matchedScheduleId !== null ? "Matched exam schedule #$matchedScheduleId" : null
);
jsonResponse(['success' => true]);
