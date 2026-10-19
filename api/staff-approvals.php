<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/LoginLock.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/StaffPosition.php';
require_once __DIR__ . '/../lib/StaffSetupCode.php';
require_once __DIR__ . '/../lib/SessionEnd.php';

// Account Management's staff section: guidance counselors sign up on their own
// (api/staff-register.php) and an administrator approves, rejects or
// deactivates them here. The administrator always can; guidance staff can when the administrator gave them "Staff Accounts"
// access in Edit Roles (View Only to see the list, Full to act). Sections are assigned in api/staff-assignments.php (admin only).
$user = Rbac::requireStaffAccounts($_SERVER['REQUEST_METHOD'] === 'GET' ? 'limited' : 'full');
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        "SELECT id, username, full_name, staff_position, email, email_verified_at, is_active, approval_status, created_at,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS locked
         FROM users WHERE role = 'counselor'
         ORDER BY (approval_status = 'pending') DESC, created_at DESC"
    )->fetchAll();

    jsonResponse(['accounts' => array_map(fn($r) => [
        'id' => (int) $r['id'],
        // Accounts made before staff gave their name fall back to their old login name; auto-generated ones show nothing.
        'fullName' => trim((string) $r['full_name']) !== '' ? $r['full_name'] : (str_starts_with($r['username'], 'staff-') ? '' : $r['username']),
        'position' => StaffPosition::label($r['staff_position'] ?? null),
        'email' => $r['email'],
        'emailVerified' => $r['email_verified_at'] !== null,
        'activated' => $r['email_verified_at'] !== null,
        'username' => $r['username'],
        'isActive' => (bool) $r['is_active'],
        'locked' => (bool) $r['locked'],   // locked by wrong passwords: an administrator can Unlock
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

$stmt = $pdo->prepare("SELECT id, username, full_name, staff_position, email, email_verified_at, is_active, approval_status FROM users WHERE id = ? AND role = 'counselor'");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) {
    jsonResponse(['success' => false, 'error' => 'Account not found.'], 404);
}
$displayName = $target['full_name'] ?: $target['username'];
if ($id === (int) $user['id'] && $type === 'toggleActive') {
    jsonResponse(['success' => false, 'error' => 'You cannot deactivate your own account.'], 409);
}

function notifyStaff(array $target, string $displayName, string $subject, string $heading, string $message): void
{
    if (!$target['email']) {
        return;
    }
    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $link = rtrim((string) getenv('APP_URL'), '/') . '/staff-login';
    $html = EmailTemplate::render($heading, "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p><p style=\"margin:0;\">$message</p>", 'Go to Sign In', $link, '');
    Mailer::send($target['email'], $displayName, $subject, $html, "Hi $displayName,\n\n" . strip_tags($message) . "\n\n$link");
}

// Emails the temporary access code. The approver never sees a password or the code itself.
function sendSetupCode(PDO $pdo, array $target, string $displayName): bool
{
    if (!$target['email']) {
        return false;
    }
    $code = StaffSetupCode::issue($pdo, (int) $target['id']);
    [$html, $text] = StaffSetupCode::emailBody($displayName, (string) $target['username'], $code);
    return Mailer::send($target['email'], $displayName, 'Your ProfilePath account is approved', $html, $text);
}

if ($type === 'approve') {
    if ($target['approval_status'] === 'approved') {
        jsonResponse(['success' => false, 'error' => 'This account is already approved.'], 409);
    }
    if (!$target['email']) {
        jsonResponse(['success' => false, 'error' => 'This account has no email address to send the access code to.'], 409);
    }
    // Approved, but the person can't sign in until they enter the emailed code and create a
    // password (api/staff-activate.php). Entering the code is what proves the inbox is theirs.
    $pdo->prepare("UPDATE users SET approval_status = 'approved', is_active = TRUE, email_verified_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], $user['role'], 'approve_staff_account', 'user', (string) $id, $displayName);
    $sent = sendSetupCode($pdo, $target, $displayName);
    jsonResponse(['success' => true, 'status' => 'approved', 'emailSent' => $sent]);
}

if ($type === 'resendCode') {
    if ($target['approval_status'] !== 'approved' || $target['email_verified_at'] !== null) {
        jsonResponse(['success' => false, 'error' => 'A new code can only be sent to an approved account that has not been activated yet.'], 409);
    }
    $sent = sendSetupCode($pdo, $target, $displayName);
    AuditLogger::log($user['id'], $user['role'], 'resend_staff_setup_code', 'user', (string) $id, $displayName);
    jsonResponse(['success' => true, 'emailSent' => $sent]);
}

if ($type === 'reject') {
    if ($target['approval_status'] !== 'pending') {
        jsonResponse(['success' => false, 'error' => 'Only a pending sign-up can be rejected.'], 409);
    }
    $pdo->prepare("UPDATE users SET approval_status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], $user['role'], 'reject_staff_account', 'user', (string) $id, $displayName);
    notifyStaff($target, $displayName, 'Your ProfilePath sign-up was not approved', 'Sign-up not approved', 'An administrator reviewed your staff sign-up and did not approve it. Please contact your system administrator if you think this is a mistake.');
    jsonResponse(['success' => true, 'status' => 'rejected']);
}

// Unlock a staff account locked by wrong passwords. Only an administrator does this, even when
// guidance staff were given Staff Accounts access.
if ($type === 'unlock') {
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Only an administrator can unlock a staff account.'], 403);
    }
    $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);
    LoginLock::clear($pdo, LoginLock::forAccount($id));   // the next lock is short again
    $who = trim((string) ($target['full_name'] ?? '')) !== '' ? $target['full_name'] : $target['username'];
    AuditLogger::log($user['id'], $user['role'], 'unlock_account', 'user', (string) $id,
        'Unlocked ' . $who . ' (' . StaffPosition::label($target['staff_position'] ?? null) . ') after failed sign-in attempts');
    jsonResponse(['success' => true]);
}

if ($type === 'toggleActive') {
    if ($target['approval_status'] !== 'approved') {
        jsonResponse(['success' => false, 'error' => 'Approve this account first.'], 409);
    }
    $newState = !$target['is_active'];
    // Bind an int, not a PHP bool: execute() stringifies false to '', which
    // Postgres's boolean type rejects.
    $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $id]);
    if (!$newState) {
        SessionEnd::endFor($pdo, $id, 'deactivated'); // signed out right away, not only at the next sign-in
    }
    // Who it was, as it stood at the time (the audit log shows this in the row details).
    $who = trim((string) ($target['full_name'] ?? '')) !== '' ? $target['full_name'] : $target['username'];
    AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_staff_account' : 'deactivate_staff_account', 'user', (string) $id,
        $who . ' (' . StaffPosition::label($target['staff_position'] ?? null) . ')');
    jsonResponse(['success' => true, 'isActive' => $newState]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
