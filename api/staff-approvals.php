<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/StaffPosition.php';

// Account Management's staff section: guidance counselors sign up on their own
// (api/staff-register.php) and an administrator approves, rejects or
// deactivates them here. Admin only.
$user = Rbac::requireRole('admin');
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        "SELECT id, username, full_name, staff_position, email, email_verified_at, is_active, approval_status, created_at
         FROM users WHERE role = 'counselor'
         ORDER BY (approval_status = 'pending') DESC, created_at DESC"
    )->fetchAll();

    jsonResponse(['accounts' => array_map(fn($r) => [
        'id' => (int) $r['id'],
        'username' => $r['username'],
        'fullName' => $r['full_name'] ?? '',
        'position' => StaffPosition::label($r['staff_position'] ?? null),
        'email' => $r['email'],
        'emailVerified' => $r['email_verified_at'] !== null,
        'isActive' => (bool) $r['is_active'],
        'status' => $r['approval_status'],
        'createdAt' => $r['created_at'],
    ], $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$type = (string) ($body['type'] ?? '');
$id = (int) ($body['id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, username, full_name, email, email_verified_at, is_active, approval_status FROM users WHERE id = ? AND role = 'counselor'");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) {
    jsonResponse(['success' => false, 'error' => 'Account not found.'], 404);
}
$displayName = $target['full_name'] ?: $target['username'];

function notifyStaff(array $target, string $displayName, string $subject, string $heading, string $message): void
{
    if (!$target['email']) {
        return;
    }
    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $link = rtrim((string) getenv('APP_URL'), '/') . '/login';
    $html = EmailTemplate::render($heading, "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p><p style=\"margin:0;\">$message</p>", 'Go to Sign In', $link, '');
    Mailer::send($target['email'], $displayName, $subject, $html, "Hi $displayName,\n\n" . strip_tags($message) . "\n\n$link");
}

if ($type === 'approve') {
    if ($target['approval_status'] === 'approved') {
        jsonResponse(['success' => false, 'error' => 'This account is already approved.'], 409);
    }
    // Approval only follows a confirmed email, so a typo'd or someone else's
    // address can never end up with a working staff account.
    if ($target['email_verified_at'] === null) {
        jsonResponse(['success' => false, 'error' => 'This person has not verified their email yet. Ask them to open the link we emailed, then approve.'], 409);
    }
    $pdo->prepare("UPDATE users SET approval_status = 'approved', is_active = TRUE, updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], 'admin', 'approve_staff_account', 'user', (string) $id, $target['username']);
    notifyStaff($target, $displayName, 'Your ProfilePath account was approved', 'Your account is approved', 'An administrator approved your staff account. You can now sign in.');
    jsonResponse(['success' => true, 'status' => 'approved']);
}

if ($type === 'reject') {
    if ($target['approval_status'] !== 'pending') {
        jsonResponse(['success' => false, 'error' => 'Only a pending sign-up can be rejected.'], 409);
    }
    $pdo->prepare("UPDATE users SET approval_status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], 'admin', 'reject_staff_account', 'user', (string) $id, $target['username']);
    notifyStaff($target, $displayName, 'Your ProfilePath sign-up was not approved', 'Sign-up not approved', 'An administrator reviewed your staff sign-up and did not approve it. Please contact your system administrator if you think this is a mistake.');
    jsonResponse(['success' => true, 'status' => 'rejected']);
}

if ($type === 'toggleActive') {
    if ($target['approval_status'] !== 'approved') {
        jsonResponse(['success' => false, 'error' => 'Approve this account first.'], 409);
    }
    $newState = !$target['is_active'];
    // Bind an int, not a PHP bool: execute() stringifies false to '', which
    // Postgres's boolean type rejects.
    $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $id]);
    AuditLogger::log($user['id'], 'admin', $newState ? 'activate_staff_account' : 'deactivate_staff_account', 'user', (string) $id);
    jsonResponse(['success' => true, 'isActive' => $newState]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
