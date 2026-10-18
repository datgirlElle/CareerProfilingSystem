<?php

require_once __DIR__ . '/_bootstrap.php';

// POST, driven by JS from verify-email.html — not a bare GET the emailed
// link itself performs. Many email clients silently prefetch/scan links for
// safety before the user ever clicks, and a plain HTTP GET would burn a
// single-use token on that scan; those scanners don't execute page
// JavaScript, so gating the actual verification behind a fetch() call (the
// same pattern api/reset-password.php already uses from
// forgot-password.html/activate-account.html) survives that prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$token = (string) ($body['token'] ?? '');
$password = (string) ($body['password'] ?? '');

if ($token === '') {
    jsonResponse(['success' => false, 'error' => 'Missing verification token.'], 400);
}

$pdo = Database::get();
$tokenHash = hash('sha256', $token);

$stmt = $pdo->prepare(
    'SELECT t.id, t.user_id, u.role, u.approval_status FROM email_verification_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
    jsonResponse(['success' => false, 'error' => 'This verification link is invalid or has expired. Request a new one from the login page.'], 400);
}

// A student account is created without a usable password (api/register.php): whoever opens the emailed link chooses it here,
// so only the owner of the inbox can ever set the password.
$setPassword = $row['role'] === 'student';
if ($setPassword) {
    require_once __DIR__ . '/../lib/PasswordPolicy.php';
    if ($password === '') {
        jsonResponse(['success' => false, 'needsPassword' => true, 'error' => 'Choose a password to finish activating your account.'], 400);
    }
    $problems = PasswordPolicy::errors($pdo, $password);
    if ($problems) {
        jsonResponse(['success' => false, 'needsPassword' => true, 'error' => implode(' ', $problems)], 400);
    }
}

$pdo->beginTransaction();
try {
    if ($setPassword) {
        $pdo->prepare('UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $row['user_id']]);
    }
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
    $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$row['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to verify your email. Please try again.'], 500);
}

AuditLogger::log((int) $row['user_id'], $row['role'], 'verify_email', 'user', (string) $row['user_id']);

// A staff sign-up is verified but still needs an administrator's approval;
// the page words its confirmation differently for that case.
jsonResponse(['success' => true, 'awaitingApproval' => $row['role'] === 'counselor' && $row['approval_status'] === 'pending']);
