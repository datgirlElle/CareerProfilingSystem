<?php
// Retake by request: students ask, a counselor or the Head of Guidance approves, the earlier result stays as history.
// Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS retake_requests (
        id                          SERIAL PRIMARY KEY,
        student_id                  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        attempt_number              INT NOT NULL,
        reason_enc                  TEXT NOT NULL,
        status                      VARCHAR(10) NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\', \'approved\', \'declined\', \'completed\')),
        requested_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        decided_by                  INT REFERENCES users(id) ON DELETE SET NULL,
        decided_at                  TIMESTAMPTZ,
        decision_note_enc           TEXT,
        completed_attempt_number    INT,
        completed_at                TIMESTAMPTZ
    )'
);
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_retake_requests_open ON retake_requests (student_id) WHERE status IN (\'pending\', \'approved\')');
echo "retake_requests ready.\n";
