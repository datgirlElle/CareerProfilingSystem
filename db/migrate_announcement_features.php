<?php
// Announcement page revision:
//   announcements.status            'draft' | 'sent'. Existing rows are 'sent' (they were all sent or
//                                   scheduled). A draft is not visible to students and is never emailed.
//   announcements.last_reminded_at  when "Remind Unread" last emailed this announcement's unread students
//                                   (limits it to one reminder per 24 hours).
//   announcement_reads              which student saw which announcement, and when. Drives "% read".

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'sent' CHECK (status IN ('draft', 'sent'))");
$pdo->exec('ALTER TABLE announcements ADD COLUMN IF NOT EXISTS last_reminded_at TIMESTAMPTZ');
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS announcement_reads (
        announcement_id INT NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
        student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        read_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        PRIMARY KEY (announcement_id, student_id)
    )'
);

echo "announcements.status, announcements.last_reminded_at and announcement_reads added (or already present).\n";
