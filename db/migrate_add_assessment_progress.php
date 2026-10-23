<?php
// Adds assessment_progress: a student's RIASEC answers in progress, saved on the server as they go
// (modules/CareerProfiling/lib/AssessmentProgress.php), and which exam session unlocked the assessment. A sign-out, a crash
// or a different PC then loses nothing, and a student who signs in again while the session is open
// continues without the access code. Answers are assessment data, so they are stored encrypted.
// Until this has run, the assessment works as before (answers kept in the browser only).

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS assessment_progress (
        student_id      INT PRIMARY KEY REFERENCES students(user_id) ON DELETE CASCADE,
        schedule_id     INT REFERENCES exam_schedules(id) ON DELETE SET NULL,
        is_retake       BOOLEAN NOT NULL DEFAULT FALSE,
        answers_enc     TEXT,
        updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);

echo "Created assessment_progress\n";
