<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/Sections.php';
require_once __DIR__ . '/../lib/StudentNumber.php';
require_once __DIR__ . '/../lib/AcademicYear.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

// A student registers with their Student Number, student email and a password. Who they are
// (name, strand, section) is not typed in: it comes from the class roster the Guidance Office
// uploaded, so only students on the roster can register and the Student Number is the same
// everywhere (roster, registration, login, records).
//
// Only Mapúa MCL's own student email domain may register, and when the roster has an email for
// this Student Number the registration must use that one, so nobody can claim another student's
// number with their own address. Real ownership of the address is then confirmed by the
// verification email sent below (see api/verify-email.php).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// A public form that looks numbers up on the roster: keep one address from guessing at it.
if (RateLimiter::tooMany('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
}

$pdo = Database::get();

$body = readJsonBody();
$schoolId = trim((string) ($body['schoolId'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');
$privacyConsent = $body['privacyConsent'] ?? false;

if ($schoolId === '' || $email === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
// Never trust the client-side checkbox's "required" attribute alone —
// a request built by hand (or a modified page) could omit it entirely.
if ($privacyConsent !== true) {
    jsonResponse(['success' => false, 'error' => 'You must agree to the Data Privacy Policy to create an account.'], 400);
}
if (!StudentNumber::isValid($schoolId)) {
    jsonResponse(['success' => false, 'error' => StudentNumber::INVALID_MESSAGE], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
}
if (!str_ends_with($email, '@' . StudentNumber::EMAIL_DOMAIN)) {
    jsonResponse(['success' => false, 'error' => 'Please register using your official @' . StudentNumber::EMAIL_DOMAIN . ' student email address.'], 400);
}

$errors = PasswordPolicy::errors($pdo, $password);
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

// Must be on this academic year's class roster.
$currentAy = AcademicYear::current();
$rosterStmt = $pdo->prepare('SELECT name_enc, strand, section, email FROM assessment_roster WHERE academic_year = ? AND school_id = ?');
$rosterStmt->execute([$currentAy, $schoolId]);
$roster = $rosterStmt->fetch();
if (!$roster) {
    jsonResponse(['success' => false, 'error' => 'This Student Number is not on the class roster for Academic Year ' . $currentAy . '. Please check it, or ask the Guidance Office to add you.'], 404);
}
if ($roster['email'] !== null && strtolower($roster['email']) !== $email) {
    [$local, $domain] = explode('@', $roster['email'], 2);
    $hint = substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 2)) . substr($local, -1) . '@' . $domain;
    jsonResponse(['success' => false, 'error' => "Register with the student email the Guidance Office has on file for this Student Number ($hint)."], 400);
}

$gradeLevel = Sections::gradeLevel($roster['section']);
if ($gradeLevel === null) {
    jsonResponse(['success' => false, 'error' => 'The section on the roster for this Student Number is not valid. Please ask the Guidance Office to fix it.'], 409);
}
// The roster stores "LASTNAME, FIRSTNAME MIDDLE".
$fullName = (string) Crypto::dec($roster['name_enc']);
[$lastName, $firstName] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
if ($lastName === '' || $firstName === '') {
    jsonResponse(['success' => false, 'error' => 'The name on the roster for this Student Number is incomplete. Please ask the Guidance Office to fix it.'], 409);
}
$strand = $roster['strand'];
$section = $roster['section'];

// Checked against `users` (the table the UNIQUE constraint actually lives
// on), not just `students` — a users row can exist without a matching
// students row (e.g. a profile deleted separately from its account), and
// checking students alone let that case fall through to the INSERT below,
// where it failed on the UNIQUE(username) constraint and surfaced only as
// a generic "Registration failed" 500.
$existing = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
$existing->execute([$schoolId, $email]);
if ($existing->fetch()) {
    jsonResponse(['success' => false, 'error' => 'An account with this Student Number or email already exists.'], 409);
}

$pdo->beginTransaction();
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare('INSERT INTO users (role, username, password_hash, email, is_active) VALUES (?, ?, ?, ?, TRUE) RETURNING id');
    $userStmt->execute(['student', $schoolId, $hash, $email]);
    $userId = (int) $userStmt->fetchColumn();

    $studentStmt = $pdo->prepare(
        'INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $studentStmt->execute([$userId, $schoolId, Crypto::enc($firstName), Crypto::enc($lastName), $strand, $gradeLevel, $section, $currentAy ?: null]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $pdo->prepare(
        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
    )->execute([$userId, $tokenHash]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

AuditLogger::log($userId, 'student', 'register', 'user', (string) $userId, "New student account: $schoolId");

$verifyLink = rtrim((string) getenv('APP_URL'), '/') . '/verify-email?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Verify your email to finish signing up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">Thanks for creating a ProfilePath account. Confirm this is your email address to activate it — you won\'t be able to sign in until you do.</p>',
    'Verify Email Address',
    $verifyLink,
    'This link expires in 48 hours.'
);
$bodyText = "Hi $firstName,\n\nThanks for creating a ProfilePath account. Confirm this is your email address to activate it:\n$verifyLink\n\nThis link expires in 48 hours.";
$sent = Mailer::send($email, $firstName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

$response = ['success' => true, 'emailSent' => $sent, 'name' => "$firstName $lastName", 'strand' => $strand, 'section' => $section];
if (!$sent) {
    // The student just proved they control this form submission (not the
    // inbox) — if delivery fails there's no other way for them to get
    // moving, so hand over the link directly (api/staff-register.php does
    // the same for staff sign-ups).
    $response['verifyLink'] = $verifyLink;
}
jsonResponse($response);
