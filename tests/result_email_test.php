<?php

require_once __DIR__ . '/../lib/ResultEmail.php';

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

$titles = ['BS Nursing', 'BS Computer Science', 'BS Biology'];

echo "=== the automatic result email ===\n";
$match = ResultEmail::build(false, $titles);
check('a match thanks the student and lists the Top Matches', strpos($match['message'], 'Top Matches') !== false && strpos($match['message'], 'BS Nursing') !== false);
check('the programs are listed alphabetically, not ranked', strpos($match['message'], 'BS Biology, BS Computer Science, BS Nursing') !== false);
check('no percentage or score appears', strpos($match['message'], '%') === false && stripos($match['message'], 'score') === false);
check('a match links to Career Results', $match['ctaPath'] === '/results');

$mis = ResultEmail::build(true, $titles);
check('a mismatch asks the student to visit the Guidance Office', strpos($mis['message'], 'visit the Guidance Office') !== false);
check('a mismatch names no course', strpos($mis['message'], 'BS ') === false);
check('a mismatch links to sign in', $mis['ctaPath'] === '/login');

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
