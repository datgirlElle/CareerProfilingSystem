<?php
/**
 * Recommendation sources (lib/RecommendationPipeline.php): CBF, worksheet, prediction
 * model (unavailable); the Preferred Course never changes the CBF result.
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

$cbf = fn(array $best, array $alts = []) => ['status' => 'available', 'candidateIds' => $best, 'alternatives' => $alts];
$ws = fn(array $ids) => ['status' => $ids ? 'available' : 'empty', 'courseIds' => $ids];
$noPrediction = RecommendationPipeline::predictionRecommendation();
[$A, $B, $C, $D] = [11, 12, 13, 14];

echo "=== Prediction model source (WEKA): unavailable ===\n";
check('status unavailable, no course, no placeholder prediction', $noPrediction['status'] === 'unavailable' && $noPrediction['courseIds'] === []);
$src = file_get_contents(__DIR__ . '/../lib/RecommendationPipeline.php');
check('the recommendation code does not call the prediction model code', !str_contains($src, 'PredictionModel::') && !str_contains($src, "PredictionModel.php'"));

echo "\n=== Preferred Course is separate from the CBF result ===\n";
$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B], [$C]), $ws([$D]), $noPrediction);
check('Preferred Course D (not most similar) is NOT made the Best RIASEC Match', $r['bestRiasecMatchIds'] === [$A, $B]);
check('... and does not change the alternatives', $r['alternativeIds'] === [$C]);
check('... reported as differing from the Best RIASEC Match (mismatch)', $r['preferred'] === [['id' => $D, 'inBestRiasecMatch' => false]] && $r['reason'] === 'preferred_not_matched');

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B], [$C]), $ws([$B]), $noPrediction);
check('Preferred Course B that is most similar appears naturally in the Best RIASEC Match, list unchanged',
    $r['bestRiasecMatchIds'] === [$A, $B] && $r['preferred'][0]['inBestRiasecMatch'] === true && $r['status'] === 'match');
check('tied Best RIASEC Match courses are all kept (no tie-break by the preference)', count($r['bestRiasecMatchIds']) === 2);

$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B], [$C]), $ws([$C]), $noPrediction);
check('Preferred Course among the alternatives still differs from the Best RIASEC Match', $r['status'] === 'mismatch' && $r['bestRiasecMatchIds'] === [$A, $B]);

$r = RecommendationPipeline::finalRecommendation($cbf([$A], [$B]), $ws([]), $noPrediction);
check('empty worksheet result -> CBF result unchanged', $r['bestRiasecMatchIds'] === [$A] && $r['alternativeIds'] === [$B] && $r['reason'] === 'no_worksheet');

$r = RecommendationPipeline::finalRecommendation(['status' => 'unavailable', 'candidateIds' => []], $ws([$A]), $noPrediction);
check('CBF unavailable (invalid RIASEC profile) -> nothing recommended', $r['bestRiasecMatchIds'] === [] && $r['alternativeIds'] === [] && $r['reason'] === 'no_cbf_result');

$r = RecommendationPipeline::finalRecommendation($cbf([]), $ws([$A]), $noPrediction);
check('no CBF result -> mismatch (no_cbf_match)', $r['reason'] === 'no_cbf_match');

echo "\n=== Final Best Match not computed (prediction model unavailable) ===\n";
check('finalBestMatchIds is null and the result is incomplete', $r['finalBestMatchIds'] === null && $r['isComplete'] === false && $r['pendingSources'] === ['prediction']);
$r = RecommendationPipeline::finalRecommendation($cbf([$A, $B]), $ws([$A]), ['status' => 'available', 'courseIds' => [$B]]);
check('a future prediction result does not change anything yet', $r['bestRiasecMatchIds'] === [$A, $B] && $r['finalBestMatchIds'] === null);

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
// Development profile: C-I-E dominant (totals out of 50).
$profile = ['riasec' => ['R' => 15, 'I' => 40, 'A' => 12, 'S' => 18, 'E' => 35, 'C' => 45]];
$run = RecommendationPipeline::run($profile, $progs, [$idOf['BS Nursing']]);
check('all 32 programs compared, none excluded', count($run['cbf']['results']) === 32 && $run['cbf']['excluded'] === []);
check('Best RIASEC Match = the five programs coded with C, I and E (tied), Nursing not among them',
    count($run['bestRiasecMatchIds']) === 5 && in_array($idOf['BS Accountancy'], $run['bestRiasecMatchIds'], true)
    && !in_array($idOf['BS Nursing'], $run['bestRiasecMatchIds'], true));
check('Preferred Course BS Nursing shown as differing', $run['preferred'][0] === ['id' => $idOf['BS Nursing'], 'inBestRiasecMatch' => false]);
check('alternatives = the next similarity level, none of them a Best RIASEC Match',
    $run['alternativeIds'] && !array_intersect($run['alternativeIds'], $run['bestRiasecMatchIds']));
$withPref = RecommendationPipeline::run($profile, $progs, [$idOf['BS Accountancy']]);
check('the Preferred Course does not change the CBF result',
    $withPref['bestRiasecMatchIds'] === $run['bestRiasecMatchIds'] && $withPref['alternativeIds'] === $run['alternativeIds']
    && array_column($withPref['cbf']['results'], 'cosine') === array_column($run['cbf']['results'], 'cosine'));
check('prediction model reported unavailable', $run['prediction']['status'] === 'unavailable');
$run = RecommendationPipeline::run(['riasec' => null], $progs, [$idOf['BS Nursing']]);
check('no RIASEC result -> CBF unavailable with a reason, nothing recommended',
    $run['cbf']['status'] === 'unavailable' && $run['cbf']['reason'] !== null && $run['bestRiasecMatchIds'] === [] && $run['alternativeIds'] === []);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
