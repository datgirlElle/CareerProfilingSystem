<?php
// The emailed 6-digit codes behind "Forgot password" (api/password-reset.php). Replaces the old emailed-link
// flow; password_reset_tokens is left in place but is no longer used. Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS password_reset_codes (
        id                SERIAL PRIMARY KEY,
        user_id           INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        portal            VARCHAR(10) NOT NULL CHECK (portal IN ('student', 'staff')),
        code_hash         VARCHAR(255) NOT NULL,
        attempts          INT NOT NULL DEFAULT 0,
        expires_at        TIMESTAMPTZ NOT NULL,
        verified_at       TIMESTAMPTZ,
        reset_token_hash  VARCHAR(255),
        reset_expires_at  TIMESTAMPTZ,
        used_at           TIMESTAMPTZ,
        created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_codes_user ON password_reset_codes (user_id, id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_codes_token ON password_reset_codes (reset_token_hash)');

echo "password_reset_codes table created (or already present).\n";
