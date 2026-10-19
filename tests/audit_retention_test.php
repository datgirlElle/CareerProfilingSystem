<?php
/**
 * Audit log retention rules (lib/AuditRetention.php): how many days are kept and when automatic
 * deletion is on. No database needed (the deleting itself was checked on the local database).
 *
 *   php tests/audit_retention_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every setting below is made up to check the software.
 */
require_once __DIR__ . '/../lib/AuditRetention.php';

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

echo "=== days kept ===\n";
check('nothing saved keeps 365 days', AuditRetention::days(null) === 365);
check('a saved 200 keeps 200 days', AuditRetention::days('200') === 200);
check('the limits themselves are allowed (90 and 365)', AuditRetention::days('90') === 90 && AuditRetention::days(365) === 365);
check('fewer than 90 days becomes 90', AuditRetention::days('30') === 90 && AuditRetention::days(7) === 90);
check('more than 365 days becomes 365', AuditRetention::days('500') === 365);
check('a value that is not a number keeps 365 days', AuditRetention::days('abc') === 365 && AuditRetention::days('') === 365);
check('a negative number becomes 90', AuditRetention::days('-5') === 90);

echo "\n=== the setting ===\n";
$none = AuditRetention::fromRows([]);
check('nothing saved: automatic deletion is off', $none['autoDelete'] === false);
check('nothing saved: 365 days', $none['days'] === 365);
$on = AuditRetention::fromRows(['retention.enabled' => 'true', 'monitoring.retentionDays' => '120']);
check('switch saved on: automatic deletion is on, 120 days', $on === ['autoDelete' => true, 'days' => 120]);
check('switch saved off stays off', AuditRetention::fromRows(['retention.enabled' => 'false'])['autoDelete'] === false);
check('anything other than "true" counts as off', AuditRetention::fromRows(['retention.enabled' => '1'])['autoDelete'] === false);
check('an older saved 30 days is read as 90', AuditRetention::fromRows(['monitoring.retentionDays' => '30'])['days'] === 90);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
