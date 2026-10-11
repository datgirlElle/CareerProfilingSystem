<?php
// Counseling invitations: a counselor invites a student from Student Accounts, and the student gets the CGC booking link by
// email and as a notification. Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS counseling_invites (
        id          SERIAL PRIMARY KEY,
        student_id  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        invited_by  INT REFERENCES users(id) ON DELETE SET NULL,
        booking_url VARCHAR(300) NOT NULL,
        email_sent  BOOLEAN NOT NULL DEFAULT FALSE,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )'
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_counseling_invites_student ON counseling_invites (student_id, id)');
echo "counseling_invites ready.\n";