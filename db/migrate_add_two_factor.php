<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS two_factor_codes (
        id          SERIAL PRIMARY KEY,
        user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        code_hash   VARCHAR(255) NOT NULL,
        expires_at  TIMESTAMPTZ NOT NULL,
        used_at     TIMESTAMPTZ,
        attempts    INT NOT NULL DEFAULT 0,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_two_factor_codes_user_id ON two_factor_codes(user_id)");

echo "two_factor_codes table created (or already present).\n";
