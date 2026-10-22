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

echo "\n=== Rank features (evaluation/model_experiment.py 'rank' encoding) ===\n";
$r = PredictionModel::ranks(['R' => 40, 'I' => 50, 'A' => 10, 'S' => 10, 'E' => 20, 'C' => 40]);
check('ranks: I=1, R and C tied for 2nd -> 2.5 each, E=4, A and S tied -> 5.5', $r === ['I' => 1, 'R' => 2.5, 'C' => 2.5, 'E' => 4, 'A' => 5.5, 'S' => 5.5]);
check('ranks are scale-free: 0-10 counts give the same ranks as 10-50 totals',
    PredictionModel::ranks(['R' => 8, 'I' => 10, 'A' => 2, 'S' => 2, 'E' => 4, 'C' => 8]) == $r);
$rankTree = PredictionModel::parseJ48("J48 pruned tree\n------------------\n\nS <= 2\n|   I <= 1.5: BS Nursing (5.0/1.0)\n|   I > 1.5: BS Psychology (4.0/2.0)\nS > 2: BS Civil Engineering (9.0/5.0)\n");
$rankModel = ['tree' => $rankTree, 'features' => 'rank'];
check('rank tree: S 1st, I 2nd -> BS Psychology', PredictionModel::predictLabelFromScores($rankModel, ['R' => 20, 'I' => 40, 'A' => 10, 'S' => 50, 'E' => 15, 'C' => 12]) === 'BS Psychology');
check('rank tree: I 1st, S 2nd -> BS Nursing', PredictionModel::predictLabelFromScores($rankModel, ['R' => 20, 'I' => 50, 'A' => 10, 'S' => 40, 'E' => 15, 'C' => 12]) === 'BS Nursing');
check('rank tree: S not in the top 2 -> BS Civil Engineering', PredictionModel::predictLabelFromScores($rankModel, ['R' => 50, 'I' => 40, 'A' => 10, 'S' => 20, 'E' => 15, 'C' => 30]) === 'BS Civil Engineering');
check('a model without "features" is a top-3 model (files imported before this change)',
    PredictionModel::predictLabelFromScores(['tree' => $tree2], ['R' => 10, 'I' => 40, 'A' => 10, 'S' => 10, 'E' => 30, 'C' => 50]) === 'bsit');
$threw = false;
try { PredictionModel::predictLabelFromScores(['tree' => $rankTree, 'features' => 'zscore'], $r); } catch (InvalidArgumentException $e) { $threw = true; }
check('unknown feature encoding -> refused, not guessed', $threw);


echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
