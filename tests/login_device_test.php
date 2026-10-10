<?php

require_once __DIR__ . '/../lib/LoginDevice.php';

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

echo "=== describing a device ===\n";
check('Chrome on Windows', LoginDevice::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36') === 'Chrome on Windows');
check('Edge is not mistaken for Chrome', LoginDevice::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36 Edg/126.0') === 'Edge on Windows');
check('Safari on iPhone', LoginDevice::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1') === 'Safari on iOS');
check('Chrome on Android', LoginDevice::describe('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36') === 'Chrome on Android');
check('Firefox on Linux', LoginDevice::describe('Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0') === 'Firefox on Linux');
check('nothing sent', LoginDevice::describe('') === 'Unknown browser on unknown device');

echo "\n=== a second person, or the same one? ===\n";
$recent = [['ip' => '203.0.113.5', 'device' => 'Chrome on Windows']];
check('same IP and device is the same person', LoginDevice::conflictsWith('203.0.113.5', 'Chrome on Windows', $recent) === null);
check('a different IP is flagged', LoginDevice::conflictsWith('198.51.100.9', 'Chrome on Windows', $recent) !== null);
check('a different device is flagged', LoginDevice::conflictsWith('203.0.113.5', 'Safari on iOS', $recent) !== null);
check('no earlier login means nothing to compare', LoginDevice::conflictsWith('203.0.113.5', 'Chrome on Windows', []) === null);
check('the conflicting earlier login is reported', LoginDevice::conflictsWith('198.51.100.9', 'Safari on iOS', $recent)['ip'] === '203.0.113.5');

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
