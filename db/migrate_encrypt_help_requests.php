<?php
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';

$pdo = Database::get();

$pdo->exec("ALTER TABLE help_requests ADD COLUMN IF NOT EXISTS name_enc TEXT");
$pdo->exec("ALTER TABLE help_requests ADD COLUMN IF NOT EXISTS subject_enc TEXT");

// Backfill any existing plaintext rows before dropping the old columns —
// safe to run even on a table that's already been migrated once, since
// the WHERE clause only picks up rows that haven't been backfilled yet.
// A database created from the current schema.sql never had the plaintext columns: nothing to backfill.
$hasPlain = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'help_requests' AND column_name = 'name'")->fetchColumn();
$rows = $hasPlain ? $pdo->query("SELECT id, name, subject FROM help_requests WHERE name_enc IS NULL")->fetchAll() : [];
foreach ($rows as $row) {
    $pdo->prepare('UPDATE help_requests SET name_enc = ?, subject_enc = ? WHERE id = ?')
        ->execute([Crypto::enc($row['name']), Crypto::enc($row['subject']), $row['id']]);
}

$pdo->exec('ALTER TABLE help_requests DROP COLUMN IF EXISTS name');
$pdo->exec('ALTER TABLE help_requests DROP COLUMN IF EXISTS subject');

echo 'help_requests.name/subject are now encrypted (backfilled ' . count($rows) . " existing row(s)).\n";
