<?php
// Adds consent_records: one row each time a person agrees to the Terms of Use and the Data Privacy
// Policy (RA 10173 proof of consent). Written by api/register.php (students) and
// api/staff-register.php (staff sign-up) through modules/UserManagement/lib/Consent.php, and shown to the person on
// Student Settings / Staff Profile.
//
// Stores only who, which policy version, how, and when (no IP address or device). Accounts made
// before this table existed have no receipt; nothing is backfilled, because nobody can say when
// (or whether) those people agreed.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consent_records (
        id              BIGSERIAL PRIMARY KEY,
        user_id         INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        policy_version  VARCHAR(20) NOT NULL,
        method          VARCHAR(30) NOT NULL CHECK (method IN ('registration', 'staff_signup')),
        consented_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_consent_records_user ON consent_records (user_id)');

echo "consent_records table created (or already present).\n";
