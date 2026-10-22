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
check('exact listed career (any case/spacing)', CareerMatcher::resolve('  civil   ENGINEER ', $programs) === 4);
check('program title typed as a career', CareerMatcher::resolve('Nursing', $programs) === 2);
check('word form differs from the list ("nurse" -> Registered Nurse)', CareerMatcher::resolve('nurse', $programs) === 2);
check('profession vs program word ("pharmacist" -> BS Pharmacy)', CareerMatcher::resolve('pharmacist', $programs) === 3);
check('partial overlap on a meaningful word ("software developer")', CareerMatcher::resolve('software developer', $programs) === 1);
check('exact career beats a looser one ("hotel manager" not Marketing Manager)', CareerMatcher::resolve('hotel manager', $programs) === 6);
check('only a generic word in common is NOT a link ("bank manager")', CareerMatcher::resolve('bank manager', $programs) === null);
check('unknown career has no link ("game developer")', CareerMatcher::resolve('game developer', $programs) === null);
// Regression: "design"/"designer" alone used to link "graphic designer" to
// Electrical Engineering through "Electrical Design Engineer".
check('"graphic designer" is NOT linked to Electrical Engineering via the word "design"', CareerMatcher::resolve('graphic designer', $programs) === null);
check('"interior designer" is not linked through "design" either', CareerMatcher::resolve('interior designer', $programs) === null);
$withMedia = array_merge($programs, [['id' => 9, 'title' => 'BS Multimedia Arts', 'hollandCode' => 'AER', 'careers' => ['Graphic Designer', 'Animator']]]);
check('"graphic designer" links to the program that lists it', CareerMatcher::resolve('graphic designer', $withMedia) === 9);
check('"Electrical Design Engineer" still links to Electrical Engineering', CareerMatcher::resolve('electrical design engineer', $programs) === 7);
check('gibberish has no link', CareerMatcher::resolve('zzzz qqqq', $programs) === null);
check('empty text has no link', CareerMatcher::resolve('   ', $programs) === null);

echo "\n=== resolve: ties never use the student's RIASEC scores ===\n";
// "engineer" is generic, so every engineering program matches equally.
$engineering = array_column(CareerMatcher::rank('engineer', $programs), 'id');
check('"engineer" matches several programs equally (a tie)', count($engineering) > 1);
check('the tie goes to the first program in the list (lowest id), not to a RIASEC fit', CareerMatcher::resolve('engineer', $programs) === min($engineering));

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
