<?php
// Independent verification of RIASEC scaling and CBF cosine values.
// Expected numbers are written out by hand (not produced by the engine under test).
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/QuestionBank.php';

$pass = 0; $fail = 0;
function check(string $l, bool $c): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n"; }
function near(float $a, float $b, float $e = 1e-4): bool { return abs($a - $b) < $e; }

echo "=== Scaling: scaled = raw/25*50 = 2*raw, for raw 5..25 ===\n";
$ok = true;
for ($raw = 5; $raw <= 25; $raw++) { if (QuestionBank::scaleScore($raw, 5) !== 2 * $raw) { $ok = false; } }
check('every raw 5..25 (5 items) maps to exactly 2 x raw (10..50)', $ok);
check('minimum 5 -> 10, maximum 25 -> 50', QuestionBank::scaleScore(5, 5) === 10 && QuestionBank::scaleScore(25, 5) === 50);
check('zero items in a type gives 0, not a crash', QuestionBank::scaleScore(0, 0) === 0);

echo "\n=== Student vector = scaled / 10, order R,I,A,S,E,C ===\n";
$v = CBFEngine::studentVector(['R' => 10, 'I' => 20, 'A' => 30, 'S' => 40, 'E' => 50, 'C' => 24]);
check('vector [1,2,3,4,5,2.4]', $v === [1.0, 2.0, 3.0, 4.0, 5.0, 2.4]);
foreach ([['R' => 8], ['R' => 52], ['R' => 'x']] as $i => $bad) {
    $s = ['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30]; $s = $bad + $s;
    $thrown = false; try { CBFEngine::studentVector($s); } catch (InvalidArgumentException $e) { $thrown = true; }
    check("invalid score case $i rejected (no substitution)", $thrown);
}
$thrown = false; try { CBFEngine::studentVector(['R' => 30]); } catch (InvalidArgumentException $e) { $thrown = true; }
check('missing types rejected', $thrown);

echo "\n=== Course vectors ===\n";
check('AES -> [0,0,3,1,2,0] (rank weights 3-2-1: A 3, E 2, S 1)', CBFEngine::courseVector('AES') === [0, 0, 3, 1, 2, 0]);
check('lowercase/space tolerated: RIS -> [3,2,0,1,0,0]', CBFEngine::courseVector(' ris ') === [3, 2, 0, 1, 0, 0]);
foreach (['', null, 'RR', 'RIAS', 'RRI', 'XYZ', 'R1A'] as $bad) {
    check('invalid code ' . var_export($bad, true) . ' -> null', CBFEngine::courseVector($bad) === null);
}

echo "\n=== Cosine against hand-computed values ===\n";
// Student [5,1,1,1,1,1] vs RIA [1,1,1,0,0,0]: dot=7, |S|=sqrt(30), |C|=sqrt(3) -> 7/sqrt(90)=0.73786
check('cos [5,1,1,1,1,1] vs RIA = 0.7379', near(CBFEngine::cosineSimilarity([5,1,1,1,1,1], [1,1,1,0,0,0]), 7 / sqrt(90)));
check('cos 0.7379 literal', near(CBFEngine::cosineSimilarity([5,1,1,1,1,1], [1,1,1,0,0,0]), 0.7379));
// uniform student vs any 3-letter code: 3a/(a*sqrt6*sqrt3)=3/sqrt18=0.7071
check('uniform profile vs any code = 0.7071', near(CBFEngine::cosineSimilarity([3,3,3,3,3,3], [0,1,1,0,1,0]), 0.70711));
check('identical direction = 1', near(CBFEngine::cosineSimilarity([2,2,2,0,0,0], [1,1,1,0,0,0]), 1.0));
check('orthogonal = 0', near(CBFEngine::cosineSimilarity([1,1,1,0,0,0], [0,0,0,1,1,1]), 0.0));
check('zero vector -> null, not 0', CBFEngine::cosineSimilarity([0,0,0,0,0,0], [1,1,1,0,0,0]) === null);

echo "\n=== compute(): selection rules ===\n";
$programs = [
    ['id' => 1, 'title' => 'P1', 'hollandCode' => 'RIA'],
    ['id' => 2, 'title' => 'P2', 'hollandCode' => 'RIA'],   // same code as P1 -> equal cosine
    ['id' => 3, 'title' => 'P3', 'hollandCode' => 'SEC'],
    ['id' => 4, 'title' => 'P4', 'hollandCode' => 'RIS'],
    ['id' => 5, 'title' => 'Bad', 'hollandCode' => null],
];
$res = CBFEngine::compute(['R' => 50, 'I' => 40, 'A' => 30, 'S' => 20, 'E' => 10, 'C' => 10], $programs);
$byId = []; foreach ($res['results'] as $r) { $byId[$r['id']] = $r['cosine']; }
check('P1 and P2 equal', $byId[1] === $byId[2]);
// S=[5,4,3,2,1,1], |S|=sqrt56, |C|=sqrt14 (weights 3,2,1) so |S||C|=sqrt784=28:
// RIA = [3,2,1,0,0,0] dot=15+8+3=26 -> 0.9286 ; RIS = [3,2,0,1,0,0] dot=15+8+2=25 -> 0.8929 ; SEC = [0,0,0,3,2,1] dot=6+2+1=9 -> 0.3214
check('P1 = 26/28 = 0.9286', near($byId[1], 0.9286));
check('P4 = 25/28 = 0.8929', near($byId[4], 0.8929));
check('P3 = 9/28 = 0.3214', near($byId[3], 0.3214));
check('Best match = tied P1,P2 (ids 1,2)', $res['candidates'] === [1, 2]);
check('Alternatives = next level (P4)', $res['alternatives'] === [4]);
check('invalid-code program excluded, not scored', count($res['excluded']) === 1 && $res['excluded'][0]['id'] === 5);
$empty = CBFEngine::compute(['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30], []);
check('empty program list handled safely', $empty['results'] === [] && $empty['candidates'] === []);

echo "\n=== RIASEC code tie-breaking (documented: ties keep R,I,A,S,E,C order) ===\n";
check('all tied -> RIA', CBFEngine::topRiasecLetters(['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30]) === ['R', 'I', 'A']);
check('distinct -> highest first', CBFEngine::topRiasecLetters(['R' => 10, 'I' => 20, 'A' => 30, 'S' => 40, 'E' => 50, 'C' => 24]) === ['E', 'S', 'A']);
check('tie at 3rd place resolved by letter order', CBFEngine::topRiasecLetters(['R' => 10, 'I' => 40, 'A' => 30, 'S' => 30, 'E' => 50, 'C' => 20]) === ['E', 'I', 'A']);

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
