<?php
// Tracks whether an announcement's email has gone out, so an immediate
// announcement emails once at creation and a scheduled one emails the first
// time a staff member loads Announcements at or after its publish time
// (see api/announcements.php) — never twice. Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec('ALTER TABLE announcements ADD COLUMN IF NOT EXISTS emailed_at TIMESTAMPTZ');

echo "announcements.emailed_at is in place.\n";
