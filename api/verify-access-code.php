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

// The assessment is taken once. Anyone who has already finished it is turned away right here,
// before a code is even looked at.
$alreadyDone = Database::get()->prepare('SELECT 1 FROM assessments WHERE student_id = ? AND is_latest = TRUE AND completed_at IS NOT NULL');
$alreadyDone->execute([$user['id']]);
if ($alreadyDone->fetch()) {
    AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Tried to start the assessment again after finishing it');
    jsonResponse(['success' => false, 'alreadyTaken' => true, 'error' => 'You have already taken this assessment. Please seek the assistance of the Guidance Office.'], 403);
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

// The code must come from an Exam Schedule that matches this student's
// group (strand/section/grade/AY, NULL columns = wildcard). There is no
// global fallback code any more: a group with no exam scheduled simply
// cannot start the assessment until a counselor schedules one.
$studentStmt = $pdo->prepare('SELECT strand, section, grade_level, academic_year FROM students WHERE user_id = ?');
$studentStmt->execute([$user['id']]);
$student = $studentStmt->fetch();

$candidates = [];
if ($student && $student['academic_year']) {
    $stmt = $pdo->prepare(
        'SELECT id, access_code, exam_date, start_time, end_time FROM exam_schedules
         WHERE academic_year = ?
           AND (grade_level IS NULL OR grade_level = ?)
           AND (strand IS NULL OR strand = ?)
           AND (section IS NULL OR section = ?)'
    );
    $stmt->execute([$student['academic_year'], $student['grade_level'], $student['strand'], $student['section']]);
    $candidates = $stmt->fetchAll();
}

$matchedScheduleId = null;
if (!$candidates) {
    AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'No exam scheduled for this student\'s group');
    jsonResponse(['success' => false, 'error' => 'No assessment is scheduled for your group yet. Please ask your guidance counselor.'], 403);
}

// Looped and hash_equals-compared in PHP (never in SQL) so the comparison is
// timing-safe.
//
// A schedule whose exam has already ended is archived and its code is
// discarded: it never unlocks the assessment, even though it's the right code.
$expiredMatch = false;
$notStartedMatch = null;
foreach ($candidates as $c) {
    if (!hash_equals((string) $c['access_code'], $submitted)) {
        continue;
    }
    if (ExamSchedule::hasEnded($c['exam_date'], $c['end_time'])) {
        $expiredMatch = true;
        continue;
    }
    // The assessment is activated for the group only from the session's start time.
    if (!ExamSchedule::isOpen($c['exam_date'], $c['start_time'], $c['end_time'])) {
        $notStartedMatch = $c;
        continue;
    }
    $matchedScheduleId = (int) $c['id'];
    break;
}
if ($matchedScheduleId === null) {
    if ($notStartedMatch !== null) {
        $opens = (new DateTimeImmutable($notStartedMatch['exam_date'] . ' ' . substr($notStartedMatch['start_time'], 0, 5), new DateTimeZone('Asia/Manila')))->format('F j, Y \\a\\t g:i A');
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Assessment has not started yet');
        jsonResponse(['success' => false, 'error' => "This assessment has not started yet. It opens on $opens."], 403);
    }
    if ($expiredMatch) {
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Expired assessment access code (exam already ended)');
        jsonResponse(['success' => false, 'error' => 'This access code has expired because the assessment has already ended. Please ask your guidance counselor for the current schedule and code.'], 403);
    }
    AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
    jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
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
