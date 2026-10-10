<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$pending = $_SESSION['pending_password_change'] ?? null;
if (!$pending || (int) $pending['userId'] !== (int) $user['id']) {
    jsonResponse(['success' => false, 'error' => 'No password change is currently pending. Please start over.'], 400);
}

$userStmt = $pdo->prepare('SELECT username, email FROM users WHERE id = ?');
$userStmt->execute([$user['id']]);
$userRow = $userStmt->fetch();

if (!$userRow || empty($userRow['email'])) {
    jsonResponse(['success' => false, 'error' => 'Unable to resend a code for this account.'], 400);
}

$sent = TwoFactor::issue($pdo, (int) $user['id'], $userRow['email'], $userRow['username'], $plainCode);
AuditLogger::log($user['id'], $user['role'], 'change_password_code_resent', 'user', (string) $user['id'], $sent ? 'Resent verification code' : 'Resend failed to send');

$response = ['success' => true];
if (!$sent && getenv('APP_ENV') === 'local') {
    $response['debugCode'] = $plainCode;
}
jsonResponse($response);
