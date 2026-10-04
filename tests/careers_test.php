<?php

require_once __DIR__ . '/../lib/Careers.php';

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

echo "=== parse / toLiteral: Postgres TEXT[] round trip ===\n";
check('empty array literal -> []', Careers::parse('{}') === []);
check('null -> []', Careers::parse(null) === []);
check('unquoted elements', Careers::parse('{Nurse,Teacher}') === ['Nurse', 'Teacher']);
check('quoted elements with spaces', Careers::parse('{"Software Engineer","Data Scientist"}') === ['Software Engineer', 'Data Scientist']);
$tricky = ['QA, Test "Lead"', 'Back\\slash', 'Plain'];
check('commas, quotes and backslashes survive a round trip', Careers::parse(Careers::toLiteral($tricky)) === $tricky);

echo "\n=== normalize: trim, de-duplicate, limits ===\n";
check('trims, drops blanks, collapses spaces, drops case-insensitive duplicates',
    Careers::normalize(['Software Engineer', '  software   engineer ', '', 'Data Analyst']) === ['Software Engineer', 'Data Analyst']);
check('newline-separated string is split', Careers::normalize("A\r\nB\n\nC") === ['A', 'B', 'C']);
check('non-array/non-string input -> []', Careers::normalize(null) === []);

$rejected = false;
try {
    Careers::normalize(range(1, Careers::MAX_PER_PROGRAM + 1));
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('more than the per-program maximum is rejected', $rejected);

$rejected = false;
try {
    Careers::normalize([str_repeat('x', Careers::MAX_LENGTH + 1)]);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('an over-long career is rejected', $rejected);

echo "\n=== effective: falls back to the program title ===\n";
check('empty list -> [title]', Careers::effective([], 'BS Nursing') === ['BS Nursing']);
check('listed careers are used as-is', Careers::effective(['Registered Nurse'], 'BS Nursing') === ['Registered Nurse']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
