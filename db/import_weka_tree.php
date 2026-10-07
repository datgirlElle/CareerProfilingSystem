<?php
/**
 * Imports the decision tree trained in WEKA into the system.
 *
 *   php db/import_weka_tree.php path/to/j48_output.txt [--version "J48 v1 2026-10-07"]
 *
 * In WEKA Explorer, after training J48, right-click the result -> "Save result buffer"
 * and pass that file here (only the "J48 pruned tree" block is read). The tree is
 * saved to config/prediction_model.json, which lib/PredictionModel.php runs for each
 * student. Then set 'decision_tree' => ['enabled' => true] in config/cbf.php and run
 * php db/recompute_recommendations.php.
 *
 * The script checks that every predicted course in the tree is one of the system's
 * programs, and prints the prediction for all 20 possible top-3 RIASEC combinations
 * so it can be compared with WEKA.
 */

require_once __DIR__ . '/../lib/PredictionModel.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    exit("Usage: php db/import_weka_tree.php path/to/j48_output.txt [--version \"name\"]\n");
}
$version = 'J48 ' . date('Y-m-d');
if (($i = array_search('--version', $argv, true)) !== false && isset($argv[$i + 1])) {
    $version = $argv[$i + 1];
}

try {
    $tree = PredictionModel::parseJ48((string) file_get_contents($file));
} catch (InvalidArgumentException $e) {
    exit('Could not read the tree: ' . $e->getMessage() . "\n");
}

$titles = array_map(fn($p) => $p[1], require __DIR__ . '/program_codes.php');
$unknown = [];
echo "Predicted courses in the tree:\n";
foreach (PredictionModel::leafLabels($tree) as $label) {
    $title = PredictionModel::resolveLabel($label, $titles);
    printf("  %-20s -> %s\n", $label, $title ?? 'NOT A SYSTEM PROGRAM');
    if ($title === null) {
        $unknown[] = $label;
    }
}
if ($unknown) {
    exit("\nStopped: add these names to config/course_aliases.php (or fix them in the dataset): " . implode(', ', $unknown) . "\n");
}

echo "\nPrediction for every top-3 RIASEC combination (compare with WEKA):\n";
$letters = CBFEngine::DIMENSIONS;
$noBranch = 0;
for ($a = 0; $a < 6; $a++) {
    for ($b = $a + 1; $b < 6; $b++) {
        for ($c = $b + 1; $c < 6; $c++) {
            $top = [$letters[$a], $letters[$b], $letters[$c]];
            $label = PredictionModel::predictLabel($tree, $top);
            $noBranch += $label === null ? 1 : 0;
            printf("  %s -> %s\n", implode('', $top), $label === null ? '(no branch: no prediction)' : PredictionModel::resolveLabel($label, $titles));
        }
    }
}
if ($noBranch) {
    echo "\nNote: $noBranch combination(s) have no matching branch. This happens when the tree was trained with\n"
        . "blank (missing) values. Build the WEKA file with evaluation/cbf_eval.py (x -> 1, blank -> 0) to avoid it.\n";
}

$model = ['version' => $version, 'source' => basename($file), 'imported_at' => date('c'), 'tree' => $tree];
file_put_contents(PredictionModel::MODEL_FILE, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nSaved to config/prediction_model.json ($version).\n"
    . "Next: set 'decision_tree' => ['enabled' => true] in config/cbf.php, then run php db/recompute_recommendations.php\n";
