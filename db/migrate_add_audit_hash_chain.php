<?php
// Tamper protection for the audit log: adds prev_hash and row_hash to audit_log and seals every
// existing entry, oldest first. Each seal is an HMAC of the entry and the seal before it, made with a
// secret built from APP_AES_KEY (lib/AuditChain.php), so run this with the same .env as the app, after
// the new code is deployed. Until it has run, the app writes entries without seals and "Check log"
// says it is not set up.
//
// Safe to run again: it only seals entries that still have none. It refuses an unsealed entry that has
// sealed ones after it (that can hide an edit). The one innocent cause is entries written during the
// deploy; only then run it with --reseal-after-gap. Never re-run it to clear a problem "Check log" found.

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/AuditChain.php';

$pdo = Database::get();
$resealAfterGap = in_array('--reseal-after-gap', $argv ?? [], true);

$pdo->beginTransaction();
$pdo->exec('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS prev_hash CHAR(64)');
$pdo->exec('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS row_hash CHAR(64)');
try {
    $sealed = AuditChain::sealUnsealed($pdo, $resealAfterGap);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    echo 'Nothing changed. ' . $e->getMessage() . "\n"
        . "This can mean an entry was tampered with. If it only happened during the deploy, run again with --reseal-after-gap.\n";
    exit(1);
}
$pdo->commit();

echo "Audit log seal columns ready. Entries sealed now: $sealed\n";
