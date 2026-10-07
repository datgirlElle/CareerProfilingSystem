<?php
/**
 * Prediction model (WEKA J48 tree reader, lib/PredictionModel.php). OUT OF SCOPE for the
 * CBF revision: these tests were moved here unchanged from tests/pipeline_test.php; the
 * prediction model code itself is not modified and not used by the recommendation.
 *
 *   php tests/prediction_model_test.php
 */
require_once __DIR__ . '/../lib/PredictionModel.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}

echo "=== Prediction model: WEKA J48 tree ===\n";
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


echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
