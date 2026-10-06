<?php

require_once __DIR__ . '/../lib/AcademicYear.php';

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

$tz = new DateTimeZone('Asia/Manila');
$at = fn(string $date) => new DateTimeImmutable($date . ' 12:00:00', $tz);

echo "=== AcademicYear::current: July to June ===\n";
check('1 July starts the new year', AcademicYear::current($at('2026-07-01')) === '2026-2027');
check('30 June is still the old year', AcademicYear::current($at('2026-06-30')) === '2025-2026');
check('August 2026 -> 2026-2027', AcademicYear::current($at('2026-08-15')) === '2026-2027');
check('October 2026 -> 2026-2027', AcademicYear::current($at('2026-10-05')) === '2026-2027');
check('January 2027 -> still 2026-2027', AcademicYear::current($at('2027-01-15')) === '2026-2027');
check('June 2027 -> still 2026-2027', AcademicYear::current($at('2027-06-30')) === '2026-2027');
check('1 July 2027 -> 2027-2028', AcademicYear::current($at('2027-07-01')) === '2027-2028');
check('31 December -> same year start', AcademicYear::current($at('2026-12-31')) === '2026-2027');

echo "\n=== uses school time (Asia/Manila), not the server's clock ===\n";
// 2026-06-30 17:00 UTC is 2026-07-01 01:00 in Manila -> the new academic year already.
$utcLate = new DateTimeImmutable('2026-06-30 17:00:00', new DateTimeZone('UTC'));
check('late 30 June UTC is already 1 July in Manila', AcademicYear::current($utcLate->setTimezone($tz)) === '2026-2027');
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
