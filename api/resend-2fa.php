<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) {
    jsonResponse(['success' => false, 'error' => 'No sign-in is currently pending. Please log in again.'], 400);
}

$pdo = Database::get();
$userId = (int) $pending['userId'];

$userStmt = $pdo->prepare('SELECT username, role, email FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user || empty($user['email'])) {
    jsonResponse(['success' => false, 'error' => 'Unable to resend a code for this account.'], 400);
}

$sent = TwoFactor::issue($pdo, $userId, $user['email'], $pending['sessionData']['firstName'] ?? $user['username'], $plainCode);
AuditLogger::log($userId, $user['role'], 'login_2fa_resent', 'user', $user['username'], $sent ? 'Resent 2FA code' : 'Resend failed to send');

$response = ['success' => true];
if (!$sent && getenv('APP_ENV') === 'local') {
    $response['debugCode'] = $plainCode;
}
jsonResponse($response);