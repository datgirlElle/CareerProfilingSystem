<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/Lrn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
// Students log in with their LRN, which IS their username (see
// api/register.php) — so a single username lookup covers every role,
// students and admin/counselor staff accounts alike.
$identifier = trim((string) ($body['schoolId'] ?? $body['identifier'] ?? ''));
if ($identifier === '') {
    jsonResponse(['success' => false, 'error' => 'Username or LRN is required.'], 400);
}

// Format check only — this rejects obvious garbage (injection-style
// strings, symbols, spaces) before it reaches the database, but it does
// NOT reveal whether an account actually exists. A plausible-looking but
// nonexistent email/LRN/username still falls through to the same generic
// response below, same as always — anti-enumeration is preserved.
$looksValid = filter_var($identifier, FILTER_VALIDATE_EMAIL)
    || Lrn::isValid($identifier)                          // student LRN
    || preg_match('/^[a-zA-Z0-9_.]{3,32}$/', $identifier); // staff username
if (!$looksValid) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email, LRN, or username.'], 400);
}

$pdo = Database::get();

// Always respond with the same generic success message, whether or not the
// account exists, so this endpoint can't be used to enumerate accounts.
$generic = ['success' => true, 'message' => 'If that account exists, a reset link has been sent to its email on file.'];

// Rate limit BEFORE doing anything else, keyed to the identifier they
// submitted. On hit, still return the same generic response — never a
// different message or status code — so someone probing this endpoint
// can't tell "no such account" apart from "rate limited" apart from
// "email sent". 3 requests/hour is enough for someone who genuinely
// forgot their password to retry a typo, not enough to flood an inbox or
// burn through the Brevo daily quota.
if (RateLimiter::tooMany('forgot-password:' . strtolower($identifier), 3, 60)) {
    jsonResponse($generic);
}

// A separate, higher-ceiling limit keyed by IP instead of by identifier —
// the per-identifier limit above only catches someone retrying the SAME
// number/email; it does nothing to stop someone rapidly cycling through
// many DIFFERENT numbers, since each one starts fresh at zero. This catches
// that case. The ceiling is higher (20 vs 3) since one IP can legitimately
// represent many different real people on a shared school network.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimiter::tooMany('forgot-password-ip:' . $clientIp, 20, 60)) {
    jsonResponse($generic);
}

$stmt = $pdo->prepare('SELECT id, role, username, email FROM users WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) AND is_active = TRUE');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user) {
    jsonResponse($generic);
}

if ($user['role'] === 'student') {
    // No real email was collected at registration for older accounts — fall
    // back to the standard institutional address format the rest of the app
    // already assumes (see the masked-email display on the change-password flow).
    $email = $user['email'] ?: ($user['username'] . '@mymail.mapua.edu.ph');
    $nameStmt = $pdo->prepare('SELECT first_name_enc FROM students WHERE user_id = ?');
    $nameStmt->execute([$user['id']]);
    $nameRow = $nameStmt->fetch();
    $firstName = $nameRow ? Crypto::dec($nameRow['first_name_enc']) : $user['username'];
} else {
    // Staff accounts always have an email on file (required when the admin
    // creates the account) — if somehow missing, there's nowhere to send a
    // link, so fall through to the same generic non-committal response.
    if (!$user['email']) {
        jsonResponse($generic);
    }
    $email = $user['email'];
    $firstName = $user['username'];
}

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

$insert = $pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL \'30 minutes\')'
);
$insert->execute([$user['id'], $tokenHash]);

$resetLink = rtrim((string) getenv('APP_URL'), '/') . '/forgot-password?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Reset your password',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">We received a request to reset your ProfilePath password. Click the button below to choose a new one.</p>',
    'Reset Password',
    $resetLink,
    'This link expires in 30 minutes.'
);
$bodyText = "Hi $firstName,\n\nWe received a request to reset your ProfilePath password. This link expires in 30 minutes:\n$resetLink\n\nIf you didn't request this, you can ignore this email.";

$sent = Mailer::send($email, $firstName, 'Reset your ProfilePath password', $bodyHtml, $bodyText);

AuditLogger::log($user['id'], $user['role'], 'request_password_reset', 'user', (string) $user['id']);

$response = $generic;
if (!$sent && getenv('APP_ENV') === 'local') {
    // Local dev has no real Brevo credentials — surface the link directly so the flow stays testable.
    $response['debugResetLink'] = $resetLink;
}
jsonResponse($response);
