<?php

/**
 * Changes the encryption key (APP_AES_KEY): every encrypted value is decrypted with the current key and
 * encrypted again with the new one, and the audit log is re-sealed with the new key
 * (modules/Encryption/lib/KeyRotation.php). Usage:
 *   php db/rotate_aes_key.php --generate-key   print a new random key (touches no database)
 *   php db/rotate_aes_key.php                  dry run: the whole rotation, then rolled back; saves nothing
 *   php db/rotate_aes_key.php --apply          rotate and save
 *
 * The keys come from the environment, never from the command line: APP_AES_KEY is the current key and
 * APP_AES_KEY_NEW the new one. Neither is printed, only a short fingerprint of each.
 *
 * On the live site: suspend the web service first (otherwise it keeps writing with the old key while this
 * runs, and cannot read anything once it is done), run the dry run, then --apply, set APP_AES_KEY to the
 * new key in Render, and resume the service. Keep the old key until the site works with the new one.
 */

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/KeyRotation.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

if (in_array('--generate-key', $argv, true)) {
    echo base64_encode(random_bytes(32)), "\n";
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$oldKey = (string) envValue('APP_AES_KEY');
$newKey = (string) envValue('APP_AES_KEY_NEW');
if (!KeyRotation::validKey($oldKey)) {
    echo "APP_AES_KEY (the current key) must be set to base64 of 32 bytes.\n";
    exit(1);
}
if (!KeyRotation::validKey($newKey)) {
    echo "APP_AES_KEY_NEW (the new key) must be set to base64 of 32 bytes. Make one with: php db/rotate_aes_key.php --generate-key\n";
    exit(1);
}

echo ($apply ? 'Rotating' : 'Dry run (nothing is saved)') . ' on database ' . envValue('DB_NAME') . ' at ' . envValue('DB_HOST') . "\n";
echo 'Current key ' . KeyRotation::fingerprint($oldKey) . ' -> new key ' . KeyRotation::fingerprint($newKey) . "\n\n";

$pdo = Database::get();
$pdo->beginTransaction();
try {
    $result = KeyRotation::rotate($pdo, $oldKey, $newKey);
    foreach ($result['columns'] as $c) {
        printf("  %-40s %6d values\n", $c['table'] . '.' . $c['column'], $c['values']);
    }
    printf("\n  %d values re-encrypted, %d audit log entries re-sealed, all read back correctly with the new key.\n",
        $result['values'], $result['auditSealed']);
    if ($apply) {
        $pdo->commit();
        echo "\nSaved. Now set APP_AES_KEY to the new key where the site runs (Render: Environment), then resume or redeploy it.\n";
    } else {
        $pdo->rollBack();
        echo "\nDry run: rolled back, nothing changed. Run again with --apply to rotate.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nStopped, nothing changed: " . $e->getMessage() . "\n";
    exit(1);
}
