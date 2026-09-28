<?php
// Adds notification_dismissals — which live-computed notifications a student
// has deleted. Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS notification_dismissals (
        user_id      INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        item_key     VARCHAR(100) NOT NULL,
        dismissed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        PRIMARY KEY (user_id, item_key)
    )'
);

echo "notification_dismissals is in place.\n";
