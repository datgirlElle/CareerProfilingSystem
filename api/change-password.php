<?php

require_once __DIR__ . '/_bootstrap.php';

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

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    jsonResponse(['success' => false, 'error' => 'Current password is incorrect.'], 400);
}

function passwordPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'password.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'minLength' => (int) ($rows['password.minLength'] ?? 8),
        'requireUpper' => ($rows['password.requireUpper'] ?? 'true') === 'true',
        'requireLower' => ($rows['password.requireLower'] ?? 'true') === 'true',
        'requireNumber' => ($rows['password.requireNumber'] ?? 'true') === 'true',
        'requireSymbol' => ($rows['password.requireSymbol'] ?? 'true') === 'true',
    ];
}

$policy = passwordPolicy($pdo);
$errors = [];
if (strlen($newPassword) < $policy['minLength']) {
    $errors[] = "Password must be at least {$policy['minLength']} characters.";
}
if ($policy['requireUpper'] && !preg_match('/[A-Z]/', $newPassword)) {
    $errors[] = 'Password must contain an uppercase letter.';
}
if ($policy['requireLower'] && !preg_match('/[a-z]/', $newPassword)) {
    $errors[] = 'Password must contain a lowercase letter.';
}
if ($policy['requireNumber'] && !preg_match('/[0-9]/', $newPassword)) {
    $errors[] = 'Password must contain a number.';
}
if ($policy['requireSymbol'] && !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
    $errors[] = 'Password must contain a symbol.';
}
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
