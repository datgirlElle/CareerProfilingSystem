<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS rate_limit_hits (
        id SERIAL PRIMARY KEY,
        rate_key VARCHAR(255) NOT NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_hits_key_time ON rate_limit_hits(rate_key, created_at)");

echo "rate_limit_hits table created (or already present).\n";
