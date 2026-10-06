<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
if ($username === '') {
    jsonResponse(['success' => false, 'error' => 'Username is required.'], 400);
}

$pdo = Database::get();

// Always the same generic response, whether or not the account exists or
// is already verified — same anti-enumeration reasoning as forgot-password.php.
$generic = ['success' => true, 'message' => 'If that account needs verification, a new link has been sent to its email on file.'];

// Same reasoning as forgot-password.php's limit — checked before any real
// work, and the response stays identical either way.
if (RateLimiter::tooMany('resend-verification:' . strtolower($username), 3, 60)) {
    jsonResponse($generic);
}

// Students (name in the students table) and staff who signed up themselves
// and are still pending (name in users.full_name).
$stmt = $pdo->prepare(
    "SELECT u.id, u.email, u.role, u.full_name, s.first_name_enc FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     WHERE (LOWER(u.username) = LOWER(?) OR LOWER(u.email) = LOWER(?)) AND u.email_verified_at IS NULL
       AND (u.role = 'student' OR (u.role = 'counselor' AND u.approval_status = 'pending'))"
);
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if (!$user || !$user['email']) {
    jsonResponse($generic);
}

$firstName = $user['role'] === 'student'
    ? Crypto::dec($user['first_name_enc'])
    : (string) ($user['full_name'] ?: $username);

$pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare(
    "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
)->execute([$user['id'], $tokenHash]);

$verifyLink = rtrim((string) getenv('APP_URL'), '/') . '/verify-email?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Verify your email to finish signing up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">Confirm this is your email address to activate your ProfilePath account.</p>',
    'Verify Email Address',
    $verifyLink,
    'This link expires in 48 hours.'
);
$bodyText = "Hi $firstName,\n\nConfirm this is your email address to activate your ProfilePath account:\n$verifyLink\n\nThis link expires in 48 hours.";
Mailer::send($user['email'], $firstName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

jsonResponse($generic);
