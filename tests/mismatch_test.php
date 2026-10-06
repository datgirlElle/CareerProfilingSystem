<?php

require_once __DIR__ . '/../lib/Mismatch.php';

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

$scores = [
    ['programId' => 1, 'score' => 0.30],
    ['programId' => 2, 'score' => 0.90],
    ['programId' => 3, 'score' => 0.70],
    ['programId' => 4, 'score' => 0.50],
    ['programId' => 5, 'score' => 0.10],
];

echo "=== match or mismatch ===\n";
check('the chosen program is the best match -> match', Mismatch::isMismatch(2, $scores) === false);
check('the chosen program is third -> match (still in the top three)', Mismatch::isMismatch(4, $scores) === false);
check('the chosen program is fourth -> mismatch', Mismatch::isMismatch(1, $scores) === true);
check('the chosen program is last -> mismatch', Mismatch::isMismatch(5, $scores) === true);
check('the typed career could not be linked to a program -> mismatch', Mismatch::isMismatch(null, $scores) === true);
check('the order the scores are stored in does not matter', Mismatch::isMismatch(3, array_reverse($scores)) === false);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
