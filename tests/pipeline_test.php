<?php
/**
 * Recommendation pipeline (lib/RecommendationPipeline.php) and prediction model
 * (lib/PredictionModel.php).
 *
 *   php tests/pipeline_test.php
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

$prod = CBFEngine::config();
$on = array_replace_recursive($prod, ['decision_tree' => ['enabled' => true]]);
[$A, $B, $C, $D] = [11, 12, 13, 14];

echo "=== Configuration ===\n";
check('mismatch: final = no common course, interim = preferred not matched; model not yet enabled',
    $prod['mismatch'] == ['definition' => 'no_common_course', 'interim' => 'preferred_not_matched'] && $prod['decision_tree']['enabled'] === false);

echo "\n=== Adviser cases (prediction model enabled) ===\n";
$r = RecommendationPipeline::combine([$A, $B, $C], [$A, $B, $D], [], $on);
check('Case 1: CBF {A,B,C} ∩ prediction {A,B,D} -> Top Matches {A,B}', $r['finalIds'] === [$A, $B] && $r['commonIds'] === [$A, $B]);
check('Case 1: common courses -> match, no fallback', $r['status'] === 'match' && !$r['usedFallback']);

$r = RecommendationPipeline::combine([$A, $B], [$C, $D], [], $on);
check('Case 2: no common course -> mismatch (no_common_course)', $r['status'] === 'mismatch' && $r['reason'] === 'no_common_course');
check('Case 2: fallback -> Top Matches According to the Guidance = CBF matches (never empty)', $r['finalIds'] === [$A, $B] && $r['usedFallback'] && $r['commonIds'] === []);

$r = RecommendationPipeline::combine([$A, $B, $C], [$A, $B], [$B], $on);
check('Case 3: preferred B already in Top Matches -> list unchanged, no duplicate', $r['finalIds'] === [$A, $B] && $r['preferred'] === [['id' => $B, 'inTopMatches' => true]]);

$r = RecommendationPipeline::combine([$A, $B, $C], [$A, $B], [$C], $on);
check('Case 4: preferred C not in Top Matches -> list unchanged, C marked not included (own section)',
    $r['finalIds'] === [$A, $B] && $r['preferred'] === [['id' => $C, 'inTopMatches' => false]]);
check('Case 4: preferred course outside the Top Matches is not a mismatch by itself (final definition)', $r['status'] === 'match');

$r = RecommendationPipeline::combine([$A, $B, $C], [$A, $B], [$D, $B, $D, $C], $on);
check('Case 5: several preferred courses -> kept in the order entered, duplicates dropped',
    array_column($r['preferred'], 'id') === [$D, $B, $C] && array_column($r['preferred'], 'inTopMatches') === [false, true, false]);
check('Case 5: preferred courses never change the Top Matches', $r['finalIds'] === [$A, $B]);

echo "\n=== Order and edge cases ===\n";
$r = RecommendationPipeline::combine([$C, $B, $A], [$A, $B], [], $on);
check('common courses keep the CBF (program-list) order, not the prediction order', $r['finalIds'] === [$B, $A]);
$r = RecommendationPipeline::combine([], [$A], [$A], $on);
check('no CBF match at all -> mismatch (no_cbf_match), empty list', $r['reason'] === 'no_cbf_match' && $r['finalIds'] === []);
$r = RecommendationPipeline::combine([$A, $B], [], [], $on);
check('model gives no prediction for this input -> treated as no common course', $r['reason'] === 'no_common_course' && $r['finalIds'] === [$A, $B]);

echo "\n=== Interim (prediction model not enabled) ===\n";
$r = RecommendationPipeline::combine([$A, $B, $C], null, [$B]);
check('Top Matches = CBF matches; preferred among them -> match', $r['finalIds'] === [$A, $B, $C] && $r['status'] === 'match' && $r['predictionIds'] === null);
$r = RecommendationPipeline::combine([$A, $B, $C], null, [$D]);
check('preferred not among the CBF matches -> mismatch (preferred_not_matched)', $r['status'] === 'mismatch' && $r['reason'] === 'preferred_not_matched');
check('no preferred course given -> match', RecommendationPipeline::combine([$A], null, [])['status'] === 'match');
check('prediction output ignored while the model is disabled', RecommendationPipeline::combine([$A, $B], [$D], [$A])['finalIds'] === [$A, $B]);
check('model enabled but not imported (null) -> interim rule', RecommendationPipeline::combine([$A, $B], null, [$D], $on)['reason'] === 'preferred_not_matched');

echo "\n=== Prediction model: WEKA J48 tree ===\n";
$numeric = <<<TXT
=== Classifier model (full training set) ===

J48 pruned tree
------------------

C <= 0
|   S <= 0: 'BS Civil Engineering' (6.0/2.0)
|   S > 0
|   |   A <= 0: 'BS Nursing' (4.0/1.0)
|   |   A > 0: 'BA Communication' (5.0)
C > 0
|   E <= 0: 'BS Information Technology' (7.0/3.0)
|   E > 0: 'BS Accountancy' (8.0/2.0)

Number of Leaves  : 	5
TXT;
$tree = PredictionModel::parseJ48($numeric);
check('numeric (1/0) tree: C,I,E -> BS Accountancy', PredictionModel::predictLabel($tree, ['C', 'I', 'E']) === 'BS Accountancy');
check('numeric tree: S,I,A -> BA Communication', PredictionModel::predictLabel($tree, ['S', 'I', 'A']) === 'BA Communication');
check('numeric tree: R,I,S -> BS Nursing', PredictionModel::predictLabel($tree, ['R', 'I', 'S']) === 'BS Nursing');
check('leaf labels listed once each', count(PredictionModel::leafLabels($tree)) === 5);

$nominal = "J48 pruned tree\n------------------\n\nc = x\n|   i = x: bsit (3.0/1.0)\n|   i = no: BSA (2.0)\nc = no: BSN (4.0/1.0)\n";
$tree2 = PredictionModel::parseJ48($nominal);
check('adviser format (x / no, lowercase letters): C,I,E -> bsit', PredictionModel::predictLabel($tree2, ['C', 'I', 'E']) === 'bsit');
check('adviser format: R,I,A -> BSN', PredictionModel::predictLabel($tree2, ['R', 'I', 'A']) === 'BSN');
check('single-leaf tree', PredictionModel::parseJ48("J48 pruned tree\n------------------\n: BSIT (10.0/4.0)\n") === ['class' => 'BSIT']);
$blankOnly = PredictionModel::parseJ48("J48 pruned tree\n------------------\nc = x: BSA (3.0)\n");
check('no branch for the input (trained with blanks as missing) -> no prediction', PredictionModel::predictLabel($blankOnly, ['R', 'I', 'A']) === null);
$threw = false;
try { PredictionModel::parseJ48("not a tree at all"); } catch (InvalidArgumentException $e) { $threw = true; }
check('text without a tree is rejected', $threw);

$titles = array_map(fn($p) => $p[1], require __DIR__ . '/../db/program_codes.php');
check('class label "bsit" -> BS Information Technology', PredictionModel::resolveLabel('bsit', $titles) === 'BS Information Technology');
check('class label "B Multimedia Arts" (dataset tools name) -> BS Multimedia Arts', PredictionModel::resolveLabel('B Multimedia Arts', $titles) === 'BS Multimedia Arts');
check('class label "BSBA Major in Financial Management" -> full program title',
    PredictionModel::resolveLabel('BSBA Major in Financial Management', $titles) === 'BS Business Administration Major in Financial Management');
check('unknown label -> null', PredictionModel::resolveLabel('BS Astronomy', $titles) === null);
$aliases = require __DIR__ . '/../config/course_aliases.php';
check('every alias key is a real program title', array_diff(array_keys($aliases), $titles) === []);

$programs = [['id' => 4, 'title' => 'BS Information Technology'], ['id' => 7, 'title' => 'BS Nursing']];
$model = ['version' => 'test', 'tree' => $tree2];
check('predict(): label mapped to the program id', PredictionModel::predict(['C', 'I', 'E'], $programs, $model) === [4]);
check('no model file -> load() returns null (model not available)', PredictionModel::load(__DIR__ . '/no_such_model.json') === null);

echo "\n=== Full pipeline run (stages 2-6) ===\n";
$progs = [];
foreach (require __DIR__ . '/../db/program_codes.php' as $i => [$college, $title, , $code]) {
    $progs[] = ['id' => $i + 1, 'title' => $title, 'hollandCode' => $code, 'relatedStrands' => [], 'collegeCode' => $college];
}
$idOf = array_column($progs, 'id', 'title');
$profile = ['riasec' => ['R' => 15, 'I' => 40, 'A' => 12, 'S' => 18, 'E' => 35, 'C' => 45], 'strand' => 'ABM', 'electives' => []];
$run = RecommendationPipeline::run($profile, $progs, [$idOf['BS Nursing']], $on, ['version' => 'test', 'tree' => $tree]);
$cbfTitles = array_map(fn($id) => array_column($progs, 'title', 'id')[$id], $run['cbfIds']);
check('C-I-E student: CBF match set = the 5 programs coded with C, I, E', count($run['cbfIds']) === 5 && in_array('BS Accountancy', $cbfTitles, true));
check('C-I-E student: model predicts BS Accountancy -> Top Matches = {BS Accountancy}', $run['finalIds'] === [$idOf['BS Accountancy']] && $run['predictionIds'] === [$idOf['BS Accountancy']]);
check('C-I-E student: Preferred Course BS Nursing not among the Top Matches', $run['preferred'] === [['id' => $idOf['BS Nursing'], 'inTopMatches' => false]]);
check('model version recorded', $run['modelVersion'] === 'test');
$run = RecommendationPipeline::run($profile, $progs, [$idOf['BS Nursing']], $prod);
check('model disabled: Top Matches = CBF match set, no model version', $run['finalIds'] === $run['cbfIds'] && $run['modelVersion'] === null);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
