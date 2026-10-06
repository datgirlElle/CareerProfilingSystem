<?php

require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';

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

function approx(float $a, float $b, float $eps = 0.0001): bool
{
    return abs($a - $b) < $eps;
}

// Fixed test configuration: the real config/cbf.php vocabularies, with weights
// and flags pinned so these tests don't change when the thesis weights do.
$base = require __DIR__ . '/../config/cbf.php';
$cfg = array_merge($base, [
    'weights' => ['riasec' => 1.0, 'strand' => 1.0, 'electives' => 1.0],
    'final_score' => ['similarity' => 0.70, 'stated_program' => 0.30],
    'riasec_subtract_floor' => false,
    // Engine-mechanics tests below use the rank-preserving encoding so their
    // hand-computed values stay fixed; production (binary) is tested at the end.
    'holland_rank_weights' => [1.00, 0.67, 0.33],
    'student_vector' => 'scores',
    'match_rule' => 'top_n',
]);
$W = $cfg['holland_rank_weights'];
$ccis = $base['college_electives']['CCIS'];
$cas = $base['college_electives']['CAS'];

echo "=== hollandCodeToVector, order R,I,A,S,E,C ===\n";
check('binary (production): IRC -> [1,1,0,0,0,1]', CBFEngine::hollandCodeToVector('IRC') === [1, 1, 0, 0, 0, 1]);
check('binary (production): AES -> [0,0,1,1,1,0]', CBFEngine::hollandCodeToVector('AES') === [0, 0, 1, 1, 1, 0]);
check('binary ignores letter order: IRC == RIC == ICR', CBFEngine::hollandCodeToVector('IRC') === CBFEngine::hollandCodeToVector('RIC')
    && CBFEngine::hollandCodeToVector('RIC') === CBFEngine::hollandCodeToVector('ICR'));
check('rank-preserving: IRC -> [0.67,1,0,0,0,0.33]', CBFEngine::hollandCodeToVector('IRC', $W) === [0.67, 1.00, 0, 0, 0, 0.33]);
check('rank-preserving: RIC -> [1,0.67,0,0,0,0.33]', CBFEngine::hollandCodeToVector('RIC', $W) === [1.00, 0.67, 0, 0, 0, 0.33]);
check('rank-preserving: AES -> [0,0,1,0.33,0.67,0]', CBFEngine::hollandCodeToVector('AES', $W) === [0, 0, 1.00, 0.33, 0.67, 0]);
check('legacy 3/2/1 still available when passed explicitly', CBFEngine::hollandCodeToVector('SEC', [3, 2, 1]) === [0, 0, 0, 3, 2, 1]);

echo "\n=== cosineSimilarity: hand-computed known vectors ===\n";
// Student scored 50 on Realistic and 0 elsewhere. Program "RIC" -> [1,0.67,0,0,0,0.33].
// dot = 50*1 = 50; |studentVec| = 50; |programVec| = sqrt(1 + 0.67^2 + 0.33^2) = 1.24816...
// cosine = 50 / (50 * 1.24816) = 0.80118...
$studentVec = CBFEngine::scoresToVector(['R' => 50, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0]);
$programVecRIC = CBFEngine::hollandCodeToVector('RIC', $W);
$cosineRIC = CBFEngine::cosineSimilarity($studentVec, $programVecRIC);
check('pure-Realistic student vs RIC program ~= 0.8012', approx($cosineRIC, 1 / sqrt(1 + 0.67 ** 2 + 0.33 ** 2)));
check('pure-Realistic student vs SEC program == 0', CBFEngine::cosineSimilarity($studentVec, CBFEngine::hollandCodeToVector('SEC', $W)) === 0.0);
check('identical vectors cosine == 1', approx(CBFEngine::cosineSimilarity($programVecRIC, $programVecRIC), 1.0));
check('zero vector cosine == 0 (no div-by-zero)', CBFEngine::cosineSimilarity([0, 0, 0, 0, 0, 0], $programVecRIC) === 0.0);
check('works for any vector length', approx(CBFEngine::cosineSimilarity([1, 0, 1, 0], [1, 0, 0, 0]), 1 / sqrt(2)));

echo "\n=== Stated-program bonus formula (0.70/0.30, configurable) ===\n";
// No strand/electives on either side -> those blocks are all zero, so the overall
// cosine equals the RIASEC cosine and Final = 0.70*cosine + 0.30*indicator.
$programs = [
    ['id' => 1, 'hollandCode' => 'RIC'],
    ['id' => 2, 'hollandCode' => 'SEC'], // zero RIASEC overlap
    ['id' => 3, 'hollandCode' => 'RCI'],
    ['id' => 4, 'hollandCode' => 'RSA'],
];
$pureR = ['riasec' => ['R' => 50, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0]];
$rPrimaryCosine = 1 / sqrt(1 + 0.67 ** 2 + 0.33 ** 2); // shared by programs 1, 3, and 4

$result = CBFEngine::recommend($pureR, $programs, 1, $cfg);
check('stated program (id=1) final score == 0.70*cosine + 0.30', $result['top3'][0]['id'] === 1 && approx($result['top3'][0]['score'], round(0.70 * $rPrimaryCosine + 0.30, 4)));
check('non-stated program never gets the +0.30 indicator', $result['all'][1]['indicator'] === 0.0 && $result['all'][2]['indicator'] === 0.0);
check('top3 has exactly 3 entries', count($result['top3']) === 3);
check('statedOutsideTop3 is null when the stated program IS in the top 3', $result['statedOutsideTop3'] === null);

$resultNoWorksheet = CBFEngine::recommend($pureR, $programs, null, $cfg);
check('no stated program -> top pick is an R-primary program', in_array($resultNoWorksheet['top3'][0]['id'], [1, 3, 4], true));
check('no stated program -> top score == 0.70*cosine', approx($resultNoWorksheet['top3'][0]['score'], round(0.70 * $rPrimaryCosine, 4)));

$resultBadStated = CBFEngine::recommend($pureR, $programs, 2, $cfg);
check('stated-but-poor-fit program falls outside the top 3', !in_array(2, array_column($resultBadStated['top3'], 'id'), true));
check('statedOutsideTop3 surfaces program id=2 with score 0.30', $resultBadStated['statedOutsideTop3']['id'] === 2 && approx($resultBadStated['statedOutsideTop3']['score'], 0.30));

echo "\n=== Multi-attribute profiles (RIASEC + strand + electives) ===\n";
// Hypothetical test fixtures (not real students or real strand mappings).
$stemStudent = [
    'riasec' => ['R' => 38, 'I' => 45, 'A' => 18, 'S' => 20, 'E' => 22, 'C' => 35],
    'strand' => 'STEM',
    'electives' => ['Computer Programming (JAVA)', 'Research Methods', 'Design and Innovation'],
];
$fixturePrograms = [
    ['id' => 10, 'hollandCode' => 'IRC', 'relatedStrands' => ['STEM', 'ICT'], 'collegeCode' => 'CCIS'], // strong
    ['id' => 11, 'hollandCode' => 'IRC', 'relatedStrands' => ['ABM'], 'collegeCode' => 'ETYCB'],        // RIASEC only
    ['id' => 12, 'hollandCode' => 'AES', 'relatedStrands' => ['HUMSS'], 'collegeCode' => 'CAS'],        // very different
];
$multi = CBFEngine::recommend($stemStudent, $fixturePrograms, null, $cfg);
$byId = array_column($multi['all'], null, 'id');

// 1. Strong match
check('1. strong match ranks first', $multi['all'][0]['id'] === 10);
check('1. strong match: all three blocks matched', $byId[10]['matches']['strand'] === ['STEM']
    && $byId[10]['matches']['riasec'] === ['Investigative', 'Realistic', 'Conventional']
    && $byId[10]['matches']['electives'] === ['Computer Programming (JAVA)', 'Research Methods', 'Design and Innovation']);
check('1. explanation names the real matching features',
    str_contains($byId[10]['explanation'], 'STEM strand') && str_contains($byId[10]['explanation'], 'Investigative')
    && str_contains($byId[10]['explanation'], 'Computer Programming (JAVA)'));

// 2. Partial match: same RIASEC fit, different strand and college.
check('2. partial match scores between strong and very different', $byId[10]['cosine'] > $byId[11]['cosine'] && $byId[11]['cosine'] > $byId[12]['cosine']);
check('2. partial match: strand and electives blocks are 0', $byId[11]['blocks']['strand']['cosine'] === 0.0 && $byId[11]['blocks']['electives']['cosine'] === 0.0);
check('2. partial match: only RIASEC listed as matching', $byId[11]['matches']['strand'] === [] && $byId[11]['matches']['electives'] === [] && $byId[11]['matches']['riasec'] !== []);

// 3. Very different
check('3. very different program has the lowest similarity', end($multi['all'])['id'] === 12);
check('3. very different program: no strand/elective match', $byId[12]['matches']['strand'] === [] && $byId[12]['matches']['electives'] === []);

// Identity: with every block filled on both sides, cosine == Σ weight_k * cosine_k.
$weighted = array_sum(array_map(fn($b) => $b['weight'] * $b['cosine'], $byId[10]['blocks']));
check('all blocks present -> cosine == Σ weight*blockCosine', approx($byId[10]['cosine'], $weighted, 0.0005));
foreach ($multi['all'] as $entry) {
    $sum = array_sum(array_column($entry['blocks'], 'contribution'));
    if (!approx($sum, $entry['cosine'], 0.0005)) {
        check("contributions sum to cosine for program {$entry['id']}", false);
    }
}
check('block contributions always sum to the overall cosine', true);

// 4. Missing optional student information (no strand, no electives)
$riasecOnly = ['riasec' => $stemStudent['riasec'], 'strand' => null, 'electives' => []];
$missing = CBFEngine::recommend($riasecOnly, $fixturePrograms, null, $cfg);
$missingById = array_column($missing['all'], null, 'id');
$riasecCos = CBFEngine::cosineSimilarity(CBFEngine::scoresToVector($stemStudent['riasec']), CBFEngine::hollandCodeToVector('IRC', $W));
// ||A||^2 = w_riasec = 1/3, ||B||^2 = 1 -> cosine = (1/3 * riasecCos) / sqrt(1/3) = riasecCos / sqrt(3)
check('4. missing strand/electives: no error, cosine == riasecCos/sqrt(3)', approx($missingById[10]['cosine'], $riasecCos / sqrt(3)));
check('4. missing strand/electives: those blocks contribute 0', $missingById[10]['blocks']['strand']['contribution'] === 0.0 && $missingById[10]['blocks']['electives']['contribution'] === 0.0);
check('4. programs 10 and 11 tie on RIASEC -> tie broken by lower id', $missing['all'][0]['id'] === 10 && $missing['all'][1]['id'] === 11);

// 5. Program with missing optional attributes (no related strands, unknown college)
$bare = CBFEngine::recommend($stemStudent, [['id' => 20, 'hollandCode' => 'IRC', 'relatedStrands' => [], 'collegeCode' => 'XYZ']], null, $cfg)['all'][0];
check('5. program without strands/electives: finite score, no error', is_finite($bare['cosine']) && $bare['cosine'] > 0);
check('5. program without strands/electives: only RIASEC contributes', $bare['blocks']['strand']['contribution'] === 0.0 && $bare['blocks']['electives']['contribution'] === 0.0 && $bare['blocks']['riasec']['contribution'] > 0);

// 6. Zero-magnitude student vector (nothing filled in)
$empty = CBFEngine::recommend(['riasec' => null, 'strand' => null, 'electives' => []], $fixturePrograms, null, $cfg);
check('6. zero-magnitude student: every cosine == 0, no NaN', array_sum(array_column($empty['all'], 'cosine')) === 0.0);
check('6. zero-magnitude student: still returns a deterministic ranking', array_column($empty['all'], 'id') === [10, 11, 12]);
check('6. zero-magnitude student: explanation says low match', str_contains($empty['all'][0]['explanation'], 'shares none'));

// Identical profiles -> cosine 1
$mirror = [
    'riasec' => ['R' => 30, 'I' => 20, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 10], // proportional to RIC = [3,2,0,0,0,1]
    'strand' => 'STEM',
    'electives' => $ccis,
];
$same = CBFEngine::recommend($mirror, [['id' => 30, 'hollandCode' => 'RIC', 'relatedStrands' => ['STEM'], 'collegeCode' => 'CCIS']], null, $cfg)['all'][0];
check('identical student and program profiles -> cosine == 1', approx($same['cosine'], 1.0));

// Very low similarity
$artsy = ['riasec' => ['R' => 10, 'I' => 10, 'A' => 48, 'S' => 45, 'E' => 40, 'C' => 10], 'strand' => 'HUMSS', 'electives' => $cas];
$low = CBFEngine::recommend($artsy, [['id' => 40, 'hollandCode' => 'RIC', 'relatedStrands' => ['STEM'], 'collegeCode' => 'MITL']], null, $cfg)['all'][0];
check('very low similarity stays low (< 0.2)', $low['cosine'] < 0.2);

// Multiple programs with the same score
$twins = CBFEngine::recommend($stemStudent, [
    ['id' => 51, 'hollandCode' => 'IRC', 'relatedStrands' => ['STEM'], 'collegeCode' => 'CCIS'],
    ['id' => 50, 'hollandCode' => 'IRC', 'relatedStrands' => ['STEM'], 'collegeCode' => 'CCIS'],
], null, $cfg)['all'];
check('equal-profile programs get equal scores', $twins[0]['score'] === $twins[1]['score']);
check('equal scores ordered by lower program id', $twins[0]['id'] === 50);

echo "\n=== Configuration ===\n";
$riasecOnlyCfg = array_merge($cfg, ['weights' => ['riasec' => 1.0, 'strand' => 0, 'electives' => 0]]);
$r = CBFEngine::recommend($stemStudent, $fixturePrograms, null, $riasecOnlyCfg);
check('weight 0 removes a block from the vectors and the breakdown', array_keys($r['all'][0]['blocks']) === ['riasec']);
check('weight 0 block is not reported as a match', $r['all'][0]['matches']['strand'] === [] && $r['all'][0]['matches']['electives'] === []);
check('weights are rescaled to sum to 1', approx(array_sum(CBFEngine::normalizedWeights(array_merge($cfg, ['weights' => ['riasec' => 6, 'strand' => 3, 'electives' => 1]]))), 1.0));

$floorCfg = array_merge($cfg, ['riasec_subtract_floor' => true]);
$scores = ['R' => 10, 'I' => 50, 'A' => 25, 'S' => 10, 'E' => 10, 'C' => 10];
check('default scaling: score / 50 -> 0..1', CBFEngine::studentFeatures(['riasec' => $scores], $cfg)['riasec'] === [0.2, 1.0, 0.5, 0.2, 0.2, 0.2]);
check('riasec_subtract_floor: (score - 10) / 40 -> 0..1', CBFEngine::studentFeatures(['riasec' => $scores], $floorCfg)['riasec'] === [0.0, 1.0, 0.375, 0.0, 0.0, 0.0]);
check('dividing by 50 does not change cosine', approx(
    CBFEngine::cosineSimilarity(CBFEngine::studentFeatures(['riasec' => $scores], $cfg)['riasec'], $programVecRIC),
    CBFEngine::cosineSimilarity(CBFEngine::scoresToVector($scores), $programVecRIC)));

echo "\n=== Production configuration (config/cbf.php) matches the methodology ===\n";
$prod = CBFEngine::config();
check('only the RIASEC block is used', CBFEngine::normalizedWeights($prod) === ['riasec' => 1.0]);
check('ranking is by cosine alone (no stated-program bonus)', $prod['final_score'] == ['similarity' => 1.0, 'stated_program' => 0.0]);
check('encoding is binary (1/1/1: letter present = 1, absent = 0)', $prod['holland_rank_weights'] === [1, 1, 1]);
check('mapped courses = every program tied at the highest similarity', $prod['match_rule'] === 'highest');
check('student vector = top-3 binary', $prod['student_vector'] === 'top_binary' && $prod['student_top_n'] === 3);
check('top-3 letters: C, I, E -> x under C, I, E (adviser sample)',
    CBFEngine::studentFeatures(['riasec' => ['R' => 36, 'I' => 39, 'A' => 37, 'S' => 32, 'E' => 39, 'C' => 41]])['riasec'] === [0, 1, 0, 0, 1, 1]);
check('ties for 3rd place keep R,I,A,S,E,C order', CBFEngine::topRiasecLetters(['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30]) === ['R', 'I', 'A']);
check('no assessment -> zero student vector', CBFEngine::studentFeatures(['riasec' => null])['riasec'] === [0, 0, 0, 0, 0, 0]);

// S001 example from evaluation/METHODOLOGY.md (scores already on a 0-1 scale -> x50 = raw totals),
// computed with the production setup: top-3 binary student (I, C, R) vs binary course letters,
// so cosine = matching letters / 3.
$s001 = ['riasec' => ['R' => 36, 'I' => 45.5, 'A' => 20, 'S' => 17.5, 'E' => 30, 'C' => 39], 'strand' => 'STEM', 'electives' => ['Animation']];
$catalog = [
    ['id' => 1, 'hollandCode' => 'IRC', 'relatedStrands' => [], 'collegeCode' => 'CCIS'],
    ['id' => 2, 'hollandCode' => 'RIC', 'relatedStrands' => ['STEM'], 'collegeCode' => 'MITL'],
    ['id' => 3, 'hollandCode' => 'AES', 'relatedStrands' => [], 'collegeCode' => 'CAS'],
    ['id' => 4, 'hollandCode' => 'ICR', 'relatedStrands' => [], 'collegeCode' => 'MITL'],
    ['id' => 5, 'hollandCode' => 'SEA', 'relatedStrands' => [], 'collegeCode' => 'ETYCB'],
];
$r = CBFEngine::recommend($s001, $catalog, 3);
$byId = array_column($r['all'], null, 'id');
check('S001: IRC = RIC = ICR = 1 (3 of 3 letters), AES = SEA = 0 (no letters)',
    $byId[1]['cosine'] === 1.0 && $byId[2]['cosine'] === 1.0 && $byId[4]['cosine'] === 1.0
    && $byId[3]['cosine'] === 0.0 && $byId[5]['cosine'] === 0.0);
check('one matching letter -> 0.3333 (EIS shares only I)', CBFEngine::recommend($s001, [['id' => 9, 'hollandCode' => 'EIS']], null)['all'][0]['cosine'] === 0.3333);
check('score == cosine for every course, including the stated one', array_reduce($r['all'], fn($ok, $e) => $ok && $e['score'] === $e['cosine'], true));
check('tied courses ranked by lower id: IRC(1), RIC(2), ICR(4)', array_column($r['top3'], 'id') === [1, 2, 4]);
check('stated low-fit program (AES) is NOT pushed into the Top-N', $r['statedOutsideTop3']['id'] === 3);
check('low-match explanation names only RIASEC', CBFEngine::explain(['strand' => [], 'riasec' => [], 'electives' => []]) === 'This program shares none of your top RIASEC types, so its match is low.');
// Adviser's sample student (top 3 = C, I, E) against all 32 programs.
$all = [];
foreach (require __DIR__ . '/../db/program_codes.php' as $i => [$c, $t, $o, $f]) {
    $all[] = ['id' => $i + 1, 'title' => $t, 'hollandCode' => $f, 'collegeCode' => $c];
}
$cie = CBFEngine::recommend(['riasec' => ['R' => 36, 'I' => 39, 'A' => 37, 'S' => 32, 'E' => 39, 'C' => 41]], $all, null);
check('C-I-E student: all 5 programs sharing C, I, E are matches (no cut at 3)',
    array_column($cie['top3'], 'hollandCode') === ['CEI', 'CIE', 'ECI', 'EIC', 'IEC']);
$icr = CBFEngine::recommend(['riasec' => ['R' => 36, 'I' => 45, 'A' => 20, 'S' => 17, 'E' => 30, 'C' => 39]], $all, null);
check('I-C-R student: all 11 I/R/C programs incl. Marine Engineering, across colleges',
    count($icr['top3']) === 11 && in_array('BS Marine Engineering', array_column($icr['top3'], 'title'), true)
    && count(array_unique(array_column($icr['top3'], 'collegeCode'))) >= 4);
check('same-code programs appear together (BSCS and BSIT)',
    count(array_intersect(['BS Computer Science', 'BS Information Technology'], array_column($icr['top3'], 'title'))) === 2);
check('no assessment -> no mapped courses (refer to guidance)', CBFEngine::recommend(['riasec' => null], $all, null)['top3'] === []);
check('selectMatches keeps only entries tied at the best score',
    array_column(CBFEngine::selectMatches([['id' => 1, 'score' => 1.0], ['id' => 2, 'score' => 1.0], ['id' => 3, 'score' => 0.6667]]), 'id') === [1, 2]);
check('strand and electives do not affect the ranking', $byId[2]['blocks'] === ['riasec' => $byId[2]['blocks']['riasec']]);

echo "\n=== Postgres text[] parsing ===\n";
check('bare and quoted elements are both read', CBFData::parseTextArray('{Animation,"Biology 1-2",Entrepreneurship}') === ['Animation', 'Biology 1-2', 'Entrepreneurship']);
check('escaped quotes/backslashes are unescaped', CBFData::parseTextArray('{"a \\"b\\"","c\\\\d"}') === ['a "b"', 'c\\d']);
check('empty array -> []', CBFData::parseTextArray('{}') === [] && CBFData::parseTextArray(null) === []);
check('literal round-trips through the parser', CBFData::parseTextArray(CBFData::textArrayLiteral(['Physics 1-2', 'Animation'])) === ['Physics 1-2', 'Animation']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
