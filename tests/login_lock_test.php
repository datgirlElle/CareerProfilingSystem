<?php
/**
 * Soft sign-in lock rules (lib/LoginLock.php): how long each lock lasts, and how names with no
 * account are kept. No database needed (counting and locking were checked on the local database).
 *
 *   php tests/login_lock_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. The names below are made up to check the software.
 */
require_once __DIR__ . '/../lib/LoginLock.php';

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

echo "=== how long each lock lasts (admin's lockout length 15) ===\n";
check('first lock of the day: 1 minute', LoginLock::minutesFor(1, 15) === 1);
check('second lock: 5 minutes', LoginLock::minutesFor(2, 15) === 5);
check('third lock: the admin\'s 15 minutes', LoginLock::minutesFor(3, 15) === 15);
check('later locks stay at 15', LoginLock::minutesFor(7, 15) === 15);

echo "\n=== the admin's length is the ceiling ===\n";
check('lockout length 3: 1, then 3, then 3', LoginLock::minutesFor(1, 3) === 1 && LoginLock::minutesFor(2, 3) === 3 && LoginLock::minutesFor(3, 3) === 3);
check('lockout length 1: always 1', LoginLock::minutesFor(1, 1) === 1 && LoginLock::minutesFor(4, 1) === 1);
check('lockout length 60: 1, 5, then 60', LoginLock::minutesFor(2, 60) === 5 && LoginLock::minutesFor(3, 60) === 60);
check('a broken setting of 0 still locks for 1 minute', LoginLock::minutesFor(1, 0) === 1 && LoginLock::minutesFor(3, 0) === 1);

echo "\n=== names with no account ===\n";
check('an account and a name are kept apart', LoginLock::forAccount(12) === 'u:12' && str_starts_with(LoginLock::forName('12'), 'n:'));
check('a name is stored as a hash, never as typed', LoginLock::forName('MadeUp-Typed-Password1!') === 'n:' . hash('sha256', 'madeup-typed-password1!'));
check('capital letters do not make a different name', LoginLock::forName('Made.Up') === LoginLock::forName('made.up'));

echo "\n=== the waiting message ===\n";
check('about a minute left reads "1 minute"', str_starts_with(LoginLock::waitText(time() + 30), 'Try again in 1 minute,'));
check('4.5 minutes left reads "5 minutes"', str_starts_with(LoginLock::waitText(time() + 270), 'Try again in 5 minutes,'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
