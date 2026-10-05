<?php
// Staff (guidance counselor) accounts are now created by the staff member
// signing up on their own, then approved by an administrator.
//   users.approval_status  'pending' | 'approved' | 'rejected'
//       Existing rows default to 'approved' so every account that already
//       exists keeps working exactly as before. Only counselor sign-ups start
//       as 'pending'; students and admins are never pending.
//   users.full_name        Staff display name shown to the admin when approving
//       (students keep their encrypted name in the students table).

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS approval_status VARCHAR(10) NOT NULL DEFAULT 'approved'");
$pdo->exec('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_approval_status_check');
$pdo->exec("ALTER TABLE users ADD CONSTRAINT users_approval_status_check CHECK (approval_status IN ('pending', 'approved', 'rejected'))");
$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(150)');
// Staff pick their position when signing up. Both positions use the same
// system role ('counselor') and so have exactly the same access; the position
// is only the title shown next to their name. NULL (accounts that already
// exist) is shown as Guidance Counselor.
$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS staff_position VARCHAR(12)');
$pdo->exec('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_staff_position_check');
$pdo->exec("ALTER TABLE users ADD CONSTRAINT users_staff_position_check CHECK (staff_position IS NULL OR staff_position IN ('counselor', 'facilitator'))");

echo "users.approval_status, users.full_name and users.staff_position added (or already present).\n";
