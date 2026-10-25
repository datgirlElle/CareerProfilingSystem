<?php

require_once __DIR__ . '/../modules/CareerDataset/lib/Careers.php';

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
check('more than the safety cap (' . Careers::MAX_PER_PROGRAM . ') is rejected', $rejected);

echo "\n=== no small per-program limit any more ===\n";
check('25 careers are accepted', count(Careers::normalize(range(1, 25))) === 25);
check('120 careers are accepted', count(Careers::normalize(array_map(fn($i) => "Career $i", range(1, 120)))) === 120);
check('the safety cap itself (' . Careers::MAX_PER_PROGRAM . ') is accepted', count(Careers::normalize(range(1, Careers::MAX_PER_PROGRAM))) === Careers::MAX_PER_PROGRAM);
$rejected = false;
try {
    Careers::normalize(str_repeat("x\n", Careers::MAX_PER_PROGRAM * 200));
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('a huge pasted block is refused before it is processed', $rejected);
$rejected = false;
try {
    Careers::normalize(array_fill(0, Careers::MAX_PER_PROGRAM * 3, 'Same'));
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('a huge list is refused even if it is all repeats', $rejected);
$rejected = false;
try {
    Careers::normalize(['Nurse', ['nested', 'array']]);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('an item that is not text (a nested list) is refused', $rejected);

echo "\n=== merge: adds only what is missing, never removes ===\n";
[$merged, $added] = Careers::merge(['Counselor Custom', 'registered nurse'], ['Registered Nurse', 'Staff Nurse', '  School   Nurse ']);
check('saved careers stay first, in their order', array_slice($merged, 0, 2) === ['Counselor Custom', 'registered nurse']);
check('a career already saved in other capitals is not added again', !in_array('Registered Nurse', $added, true));
check('only the missing ones are added (tidied)', $added === ['Staff Nurse', 'School Nurse']);
[$again, $none] = Careers::merge($merged, ['Registered Nurse', 'Staff Nurse', 'School Nurse']);
check('running it again adds nothing', $none === [] && $again === $merged);
check('nothing saved: the whole official list is added', Careers::merge([], ['A', 'B'])[0] === ['A', 'B']);

echo "\n=== changes: what the Audit Log records ===\n";
check('2 added, 1 removed', Careers::changes(['A', 'B', 'C'], ['A', 'c', 'D', 'E']) === ['added' => 2, 'removed' => 1]);
check('same list in other capitals: no change', Careers::changes(['Nurse'], ['nurse']) === ['added' => 0, 'removed' => 0]);

$rejected = false;
try {
    Careers::normalize([str_repeat('x', Careers::MAX_LENGTH + 1)]);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('an over-long career is rejected', $rejected);

echo "\n=== effective: falls back to the starter careers, never the program name ===\n";
$nursing = Careers::effective([], 'BS Nursing');
check('empty list -> the program\'s starter careers', in_array('Registered Nurse', $nursing, true));
check('the program name is never offered as a career', !in_array('BS Nursing', $nursing, true));
check('every starter list has careers and none is a program name', (function () {
    foreach (StarterCareers::LISTS as $title => $list) {
        if ($list === [] || in_array($title, $list, true) || count($list) > Careers::MAX_PER_PROGRAM) {
            return false;
        }
    }
    return true;
})());
check('a program with no starter list and no careers -> []', Careers::effective([], 'BS Underwater Basket Weaving') === []);
check('listed careers are used as-is', Careers::effective(['Registered Nurse'], 'BS Nursing') === ['Registered Nurse']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
