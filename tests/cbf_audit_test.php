<?php
/**
 * CBF audit (2026-10-26): the scoring -> vector -> cosine -> ranking contract end to end, with the
 * edge cases the audit checked. Pure: no database, no real student records.
 *
 *   php tests/cbf_audit_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every profile and program below is synthetic.
 */
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/RecommendationPipeline.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/PredictionModel.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/QuestionBank.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/AssessmentVersion.php';

$passed = 0;
$failures = 0;
function check(string $label, bool $condition): void
{
    global $passed, $failures;
    $condition ? $passed++ : $failures++;
    echo ($condition ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}
function throws(callable $f): bool
{
    try { $f(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
/** A balanced synthetic question set: $n questions per type, ids from $firstId. */
function questionSet(int $n, int $firstId = 1): array
{
    $qs = [];
    $id = $firstId;
    foreach (QuestionBank::DIMENSIONS as $d) {
        for ($i = 1; $i <= $n; $i++) {
            $qs[] = ['id' => $id++, 'dimension' => $d, 'text' => "Synthetic $d item $i"];
        }
    }
    return $qs;
}
/** Answers for a set: every question of type $d gets $byType[$d]. */
function answersFor(array $questions, array $byType): array
{
    return array_map(fn($q) => ['questionId' => $q['id'], 'answer' => $byType[$q['dimension']]], $questions);
}

$programs = [
    ['id' => 1, 'title' => 'P-SIR', 'hollandCode' => 'SIR'],
    ['id' => 2, 'title' => 'P-IRS', 'hollandCode' => 'IRS'],
    ['id' => 3, 'title' => 'P-ISC', 'hollandCode' => 'ISC'],
    ['id' => 4, 'title' => 'P-AES', 'hollandCode' => 'AES'],
    ['id' => 5, 'title' => 'P-CEI', 'hollandCode' => 'CEI'],
];

echo "=== 1-2. Normal profile and dimension order ===\n";
$scores = ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25];
$S = CBFEngine::studentVector($scores);
check('stored scores / 10 in R,I,A,S,E,C order', $S === [3.0, 4.0, 2.0, 4.5, 1.5, 2.5]);
check('keys given in another order land in the same positions',
    CBFEngine::studentVector(array_reverse($scores, true)) === $S);
check('student vector keeps full precision (no internal rounding)',
    CBFEngine::studentVector(['R' => 33.33333, 'I' => 20, 'A' => 20, 'S' => 20, 'E' => 20, 'C' => 20])[0] === 33.33333 / 10);
check('all six numeric scores are used, not a top-3 0/1 vector',
    count(array_filter($S, fn($v) => $v != 0 && $v != 1)) === 6);

echo "\n=== 3. Program vector from a counselor-reviewed code ===\n";
check('SIR -> [1, 1, 0, 1, 0, 0]', CBFEngine::courseVector('SIR') === [1, 1, 0, 1, 0, 0]);
check('padded / lower-case code is normalised', CBFEngine::courseVector(' sir ') === [1, 1, 0, 1, 0, 0]);

echo "\n=== 4-8. Cosine similarity ===\n";
// Hand calculation: S·C = 3.0 + 4.0 + 4.5 = 11.5; ||S|| = sqrt(9 + 16 + 4 + 20.25 + 2.25 + 6.25) = sqrt(57.75);
// ||C|| = sqrt(3); cos = 11.5 / (sqrt(57.75) * sqrt(3)) = 0.873704...
$manual = 11.5 / (sqrt(57.75) * sqrt(3));
check('dot product = 11.5', CBFEngine::dotProduct($S, CBFEngine::courseVector('SIR')) === 11.5);
check('cosine equals the hand calculation to 1e-12', abs(CBFEngine::cosineSimilarity($S, CBFEngine::courseVector('SIR')) - $manual) < 1e-12);
check('identical nonzero vectors -> 1', abs(CBFEngine::cosineSimilarity($S, $S) - 1.0) < 1e-12);
check('orthogonal vectors -> 0', CBFEngine::cosineSimilarity([1, 1, 1, 0, 0, 0], [0, 0, 0, 1, 1, 1]) === 0.0);
check('zero student vector -> null (undefined), not 0', CBFEngine::cosineSimilarity([0, 0, 0, 0, 0, 0], [1, 1, 1, 0, 0, 0]) === null);
check('zero program vector -> null (undefined), not 0', CBFEngine::cosineSimilarity($S, [0, 0, 0, 0, 0, 0]) === null);
check('a stored profile can never be a zero vector (scores are at least 10)',
    CBFEngine::magnitude(CBFEngine::studentVector(array_fill_keys(CBFEngine::DIMENSIONS, 10))) > 0);

echo "\n=== 9. Missing, malformed or non-numeric scores ===\n";
$base = $scores;
check('missing score refused', throws(fn() => CBFEngine::studentVector(array_diff_key($base, ['C' => 0]))));
check('non-numeric score refused', throws(fn() => CBFEngine::studentVector(['C' => 'high'] + $base)));
check('NAN refused (passes no range check, so it needs its own check)', throws(fn() => CBFEngine::studentVector(['R' => NAN] + $base)));
check('INF refused', throws(fn() => CBFEngine::studentVector(['R' => INF] + $base)));
check('score below 10 refused', throws(fn() => CBFEngine::studentVector(['R' => 9] + $base)));
check('score above 50 refused', throws(fn() => CBFEngine::studentVector(['R' => 51] + $base)));
check('null / empty profile refused', throws(fn() => CBFEngine::studentVector(null)) && throws(fn() => CBFEngine::studentVector([])));
check('pipeline reports an invalid profile as unavailable instead of guessing',
    RecommendationPipeline::cbfRecommendation(['R' => NAN] + $base, $programs)['status'] === 'unavailable');
check('vectors of different length refused (was a silently wrong value)',
    throws(fn() => CBFEngine::cosineSimilarity([1, 2, 3], [1, 2, 3, 4, 5, 6])));
check('keyed vector refused (order would be ambiguous)',
    throws(fn() => CBFEngine::dotProduct(['R' => 1, 'I' => 1, 'A' => 1, 'S' => 0, 'E' => 0, 'C' => 0], [1, 1, 1, 0, 0, 0])));
check('non-numeric vector value refused', throws(fn() => CBFEngine::magnitude([1, 1, '1', 0, 0, 0])));
check('NAN vector value refused', throws(fn() => CBFEngine::magnitude([1, 1, NAN, 0, 0, 0])));

echo "\n=== 10. Invalid or missing program mappings ===\n";
$withBad = array_merge($programs, [
    ['id' => 6, 'title' => 'P-missing', 'hollandCode' => null],
    ['id' => 7, 'title' => 'P-repeat', 'hollandCode' => 'SSI'],
    ['id' => 8, 'title' => 'P-bad-letter', 'hollandCode' => 'SIX'],
    ['id' => 9, 'title' => 'P-padded', 'hollandCode' => ' sir '],
]);
$r = CBFEngine::compute($scores, $withBad);
check('invalid codes are excluded with a reason, never given a made-up vector',
    array_column($r['excluded'], 'id') === [6, 7, 8] && !array_filter($r['excluded'], fn($e) => $e['reason'] === ''));
$padded = array_values(array_filter($r['results'], fn($x) => $x['id'] === 9))[0];
check('a padded code is stored normalised (SIR, not " SIR ")', $padded['hollandCode'] === 'SIR');
check('a padded code gets the same 1-2-3 tie order as the clean code',
    $padded['letterOrder'] === CBFEngine::letterOrderMatch($scores, 'SIR') && $padded['letterOrder'] === [true, true, true]);
check('a padded code is in the same Best Match group as the clean code', in_array(9, $r['candidates'], true) && in_array(1, $r['candidates'], true));
check('no valid program -> empty result, no candidates', CBFEngine::compute($scores, array_slice($withBad, 5, 3))['candidates'] === []);

echo "\n=== 11. Equal similarities stay in one group ===\n";
$r = CBFEngine::compute($scores, $programs);
check('SIR and IRS (same letters) are both Best Match', $r['candidates'] === [1, 2]);
check('tied programs have exactly equal cosines', $r['results'][0]['cosine'] === $r['results'][1]['cosine']);
check('order inside the tie follows the 1-2-3 letter ranking (SIR before IRS), membership unchanged',
    array_column(array_slice($r['results'], 0, 2), 'id') === [1, 2]);
$flat = array_fill_keys(CBFEngine::DIMENSIONS, 30);
check('flat profile: every program tied at 1/sqrt(2), no artificial winner',
    CBFEngine::compute($flat, $programs)['candidates'] === [1, 2, 3, 4, 5]
    && abs(CBFEngine::cosineSimilarity(CBFEngine::studentVector($flat), [1, 1, 1, 0, 0, 0]) - M_SQRT1_2) < 1e-12);
check('student tie S = I does not favour either letter in the tie order',
    CBFEngine::letterOrderMatch(['R' => 30, 'I' => 45, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25], 'SIR')
    === CBFEngine::letterOrderMatch(['R' => 30, 'I' => 45, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25], 'ISR'));

echo "\n=== 12. Values that differ before rounding but display the same ===\n";
// Synthetic non-integer scores (stored scores are whole numbers; see the note in docs/methodology-notes.md §4).
$near = ['R' => 30.001, 'I' => 30, 'A' => 40, 'S' => 40, 'E' => 10, 'C' => 10];
$r = CBFEngine::compute($near, [['id' => 1, 'title' => 'IAS', 'hollandCode' => 'IAS'], ['id' => 2, 'title' => 'RAS', 'hollandCode' => 'RAS']]);
$cIAS = CBFEngine::cosineSimilarity(CBFEngine::studentVector($near), CBFEngine::courseVector('IAS'));
$cRAS = CBFEngine::cosineSimilarity(CBFEngine::studentVector($near), CBFEngine::courseVector('RAS'));
check('the two cosines differ at full precision', $cRAS > $cIAS && $cRAS - $cIAS > CBFEngine::TIE_EPSILON);
check('... and are equal after the 4-decimal display rounding', round($cRAS, 4) === round($cIAS, 4));
check('the ranking and Best Match use full precision, not the display value', $r['candidates'] === [2] && $r['alternatives'] === [1]);
check('rounded display value is what results carry', $r['results'][0]['cosine'] === round($cRAS, 4));
// With whole-number stored scores two different cosine levels are at least
// 0.1 / (||S|| * sqrt(3)) >= 0.1 / (sqrt(150) * sqrt(3)) apart, far more than the 4-decimal rounding step.
check('stored (whole-number) scores: distinct cosine levels are > 0.0047 apart, so rounding never merges two',
    0.1 / (sqrt(150) * sqrt(3)) > 0.0047);

echo "\n=== 13-15. Question changes, scoring bounds and history ===\n";
$five = questionSet(5);
$six = questionSet(6, 101);
$high = ['R' => 5, 'I' => 4, 'A' => 3, 'S' => 2, 'E' => 1, 'C' => 5];
$s5 = AssessmentVersion::score($five, answersFor($five, $high))['scores'];
$s6 = AssessmentVersion::score($six, answersFor($six, $high))['scores'];
check('5 per type: raw 25 -> 50, raw 5 -> 10', $s5['R'] === 50 && $s5['E'] === 10);
check('adding a question per type keeps the 10-50 bounds (raw 30 -> 50, raw 6 -> 10)', $s6['R'] === 50 && $s6['E'] === 10 && $s6 === $s5);
check('scores from either set are valid CBF input', !throws(fn() => CBFEngine::studentVector($s6)));
$uneven = $five;
$uneven[0]['dimension'] = 'I';   // a question reassigned from R to I: 4 R, 6 I
check('a reassigned question that unbalances the types is refused for scoring', throws(fn() => AssessmentVersion::score($uneven, answersFor($uneven, $high))));
$hidden = array_values(array_filter($five, fn($q) => !in_array($q['id'], [1, 6, 11, 16, 21, 26], true)));   // one hidden per type
check('hiding one question per type gives a new set (new fingerprint)', AssessmentVersion::fingerprint($hidden) !== AssessmentVersion::fingerprint($five));
check('answers to a hidden question are refused against the new set', throws(fn() => AssessmentVersion::score($hidden, answersFor($five, $high))));
check('the new set scores with its own count (4 per type, still 10-50)', AssessmentVersion::score($hidden, answersFor($hidden, $high))['scores'] === $s5);
$reworded = $five;
$reworded[0]['text'] = 'Reworded synthetic R item';
check('rewording a question also gives a new set', AssessmentVersion::fingerprint($reworded) !== AssessmentVersion::fingerprint($five));
$oldAnswers = answersFor($five, ['R' => 4, 'I' => 3, 'A' => 2, 'S' => 5, 'E' => 1, 'C' => 3]);
$stored = AssessmentVersion::score($five, $oldAnswers)['scores'];
check('an old attempt still re-scores to its stored scores against its own (old) set after the bank changed',
    AssessmentVersion::score(AssessmentVersion::snapshot($five), $oldAnswers)['scores'] === $stored);
check('the scoring version is recorded with every attempt', AssessmentVersion::SCORING_VERSION === 'riasec-10-50-v1');

echo "\n=== 16. WEKA/J48 inputs stay top-3 binary and separate ===\n";
$tie = ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 40];
check('J48 inputs: top three as 0/1 (S, I, C)', PredictionModel::binaryInputs($tie) === [0, 1, 0, 1, 0, 1]);
check('J48 tie for 3rd place keeps R,I,A,S,E,C order (R and I before C)',
    PredictionModel::binaryInputs(['R' => 40, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 40]) === [1, 1, 0, 1, 0, 0]);
check('J48 still refuses non-whole scores', throws(fn() => PredictionModel::binaryInputs(['R' => 30.5] + $tie)));
check('J48 refuses NAN like the CBF does', throws(fn() => PredictionModel::binaryInputs(['R' => NAN] + $tie)));
$model = PredictionModel::load();
if ($model !== null && ($model['format'] ?? null) === PredictionModel::LOOKUP_FORMAT) {
    $p = PredictionModel::predictBinary($model, $tie);
    check('installed model: output keeps its keys (inputs, predicted, distribution, ranked)',
        array_keys($p) === ['inputs', 'predicted', 'distribution', 'ranked'] && $p['inputs'] === [0, 1, 0, 1, 0, 1]);
    $off = ['decision_tree' => ['enabled' => false]] + CBFEngine::config();
    check('the CBF result is the same with the J48 model switched off',
        RecommendationPipeline::run(['riasec' => $tie], $programs, [])['cbf'] === RecommendationPipeline::run(['riasec' => $tie], $programs, [], $off)['cbf']);
} else {
    echo "  SKIP: no lookup-format model installed (config/prediction_model.json)\n";
}

echo "\n=== Program vectors from the configured (counselor-approved) codes ===\n";
$configured = require __DIR__ . '/../db/program_codes.php';
$ok = count($configured) === 32;
foreach ($configured as [, $t, , $final]) {
    $v = CBFEngine::courseVector($final);
    $ok = $ok && $v !== null && array_sum($v) === 3
        && array_keys(array_filter($v)) === array_keys(array_filter(array_map(fn($d) => str_contains($final, $d), CBFEngine::DIMENSIONS)));
}
check('all 32 configured codes give a 0/1 vector with exactly their three letters set', $ok);

echo "\n=== Program changes: what the CBF compares, and stale saved results ===\n";
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
$saved = fn(array $progs) => array_map(fn($x) => ['programId' => $x['id'], 'hollandCode' => $x['hollandCode']],
    CBFEngine::compute($scores, $progs)['results']);
$base = $saved($programs);
check('unchanged program list -> not stale', !CBFData::isStale($base, $programs));
check('saved order does not matter', !CBFData::isStale(array_reverse($base), $programs));
$added = array_merge($programs, [['id' => 6, 'title' => 'P-new', 'hollandCode' => 'SIA']]);
check('a program added since -> stale', CBFData::isStale($base, $added));
$deactivated = array_slice($programs, 1);   // activePrograms() no longer returns program 1
check('a program deactivated since -> stale', CBFData::isStale($base, $deactivated));
check('reactivated again (same list as saved) -> not stale', !CBFData::isStale($base, $programs));
$recoded = $programs;
$recoded[2]['hollandCode'] = 'IEC';
check('a Holland code changed since -> stale', CBFData::isStale($base, $recoded));
check('no Active program -> stale (the saved result lists programs no longer offered)', CBFData::isStale($base, []));
check('an Active program with an invalid code is not compared, so it does not make a result stale',
    !CBFData::isStale($base, array_merge($programs, [['id' => 7, 'title' => 'P-bad', 'hollandCode' => 'XYZ']])));
check('padded / lower-case codes compare as the CBF reads them',
    !CBFData::isStale($base, array_map(fn($p) => ['hollandCode' => ' ' . strtolower($p['hollandCode'])] + $p, $programs)));
$r = CBFEngine::compute($scores, $added);
check('added SIA program: compared and ranked with the rest', in_array(6, array_column($r['results'], 'id'), true));
$r = CBFEngine::compute($scores, $deactivated);
check('deactivating Best Match program 1: program 2 (same cosine) remains Best Match, others unchanged',
    $r['candidates'] === [2] && $r['alternatives'] === [3]);
check('deactivating changes no other program\'s cosine', array_column($r['results'], 'cosine', 'id')
    === array_diff_key(array_column(CBFEngine::compute($scores, $programs)['results'], 'cosine', 'id'), [1 => 0]));
check('re-coding ISC -> IEC changes only that program\'s cosine', (function () use ($scores, $programs, $recoded) {
    $a = array_column(CBFEngine::compute($scores, $programs)['results'], 'cosine', 'id');
    $b = array_column(CBFEngine::compute($scores, $recoded)['results'], 'cosine', 'id');
    return array_keys(array_diff_assoc($a, $b)) === [3];
})());
check('no Active program: empty result, nothing to save', CBFEngine::compute($scores, [])['results'] === []
    && RecommendationPipeline::run(['riasec' => $scores], [], [])['bestRiasecMatchIds'] === []);

echo "\n=== 15. Evaluation script (evaluation/cbf_eval.py --scale production) agrees with CBFEngine ===\n";
$python = null;
foreach (['python', 'python3'] as $cand) {
    exec(escapeshellarg($cand) . ' -c "import sys; print(sys.version_info[0])" 2>&1', $o, $code);
    if ($code === 0 && trim((string) end($o)) === '3') { $python = $cand; break; }
    $o = [];
}
if ($python === null) {
    echo "  SKIP: no Python 3 on PATH\n";
} else {
    mt_srand(26);
    $profiles = [];
    for ($i = 0; $i < 300; $i++) {
        $profiles[] = array_combine(CBFEngine::DIMENSIONS, array_map(fn() => mt_rand(10, 50), CBFEngine::DIMENSIONS));
    }
    $profiles[] = array_fill_keys(CBFEngine::DIMENSIONS, 10);
    $profiles[] = array_fill_keys(CBFEngine::DIMENSIONS, 50);
    $codes = array_values(array_unique(array_column($configured, 3)));
    $in = tempnam(sys_get_temp_dir(), 'cbfp');
    file_put_contents($in, json_encode(['profiles' => $profiles, 'codes' => $codes]));
    // A script file, not -c: Windows command-line quoting would strip the quotes inside it.
    $scriptFile = tempnam(sys_get_temp_dir(), 'cbfp') . '.py';
    file_put_contents($scriptFile, implode("\n", [
        'import json, sys',
        'sys.path.insert(0, sys.argv[2])',
        'import cbf_eval as m',
        'd = json.load(open(sys.argv[1]))',
        'print(json.dumps([[m.cosine(m.student_vector(dict(p, StudentID="x"), "production"), m.holland_to_vector(c))'
        . ' for c in d["codes"]] for p in d["profiles"]]))',
    ]));
    $py = json_decode((string) shell_exec(escapeshellarg($python) . ' ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($in) . ' '
        . escapeshellarg(realpath(__DIR__ . '/../evaluation'))), true);
    unlink($in);
    unlink($scriptFile);
    if (!is_array($py) || count($py) !== 302) {
        check('evaluation script produced cosines for every profile', false);
        $py = array_fill(0, 302, array_fill(0, 32, INF));
    }
    $maxDiff = 0.0;
    $groupsAgree = true;
    $progs = array_map(fn($c, $i) => ['id' => $i, 'title' => $c, 'hollandCode' => $c], $codes, array_keys($codes));
    foreach ($profiles as $i => $p) {
        $r = CBFEngine::compute($p, $progs);
        $php = [];
        foreach ($codes as $j => $c) {
            $php[$j] = CBFEngine::cosineSimilarity(CBFEngine::studentVector($p), CBFEngine::courseVector($c));
            $maxDiff = max($maxDiff, abs($php[$j] - ($py[$i][$j] ?? INF)));
        }
        $best = max($py[$i]);
        $pyBest = array_keys(array_filter($py[$i], fn($v) => abs($v - $best) < 1e-9));
        $phpBest = $r['candidates'];
        sort($pyBest);
        sort($phpBest);
        $groupsAgree = $groupsAgree && $pyBest === $phpBest;
    }
    check(count($profiles) . ' profiles x ' . count($codes) . " codes: cosines agree to 1e-12 (max difference $maxDiff)", is_array($py) && $maxDiff < 1e-12);
    check('Best RIASEC Match groups agree for every profile', is_array($py) && $groupsAgree);
}

echo "\n=== Requested synthetic profiles (all 32 configured programs) ===\n";
$all = array_map(fn($row, $i) => ['id' => $i + 1, 'title' => $row[1], 'hollandCode' => $row[3]], $configured, array_keys($configured));
$named = [
    'unique top 1-2-3 (S, I, R)'      => ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25],
    'tie for first (I = S)'           => ['R' => 30, 'I' => 45, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25],
    'tie at 3rd/4th (R = C)'          => ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 30],
    'all six equal'                   => array_fill_keys(CBFEngine::DIMENSIONS, 30),
    'all minimum (10)'                => array_fill_keys(CBFEngine::DIMENSIONS, 10),
    'all maximum (50)'                => array_fill_keys(CBFEngine::DIMENSIONS, 50),
    'low-scoring dominant type (E)'   => ['R' => 10, 'I' => 11, 'A' => 10, 'S' => 12, 'E' => 18, 'C' => 11],
];
foreach ($named as $label => $p) {
    $r = CBFEngine::compute($p, $all);
    $S = CBFEngine::studentVector($p);
    $exact = true;
    foreach ($r['results'] as $x) {
        $C = CBFEngine::courseVector($x['hollandCode']);
        $manualCos = array_sum(array_map(fn($a, $b) => $a * $b, $S, $C)) / (sqrt(array_sum(array_map(fn($a) => $a * $a, $S))) * sqrt(3));
        $exact = $exact && abs($x['cosine'] - round($manualCos, 4)) < 1e-12;
    }
    $sorted = array_column($r['results'], 'cosine');
    $desc = $sorted;
    rsort($desc);
    $desc = $desc === $sorted;
    $bestCos = array_unique(array_map(fn($id) => array_column($r['results'], 'cosine', 'id')[$id], $r['candidates']));
    check(sprintf('%-30s vector = stored scores / 10', $label), $S === array_map(fn($d) => (float) $p[$d] / 10, CBFEngine::DIMENSIONS));
    check(sprintf('%-30s all 32 cosines = hand formula; sorted high -> low', $label), $exact && $desc);
    check(sprintf('%-30s Best Match (%d program(s)) share one cosine', $label, count($r['candidates'])), count($bestCos) === 1);
}
$eq = CBFEngine::compute($named['all six equal'], $all);
check('all equal / all minimum / all maximum: every program tied at 0.7071 (same direction)',
    count($eq['candidates']) === 32 && CBFEngine::compute($named['all minimum (10)'], $all)['candidates'] === $eq['candidates']
    && CBFEngine::compute($named['all maximum (50)'], $all)['candidates'] === $eq['candidates'] && $eq['results'][0]['cosine'] === 0.7071);
$t1 = CBFEngine::compute($named['tie for first (I = S)'], $all);
check('tie for first: the CBF result does not depend on which tied letter is called "first"',
    $t1 === CBFEngine::compute(['I' => 45, 'S' => 45] + $named['tie for first (I = S)'], $all));

echo "\n=== 17. Result shape used by CBFData::saveRecommendation and the API ===\n";
$r = CBFEngine::compute($scores, $programs);
check('compute() keys unchanged', array_keys($r) === ['studentScores', 'studentVector', 'studentMagnitude', 'results', 'excluded', 'candidates', 'alternatives']);
check('each result keeps its keys', array_keys($r['results'][0]) === ['id', 'title', 'hollandCode', 'courseVector', 'dotProduct', 'courseMagnitude', 'cosine', 'letterOrder']);
check('studentScores are the six stored scores', $r['studentScores'] === $scores);
check('cosines are 0-1 similarity values, not percentages', !array_filter($r['results'], fn($x) => $x['cosine'] < 0 || $x['cosine'] > 1));
$pipe = RecommendationPipeline::run(['riasec' => $scores], $programs, [3], null, null);
check('pipeline: Preferred Course is reported separately and does not change Best Match',
    $pipe['bestRiasecMatchIds'] === [1, 2] && $pipe['preferred'][0] === ['id' => 3, 'inBestRiasecMatch' => false]);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures ? 1 : 0);
