<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$body = readJsonBody();
$currentPassword = (string) ($body['currentPassword'] ?? '');
$newPassword = (string) ($body['newPassword'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    jsonResponse(['success' => false, 'error' => 'Current and new password are required.'], 400);
}

// Someone at a PC left signed in could otherwise keep guessing the current password here.
if (RateLimiter::tooMany('pw-change:' . (int) $user['id'], 5, 15)) {
    jsonResponse(['success' => false, 'error' => 'Too many attempts. Please wait 15 minutes and try again.'], 429);
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    jsonResponse(['success' => false, 'error' => 'Current password is incorrect.'], 400);
}

// The same rules (and messages) as registration, password reset and staff activation.
$errors = PasswordPolicy::errors($pdo, $newPassword);
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

$hash = password_hash($newPassword, PASSWORD_BCRYPT);

// Staff accounts get a step-up email verification before the change takes
// effect, gated by the same policy toggle that used to guard login
// (Security Configuration's staff verification setting). Students aren't
// included — same scoping reasoning as before: it's not who this policy
// is meant to protect.
$isStaff = in_array($user['role'], ['admin', 'counselor'], true);
if ($isStaff && TwoFactor::isEnabled($pdo)) {
    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $emailStmt->execute([$user['id']]);
    $email = $emailStmt->fetchColumn();

    if (!empty($email)) {
        $sent = TwoFactor::issue($pdo, (int) $user['id'], $email, $user['username'], $plainCode);
        if (!$sent) {
            AuditLogger::log($user['id'], $user['role'], 'change_password_code_send_failed', 'user', (string) $user['id'], 'Could not send verification code');
        }

        // Hold the new hash pending verification — nothing is written to
        // users.password_hash until api/verify-password-change.php
        // confirms the code.
        $_SESSION['pending_password_change'] = [
            'userId' => (int) $user['id'],
            'newPasswordHash' => $hash,
        ];

        AuditLogger::log($user['id'], $user['role'], 'change_password_pending', 'user', (string) $user['id'], 'Verification code sent, awaiting confirmation');
        $response = ['success' => true, 'verificationRequired' => true];
        if (!$sent && getenv('APP_ENV') === 'local') {
            // Local dev has no real Brevo credentials — surface the code
            // directly, same convention as forgot-password.php's
            // debugResetLink, so the flow stays testable.
            $response['debugCode'] = $plainCode;
        }
        jsonResponse($response);
    }
}

$pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $user['id']]);

AuditLogger::log($user['id'], $user['role'], 'change_password', 'user', (string) $user['id']);

jsonResponse(['success' => true]);
