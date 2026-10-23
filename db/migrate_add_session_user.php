<?php
// Adds two columns to sessions (modules/Authentication/lib/DbSessionHandler.php, modules/Authentication/lib/SessionEnd.php):
//   user_id       whose sign-in each stored session is, so one person can be signed out everywhere:
//                 a student who signs in on a new device (one device at a time), an account that is
//                 turned off, a password change or reset. Before this, a password reset found the
//                 person's sessions by searching the stored session text (fragile).
//   ended_reason  why a session was ended (other_device, deactivated, password_changed). The session is
//                 emptied, and the sign-in page on that device says why once.
//
// Existing sessions get NULL and fill in user_id on their next request. Safe to run twice.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();

$pdo->exec('ALTER TABLE sessions ADD COLUMN IF NOT EXISTS user_id INT');
$pdo->exec('ALTER TABLE sessions ADD COLUMN IF NOT EXISTS ended_reason VARCHAR(30)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)');

echo "sessions.user_id and sessions.ended_reason added (or already present).\n";
