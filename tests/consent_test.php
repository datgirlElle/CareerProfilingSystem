<?php

// No database needed: checks the parts of lib/Consent.php that work without one.
require_once __DIR__ . '/../lib/Consent.php';

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

echo "=== consent receipts: policy version ===\n";
check('the current version is a year and month (YYYY-MM)', preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', Consent::VERSION) === 1);
check('LABEL matches VERSION, so receipts and pages name the same policy', Consent::label(Consent::VERSION) === Consent::LABEL);
check('a stored version is shown as a month and year', Consent::label('2026-08') === 'August 2026' && Consent::label('2027-01') === 'January 2027');
check('a version that is not YYYY-MM is shown as it is', Consent::label('v2') === 'v2' && Consent::label('2026-13') === '2026-13');
check('the policy pages say the same month as LABEL ("Last updated: ' . Consent::LABEL . '")',
    str_contains((string) file_get_contents(__DIR__ . '/../privacy.html'), 'Last updated: ' . Consent::LABEL)
    && str_contains((string) file_get_contents(__DIR__ . '/../terms.html'), 'Last updated: ' . Consent::LABEL));

echo "\n=== consent receipts: how the person agreed ===\n";
check('only the two sign-up forms are allowed', Consent::METHODS === ['registration', 'staff_signup']);
$refused = false;
try {
    // The method is checked before any database call, so no PDO is needed to see the refusal.
    Consent::record(new class extends PDO { public function __construct() {} }, 1, 'made_up_method');
} catch (InvalidArgumentException $e) {
    $refused = true;
}
check('an unknown method is refused before anything is saved', $refused);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
