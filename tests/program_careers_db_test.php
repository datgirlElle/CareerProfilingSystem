<?php
// DEVELOPMENT TEST DATA ONLY: made-up programs and careers, all inside one transaction that is rolled back, so the
// database is left exactly as it was. None of it is research data.
//
// Saving and reading back a program's careers the way api/programs.php does (Careers::normalize -> TEXT[] -> parse):
// more than 20 careers, duplicates, editing and removing, the same career in two programs, other programs untouched,
// and CareerMatcher finding a career deep in a long list.
//   php tests/program_careers_db_test.php
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/CareerMatcher.php';

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

$pdo = Database::get();
$collegeId = (int) $pdo->query('SELECT id FROM colleges ORDER BY id LIMIT 1')->fetchColumn();
if ($collegeId === 0) {
    echo "No college in this database; run the setup first.\n";
    exit(1);
}

$save = function (int $id, $input) use ($pdo): void {
    $pdo->prepare('UPDATE programs SET careers = ?::text[] WHERE id = ?')->execute([Careers::toLiteral(Careers::normalize($input)), $id]);
};
$read = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT careers FROM programs WHERE id = ?');
    $stmt->execute([$id]);
    return Careers::parse($stmt->fetchColumn());
};
$careers = fn(int $n, string $prefix = 'QA Career') => array_map(fn($i) => "$prefix $i", range(1, $n));

$pdo->beginTransaction();
try {
    $others = $pdo->query('SELECT id, careers FROM programs ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $insert = $pdo->prepare("INSERT INTO programs (college_id, title_enc, holland_code_enc, status) VALUES (?, ?, ?, 'Active') RETURNING id");
    $insert->execute([$collegeId, Crypto::enc('QA Careers Program A'), Crypto::enc('RIC')]);
    $a = (int) $insert->fetchColumn();
    $insert->execute([$collegeId, Crypto::enc('QA Careers Program B'), Crypto::enc('SEC')]);
    $b = (int) $insert->fetchColumn();

    echo "=== more than 20 careers are saved and read back ===\n";
    $save($a, $careers(35));
    check('35 careers saved and read back in the same order', $read($a) === $careers(35));
    $save($a, $careers(120));
    check('120 careers saved and read back', $read($a) === $careers(120));
    $save($a, implode("\n", $careers(40)));
    check('a pasted block of 40 lines is saved as 40 careers', count($read($a)) === 40);

    echo "\n=== duplicates and tidying ===\n";
    $save($a, array_merge($careers(25), ['qa career 3', '  QA   Career 7 ', '', 'Brand New Career']));
    check('repeats in any capitals and blank lines are dropped (25 + 1 new = 26)', count($read($a)) === 26);
    check('the first spelling is the one kept', in_array('QA Career 3', $read($a), true) && !in_array('qa career 3', $read($a), true));

    echo "\n=== editing and removing ===\n";
    $edited = array_values(array_diff($careers(30), ['QA Career 2', 'QA Career 15', 'QA Career 30']));
    $edited[] = 'Added Later One';
    $edited[] = 'Added Later Two';
    $save($a, $edited);
    $now = $read($a);
    check('3 removed and 2 added: 29 careers', count($now) === 29);
    check('the removed careers are gone', !array_intersect(['QA Career 2', 'QA Career 15', 'QA Career 30'], $now));
    check('the added careers are there', in_array('Added Later One', $now, true) && in_array('Added Later Two', $now, true));
    $save($a, []);
    check('removing every career saves an empty list', $read($a) === []);

    echo "\n=== the same career in two programs ===\n";
    $save($a, ['Data Analyst', 'Shared Career']);
    $save($b, ['Shared Career', 'Data Analyst', 'Other']);
    check('both programs keep the shared careers', in_array('Shared Career', $read($a), true) && in_array('Shared Career', $read($b), true));

    echo "\n=== other programs are untouched ===\n";
    $after = $pdo->query('SELECT id, careers FROM programs ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    unset($after[$a], $after[$b]);
    check('no other program\'s careers changed', $after === $others);

    echo "\n=== CareerMatcher reads long lists ===\n";
    $save($a, $careers(150, 'Long List Career'));
    $programs = array_values(array_filter(CareerMatcher::loadPrograms($pdo), fn($p) => in_array($p['id'], [$a, $b], true)));
    check('the career at position 143 of 150 links to its program',
        CareerMatcher::resolve('Long List Career 143', $programs, null) === $a);
    check('a career only program B lists links to program B', CareerMatcher::resolve('Other', $programs, null) === $b);
} finally {
    $pdo->rollBack();
}

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
