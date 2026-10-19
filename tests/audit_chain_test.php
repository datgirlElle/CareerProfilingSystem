<?php
/**
 * Audit log seals (AuditChain::seal): the same entry always gets the same seal, and any change to the
 * entry, to the seal before it, or to the secret gives a different one. No database needed (the chain
 * itself was checked on the local database).
 *
 *   php tests/audit_chain_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. The entry and the secret below are made up to check the software.
 */
require_once __DIR__ . '/../lib/AuditChain.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

$key = str_repeat('k', 32);   // made-up secret
$prev = str_repeat('a', 64);
$entry = [
    'id' => 41, 'created_us' => 1792300000123456, 'actor_user_id' => 7, 'actor_role' => 'admin',
    'action' => 'update_faq', 'target_type' => 'faq', 'target_id' => '12', 'detail_enc' => 'MADEUPCIPHERTEXT==', 'ip' => '127.0.0.1',
];
$seal = AuditChain::seal($prev, $entry, $key);

echo "=== the same entry, the same seal ===\n";
check('a seal is 64 hex characters', preg_match('/^[0-9a-f]{64}$/', $seal) === 1);
check('sealing again gives the same seal', AuditChain::seal($prev, $entry, $key) === $seal);
check('numbers read back as text give the same seal (5 and "5")',
    AuditChain::seal($prev, ['id' => '41', 'created_us' => '1792300000123456', 'actor_user_id' => '7'] + $entry, $key) === $seal);
check('the starting seal is 64 zeros', AuditChain::ZERO === str_repeat('0', 64));

echo "\n=== any change gives another seal ===\n";
$changed = ['id' => 42, 'created_us' => 1792300000123457, 'actor_user_id' => 8, 'actor_role' => 'counselor',
    'action' => 'delete_faq', 'target_type' => 'user', 'target_id' => '13', 'detail_enc' => 'OTHERCIPHERTEXT==', 'ip' => '127.0.0.2'];
$all = true;
foreach ($changed as $field => $value) {
    $all = $all && AuditChain::seal($prev, [$field => $value] + $entry, $key) !== $seal;
}
check('changing any one of the 9 fields changes the seal', $all);
check('a different seal before it changes the seal', AuditChain::seal(str_repeat('b', 64), $entry, $key) !== $seal);
check('a different secret changes the seal', AuditChain::seal($prev, $entry, str_repeat('x', 32)) !== $seal);
check('an empty value and no value read differently', AuditChain::seal($prev, ['target_id' => ''] + $entry, $key) !== AuditChain::seal($prev, ['target_id' => null] + $entry, $key));
check('moving text from one field to the next changes the seal',
    AuditChain::seal($prev, ['target_type' => 'fa', 'target_id' => 'q12'] + $entry, $key) !== $seal);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
