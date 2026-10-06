<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/StaffPosition.php';
require_once __DIR__ . '/../lib/StaffName.php';
require_once __DIR__ . '/../lib/StaffEmail.php';

// Public staff (guidance counselor / facilitator) sign-up. The account starts 'pending': the
// person must confirm their email, then an administrator approves them in
// Account Management before they can sign in. Admins are never created here.
// There is no username to choose: it is the part of the school email before the @, and that
// part must match the person's name (lib/StaffEmail.php), so an address can't be borrowed.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// A public form that sends email: keep one address/IP from using it to spam.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimiter::tooMany('staff-register:' . $ip, 5, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many sign-up attempts. Please try again later.'], 429);
}

$pdo = Database::get();
$body = readJsonBody();
$fullName = StaffName::normalize((string) ($body['fullName'] ?? ''));
$position = (string) ($body['position'] ?? '');
$email = trim((string) ($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');
$privacyConsent = $body['privacyConsent'] ?? false;

if ($fullName === '' || $email === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
if (!StaffPosition::isValid($position)) {
    jsonResponse(['success' => false, 'error' => 'Choose whether you are a Guidance Counselor or a Guidance Facilitator.'], 400);
}
if ($privacyConsent !== true) {
    jsonResponse(['success' => false, 'error' => 'You must agree to the Data Privacy Policy to create an account.'], 400);
}
if (mb_strlen($fullName) > 150) {
    jsonResponse(['success' => false, 'error' => 'Name must be 150 characters or fewer.'], 400);
}
if (!StaffName::isValid($fullName)) {
    jsonResponse(['success' => false, 'error' => StaffName::FORMAT_MESSAGE], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
}
// School email only (@mcl.edu.ph), and its name part must match the name given.
$emailCheck = StaffEmail::check($email, $fullName);
if (!$emailCheck['ok']) {
    jsonResponse(['success' => false, 'error' => $emailCheck['error']], 400);
}
$username = $emailCheck['username']; // e.g. jdcruz — never digits only, so it can't collide with a student's LRN

$errors = PasswordPolicy::errors($pdo, $password);
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

$existing = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)');
$existing->execute([$email, $username]);
if ($existing->fetch()) {
    jsonResponse(['success' => false, 'error' => 'An account with this email already exists.'], 409);
}

$pdo->beginTransaction();
try {
    $userStmt = $pdo->prepare(
        // Counselors and facilitators are the same system role (same access);
        // the position only decides the title shown beside their name.
        "INSERT INTO users (role, username, password_hash, email, full_name, staff_position, is_active, approval_status)
         VALUES ('counselor', ?, ?, ?, ?, ?, TRUE, 'pending') RETURNING id"
    );
    $userStmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $email, $fullName, $position]);
    $userId = (int) $userStmt->fetchColumn();

    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
    )->execute([$userId, hash('sha256', $rawToken)]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Sign-up failed. Please try again.'], 500);
}

AuditLogger::log($userId, 'counselor', 'staff_signup', 'user', (string) $userId, "Staff sign-up awaiting approval: $fullName (" . StaffPosition::label($position) . ')');

$verifyLink = rtrim((string) getenv('APP_URL'), '/') . '/verify-email?token=' . $rawToken;
$safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
$bodyHtml = EmailTemplate::render(
    'Verify your email to finish signing up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p>"
        . '<p style="margin:0;">Thanks for signing up for ProfilePath. Confirm this is your email address first. After that, an administrator will review and approve your account before you can sign in.</p>',
    'Verify Email Address',
    $verifyLink,
    'This link expires in 48 hours.'
);
$bodyText = "Hi $fullName,\n\nThanks for signing up for ProfilePath. Confirm this is your email address:\n$verifyLink\n\nAfter that, an administrator will review and approve your account before you can sign in. This link expires in 48 hours.";
$sent = Mailer::send($email, $fullName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

$response = ['success' => true, 'emailSent' => $sent];
if (!$sent) {
    // Delivery failed: hand over the link directly, as the student sign-up does.
    $response['verifyLink'] = $verifyLink;
}
jsonResponse($response);
