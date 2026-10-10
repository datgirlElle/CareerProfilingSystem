<?php

require_once __DIR__ . '/../lib/CareerMatcher.php';

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

$programs = [
    ['id' => 1, 'title' => 'BS Computer Science', 'hollandCode' => 'IRC', 'careers' => ['Software Engineer', 'Data Scientist']],
    ['id' => 2, 'title' => 'BS Nursing', 'hollandCode' => 'SIR', 'careers' => ['Registered Nurse', 'Nurse Educator']],
    ['id' => 3, 'title' => 'BS Pharmacy', 'hollandCode' => 'ISC', 'careers' => ['Pharmacist']],
    ['id' => 4, 'title' => 'BS Civil Engineering', 'hollandCode' => 'RIC', 'careers' => ['Civil Engineer', 'Structural Engineer']],
    ['id' => 5, 'title' => 'BS Marketing', 'hollandCode' => 'EAS', 'careers' => ['Marketing Manager', 'Brand Manager']],
    ['id' => 6, 'title' => 'BS Hospitality Management', 'hollandCode' => 'ESC', 'careers' => ['Hotel Manager']],
    ['id' => 7, 'title' => 'BS Electrical Engineering', 'hollandCode' => 'RIC', 'careers' => ['Electrical Engineer', 'Electrical Design Engineer']],
    ['id' => 8, 'title' => 'BS Mechanical Engineering', 'hollandCode' => 'REC', 'careers' => ['Mechanical Engineer']],
];

echo "=== resolve: typed career -> program ===\n";
check('exact listed career (any case/spacing)', CareerMatcher::resolve('  civil   ENGINEER ', $programs, null) === 4);
check('program title typed as a career', CareerMatcher::resolve('Nursing', $programs, null) === 2);
check('word form differs from the list ("nurse" -> Registered Nurse)', CareerMatcher::resolve('nurse', $programs, null) === 2);
check('profession vs program word ("pharmacist" -> BS Pharmacy)', CareerMatcher::resolve('pharmacist', $programs, null) === 3);
check('partial overlap on a meaningful word ("software developer")', CareerMatcher::resolve('software developer', $programs, null) === 1);
check('exact career beats a looser one ("hotel manager" not Marketing Manager)', CareerMatcher::resolve('hotel manager', $programs, null) === 6);
check('only a generic word in common is NOT a link ("bank manager")', CareerMatcher::resolve('bank manager', $programs, null) === null);
check('unknown career has no link ("game developer")', CareerMatcher::resolve('game developer', $programs, null) === null);
// Regression: "design"/"designer" alone used to link "graphic designer" to
// Electrical Engineering through "Electrical Design Engineer".
check('"graphic designer" is NOT linked to Electrical Engineering via the word "design"', CareerMatcher::resolve('graphic designer', $programs, null) === null);
check('"interior designer" is not linked through "design" either', CareerMatcher::resolve('interior designer', $programs, null) === null);
$withMedia = array_merge($programs, [['id' => 9, 'title' => 'BS Multimedia Arts', 'hollandCode' => 'AER', 'careers' => ['Graphic Designer', 'Animator']]]);
check('"graphic designer" links to the program that lists it', CareerMatcher::resolve('graphic designer', $withMedia, null) === 9);
check('"Electrical Design Engineer" still links to Electrical Engineering', CareerMatcher::resolve('electrical design engineer', $programs, null) === 7);
check('gibberish has no link', CareerMatcher::resolve('zzzz qqqq', $programs, null) === null);
check('empty text has no link', CareerMatcher::resolve('   ', $programs, null) === null);

echo "\n=== resolve: ties go to the student's RIASEC fit ===\n";
$realistic = ['R' => 45, 'I' => 20, 'A' => 10, 'S' => 10, 'E' => 30, 'C' => 25]; // RIASEC totals are 10-50
$investigative = ['R' => 10, 'I' => 48, 'A' => 10, 'S' => 10, 'E' => 10, 'C' => 40];
// "engineer" is generic, so every engineering program matches equally.
check('"engineer" for a Realistic/Enterprising student -> REC Mechanical (id 8)', CareerMatcher::resolve('engineer', $programs, $realistic) === 8);
check('"engineer" for an Investigative/Conventional student -> IRC/RIC program', in_array(CareerMatcher::resolve('engineer', $programs, $investigative), [1, 4, 7], true));
check('tie with no scores still returns a program', CareerMatcher::resolve('engineer', $programs, null) !== null);

echo "\n=== sanitize: what the student typed ===\n";
check('trims and collapses spaces', CareerMatcher::sanitize("  Software    Engineer ") === 'Software Engineer');
check('allows punctuation careers use', CareerMatcher::sanitize("Nurse (ICU) / Midwife, R&D") === 'Nurse (ICU) / Midwife, R&D');
check('allows accented letters', CareerMatcher::sanitize('Diseñador Gráfico') === 'Diseñador Gráfico');
check('rejects 1 character', CareerMatcher::sanitize('a') === null);
check('rejects over 80 characters', CareerMatcher::sanitize(str_repeat('a', 81)) === null);
check('rejects markup', CareerMatcher::sanitize('<script>alert(1)</script>') === null);
check('rejects digits only', CareerMatcher::sanitize('12345') === null);
check('rejects empty', CareerMatcher::sanitize('   ') === null);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
