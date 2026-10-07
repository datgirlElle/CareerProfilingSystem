<?php
/**
 * Recommendation sources (lib/RecommendationPipeline.php): CBF, worksheet, prediction
 * model (unavailable) and the final Best Match / Alternative Courses.
 *
 *   php tests/pipeline_test.php
 *
 * DEVELOPMENT TEST DATA ONLY: made-up profiles and course ids, not research data.
 */
require_once __DIR__ . '/../lib/RecommendationPipeline.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}

$cbf = fn(array $ids) => ['status' => 'available', 'candidateIds' => $ids];
$ws = fn(array $ids) => ['status' => $ids ? 'available' : 'empty', 'courseIds' => $ids];
$noPrediction = RecommendationPipeline::predictionRecommendation();
[$A, $B, $C, $D] = [11, 12, 13, 14];

echo "=== Prediction model source (WEKA): unavailable ===\n";
check('status unavailable, no course, no placeholder prediction', $noPrediction['status'] === 'unavailable' && $noPrediction['courseIds'] === []);
$src = file_get_contents(__DIR__ . '/../lib/RecommendationPipeline.php');
check('the recommendation code does not call the prediction model code', !str_contains($src, 'PredictionModel::') && !str_contains($src, "PredictionModel.php'"));

echo "\n=== Final recommendation (CBF ∩ worksheet; prediction pending) ===\n";
$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B, $C]), $ws([$B]), $noPrediction);
check('Preferred Course among the CBF candidates -> it is the Best Match', $r['bestMatchIds'] === [$B] && $r['status'] === 'match');
check('Alternative Courses = the other CBF candidates, in order', $r['alternativeIds'] === [$A, $C]);
check('the Best Match is never repeated in the alternatives', !in_array($B, $r['alternativeIds'], true));
check('marked incomplete: prediction model pending', $r['isComplete'] === false && $r['pendingSources'] === ['prediction'] && $r['sourcesUsed'] === ['cbf', 'worksheet']);

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B]), $ws([$D]), $noPrediction);
check('Preferred Course not among the CBF candidates -> no Best Match (mismatch)', $r['bestMatchIds'] === [] && $r['reason'] === 'preferred_not_matched');
check('... all CBF candidates become the alternatives', $r['alternativeIds'] === [$A, $B]);
check('... Preferred Course reported as not in the CBF candidates', $r['preferred'] === [['id' => $D, 'inCbfCandidates' => false, 'isBestMatch' => false]]);

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B]), $ws([]), $noPrediction);
check('empty worksheet result -> CBF still works: candidates shown as alternatives, no Best Match',
    $r['bestMatchIds'] === [] && $r['alternativeIds'] === [$A, $B] && $r['reason'] === 'no_worksheet' && $r['sourcesUsed'] === ['cbf']);

$r = RecommendationPipeline::finalRecommendation(['status' => 'unavailable', 'candidateIds' => []], $ws([$A]), $noPrediction);
check('CBF unavailable (invalid RIASEC profile) -> nothing recommended', $r['bestMatchIds'] === [] && $r['alternativeIds'] === [] && $r['reason'] === 'no_cbf_result');

$r = RecommendationPipeline::finalRecommendation($cbf([]), $ws([$A]), $noPrediction);
check('no CBF candidate -> mismatch (no_cbf_match)', $r['reason'] === 'no_cbf_match');

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B, $C]), $ws([$C, $A]), $noPrediction);
check('several preferred courses: Best Match keeps CBF (program-list) order', $r['bestMatchIds'] === [$A, $C] && $r['alternativeIds'] === [$B]);

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B]), $ws([$A]), ['status' => 'available', 'courseIds' => [$B]]);
check('a future prediction result is not intersected yet (three-way intersection not executed)', $r['bestMatchIds'] === [$A] && $r['sourcesUsed'] === ['cbf', 'worksheet']);

echo "\n=== Worksheet source ===\n";
$programs = [['id' => 1, 'title' => 'x'], ['id' => 2, 'title' => 'y']];
check('Preferred Course that is an active program -> available', RecommendationPipeline::worksheetRecommendation([2], $programs) === ['status' => 'available', 'courseIds' => [2]]);
check('no Preferred Course -> empty', RecommendationPipeline::worksheetRecommendation([], $programs)['status'] === 'empty');
check('Preferred Course no longer active -> empty (not guessed)', RecommendationPipeline::worksheetRecommendation([99], $programs)['status'] === 'empty');

echo "\n=== Full run on the 32 mapped programs ===\n";
$progs = [];
foreach (require __DIR__ . '/../db/program_codes.php' as $i => [$college, $title, , $code]) {
    $progs[] = ['id' => $i + 1, 'title' => $title, 'hollandCode' => $code];
}
$idOf = array_column($progs, 'id', 'title');
$titleOf = array_column($progs, 'title', 'id');
// Development profile: C-I-E dominant (totals out of 50).
$profile = ['riasec' => ['R' => 15, 'I' => 40, 'A' => 12, 'S' => 18, 'E' => 35, 'C' => 45]];
$run = RecommendationPipeline::run($profile, $progs, [$idOf['BS Accountancy']]);
check('all 32 programs compared, none excluded', count($run['cbf']['results']) === 32 && $run['cbf']['excluded'] === []);
check('CBF candidates = the five programs coded with C, I and E',
    count($run['cbf']['candidateIds']) === 5 && in_array($idOf['BS Accountancy'], $run['cbf']['candidateIds'], true));
check('Preferred Course BS Accountancy (CEI) is the Best Match', $run['bestMatchIds'] === [$idOf['BS Accountancy']]);
check('four alternatives', count($run['alternativeIds']) === 4);
check('prediction model reported unavailable', $run['prediction']['status'] === 'unavailable');
$run = RecommendationPipeline::run($profile, $progs, [$idOf['BS Nursing']]);
check('Preferred Course BS Nursing (SIR) -> no Best Match, five alternatives', $run['bestMatchIds'] === [] && count($run['alternativeIds']) === 5);
$run = RecommendationPipeline::run(['riasec' => null], $progs, [$idOf['BS Nursing']]);
check('no RIASEC result -> CBF unavailable with a reason, nothing recommended',
    $run['cbf']['status'] === 'unavailable' && $run['cbf']['reason'] !== null && $run['bestMatchIds'] === [] && $run['alternativeIds'] === []);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
