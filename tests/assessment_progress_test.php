<?php
/**
 * Assessment progress rules (lib/AssessmentProgress.php): which answers can be saved, and until
 * when an unlocked assessment can still be answered and submitted. No database needed (saving,
 * unlocking and submitting were checked on the local database).
 *
 *   php tests/assessment_progress_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. The dates and answers below are made up to check the software.
 */
require_once __DIR__ . '/../lib/AssessmentProgress.php';

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
$at = fn(string $time) => new DateTimeImmutable("2026-10-20 $time", $tz);

echo "=== until when it can be answered and submitted ===\n";
$w = AssessmentProgress::window('2026-10-20', '10:00:00', $at('09:30:00'));
check('during the session it is open', $w['open'] === true);
check('the session end is 10:00 Manila time', $w['endsAt'] === '2026-10-20T10:00:00+08:00');
check('it can be submitted until 15 minutes after the end', $w['submitUntil'] === '2026-10-20T10:15:00+08:00');
check('5 minutes after the end it is still open (grace)', AssessmentProgress::window('2026-10-20', '10:00', $at('10:05:00'))['open'] === true);
check('at the end of the grace period it is closed', AssessmentProgress::window('2026-10-20', '10:00', $at('10:15:00'))['open'] === false);
check('the next day it is closed', AssessmentProgress::window('2026-10-20', '10:00', new DateTimeImmutable('2026-10-21 08:00', $tz))['open'] === false);

echo "\n=== which answers can be saved ===\n";
check('a full set of answers 1 to 5 is accepted', AssessmentProgress::clean([1, 2, 3, 4, 5, 3], 6) === [1, 2, 3, 4, 5, 3]);
check('unanswered questions (null) are accepted', AssessmentProgress::clean([5, null, null, 1, null, null], 6) === [5, null, null, 1, null, null]);
check('a 0 or a 6 is refused', AssessmentProgress::clean([0, 1, 1, 1, 1, 1], 6) === null && AssessmentProgress::clean([6, 1, 1, 1, 1, 1], 6) === null);
check('text instead of a number is refused', AssessmentProgress::clean(['5', 1, 1, 1, 1, 1], 6) === null);
check('a decimal is refused', AssessmentProgress::clean([2.5, 1, 1, 1, 1, 1], 6) === null);
check('too few or too many answers are refused', AssessmentProgress::clean([1, 1, 1], 6) === null && AssessmentProgress::clean(array_fill(0, 7, 1), 6) === null);
check('answers keyed by name instead of a list are refused', AssessmentProgress::clean(['a' => 1, 'b' => 2], 2) === null);
check('something that is not a list at all is refused', AssessmentProgress::clean('1,2,3', 3) === null && AssessmentProgress::clean(null, 0) === null);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
