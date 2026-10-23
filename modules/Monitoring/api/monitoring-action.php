<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

$body = readJsonBody();
$flagId = (int) ($body['flagId'] ?? 0);
$action = (string) ($body['action'] ?? '');
$note = trim((string) ($body['note'] ?? ''));

if ($flagId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing flagId.'], 400);
}
if (!in_array($action, ['approve', 'escalate', 'dismiss'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);
}
if (mb_strlen($note) > 500) {
    jsonResponse(['success' => false, 'error' => 'The note can be at most 500 characters.'], 400);
}

$flagStmt = $pdo->prepare('SELECT id, student_id, status FROM monitoring_flags WHERE id = ?');
$flagStmt->execute([$flagId]);
$flag = $flagStmt->fetch();
if (!$flag) {
    jsonResponse(['success' => false, 'error' => 'Flag not found.'], 404);
}

// Only open flags can be handled: a waiting flag takes any action, an escalated one can still be
// approved or dismissed. A flag someone already decided stays as it is.
$allowedFrom = $action === 'escalate' ? ['pending'] : ['pending', 'escalated'];
if (!in_array($flag['status'], $allowedFrom, true)) {
    jsonResponse(['success' => false, 'error' => 'This flag was already handled. Refresh the list to see its current status.'], 409);
}

$statusMap = ['approve' => 'approved', 'escalate' => 'escalated', 'dismiss' => 'dismissed'];
$newStatus = $statusMap[$action];
$resolvesFlag = $action !== 'escalate'; // escalating moves it to the counseling queue, it isn't "resolved" yet
$counselorId = ($action === 'escalate' && $user['role'] === 'counselor') ? $user['id'] : null;

// "AND status = ?" makes two staff clicking at the same time safe: only the first one changes it.
// The note is not written to the plain-text note column; it goes only into the encrypted audit detail.
$update = $pdo->prepare(
    'UPDATE monitoring_flags
     SET status = ?, counselor_id = COALESCE(?, counselor_id), resolved_at = ?
     WHERE id = ? AND status = ?'
);
$update->execute([
    $newStatus,
    $counselorId,
    $resolvesFlag ? date('Y-m-d H:i:sO') : null,
    $flagId,
    $flag['status'],
]);
if ($update->rowCount() === 0) {
    jsonResponse(['success' => false, 'error' => 'This flag was already handled. Refresh the list to see its current status.'], 409);
}

$detail = "Student user #{$flag['student_id']}" . ($note !== '' ? " | Note: {$note}" : '');
AuditLogger::log($user['id'], $user['role'], 'monitoring_' . $action, 'monitoring_flag', (string) $flagId, $detail);

jsonResponse(['success' => true, 'status' => $newStatus]);
