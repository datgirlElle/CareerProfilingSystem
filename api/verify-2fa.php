<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) {
    jsonResponse(['success' => false, 'error' => 'No sign-in is currently pending. Please log in again.'], 400);
}

$body = readJsonBody();
$code = trim((string) ($body['code'] ?? ''));

if ($code === '') {
    jsonResponse(['success' => false, 'error' => 'Enter the code from your email.'], 400);
}

$pdo = Database::get();
$userId = (int) $pending['userId'];

$result = TwoFactor::verify($pdo, $userId, $code);

$userStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch();

switch ($result) {
    case 'ok':
        Auth::login($pending['sessionData']);
        unset($_SESSION['pending_2fa']);
        AuditLogger::log($userId, $userRow['role'] ?? null, 'login', 'user', $userRow['username'] ?? null, 'Successful login (2FA verified)');
        jsonResponse(['success' => true, 'role' => $pending['sessionData']['role'], 'redirect' => $pending['redirect'], 'user' => $pending['sessionData']]);
        break;

    case 'too_many_attempts':
        unset($_SESSION['pending_2fa']);
        AuditLogger::log($userId, $userRow['role'] ?? null, 'login_2fa_failed', 'user', $userRow['username'] ?? null, 'Too many incorrect 2FA attempts');
        jsonResponse(['success' => false, 'error' => 'Too many incorrect attempts. Please log in again.'], 429);
        break;

    case 'expired':
        AuditLogger::log($userId, $userRow['role'] ?? null, 'login_2fa_failed', 'user', $userRow['username'] ?? null, '2FA code expired');
        jsonResponse(['success' => false, 'error' => 'That code has expired. Please request a new one.', 'expired' => true], 400);
        break;

    case 'none_pending':
        unset($_SESSION['pending_2fa']);
        jsonResponse(['success' => false, 'error' => 'No sign-in is currently pending. Please log in again.'], 400);
        break;

    case 'invalid':
    default:
        AuditLogger::log($userId, $userRow['role'] ?? null, 'login_2fa_failed', 'user', $userRow['username'] ?? null, 'Incorrect 2FA code');
        jsonResponse(['success' => false, 'error' => 'Incorrect code. Please try again.'], 401);
        break;
}