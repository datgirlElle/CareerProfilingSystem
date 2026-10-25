<?php
// Adds users.is_co_admin: the administrator (Head of Guidance) can make a Guidance Counselor a co-admin on Account
// Management (modules/UserManagement/api/staff-approvals.php, type setCoAdmin). A co-admin keeps role = 'counselor' and
// gets the shared admin work (modules/Authentication/lib/Rbac.php, isCoAdmin); the permission table, security settings,
// audit log retention and full IP addresses stay with the administrator.
//
// The check constraint keeps the flag on Guidance Counselors only: never on a Guidance Facilitator (DFD process 4.0),
// a student or the administrator. Everyone starts as not co-admin. Safe to run twice.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();

$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_co_admin BOOLEAN NOT NULL DEFAULT FALSE');

$exists = $pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'users_co_admin_counselor_only'")->fetchColumn();
if (!$exists) {
    $pdo->exec("ALTER TABLE users ADD CONSTRAINT users_co_admin_counselor_only
                CHECK (NOT is_co_admin OR (role = 'counselor' AND staff_position IS DISTINCT FROM 'facilitator'))");
}

echo "users.is_co_admin added (or already present).\n";
