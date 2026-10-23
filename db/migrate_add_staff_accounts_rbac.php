<?php
// The "Staff Accounts" permission (approve staff sign-ups, send access codes): the administrator and the Guidance
// Counselors have it, Guidance Facilitators never do (modules/Authentication/lib/Rbac.php), students do not. The administrator can still
// change the counselor level in Security Configuration, Edit Roles. Safe to re-run: it never overwrites a level that
// is already saved.

require_once __DIR__ . '/../shared/lib/Database.php';

$stmt = Database::get()->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?) ON CONFLICT (module, role) DO NOTHING'
);
$added = 0;
foreach (['admin' => 'full', 'counselor' => 'full', 'student' => 'none'] as $role => $level) {
    $stmt->execute(['staffAccounts', $role, $level]);
    $added += $stmt->rowCount();
}
echo "Staff Accounts permission ready ($added row(s) added).\n";