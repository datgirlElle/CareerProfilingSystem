<?php

require_once __DIR__ . '/CBFEngine.php';

/**
 * Prediction model: the decision tree (J48) trained in WEKA, run inside the system.
 *
 * Dataset (adviser's format): one row per student, the six RIASEC letters as
 * attributes (x = one of the student's top 3 types, blank = not), and the course as
 * the class. At prediction time the same attributes are built from the student's
 * RIASEC Assessment, the tree is walked, and the predicted class (one course) is
 * returned.
 *
 * The tree is imported from WEKA's printed J48 output by db/import_weka_tree.php
 * into config/prediction_model.json, so WEKA/Java is not needed on the server.
 * When that file does not exist, the model is "not available" and predict() returns null.
 */
class PredictionModel
{
    public const MODEL_FILE = __DIR__ . '/../config/prediction_model.json';

    private const TRUE_VALUES = ['x', 'yes', 'y', '1', 'true', 't'];
    private const FALSE_VALUES = ['no', 'n', '0', 'false', 'f', '', '?', 'blank', 'none'];
    private const DIMENSION_NAMES = [
        'REALISTIC' => 'R', 'INVESTIGATIVE' => 'I', 'ARTISTIC' => 'A',
        'SOCIAL' => 'S', 'ENTERPRISING' => 'E', 'CONVENTIONAL' => 'C',
    ];

    /** The imported model, or null when none has been imported. */
    public static function load(?string $file = null): ?array
    {
        $file ??= self::MODEL_FILE;
        if (!is_file($file)) {
            return null;
        }
        $model = json_decode((string) file_get_contents($file), true);
        return is_array($model) && isset($model['tree']) ? $model : null;
    }

    /**
     * Predicted course (class label as written in the dataset) for a student whose
     * top RIASEC types are $topLetters (e.g. ['C', 'I', 'E']). null if the tree has no
     * branch for this input.
     */
    public static function predictLabel(array $tree, array $topLetters): ?string
    {
        $selected = array_fill_keys(array_map('strtoupper', $topLetters), true);
        $node = $tree;
        while (!isset($node['class'])) {
            $letter = self::dimension((string) ($node['attribute'] ?? ''));
            if ($letter === null) {
                return null;
            }
            $isSelected = isset($selected[$letter]);
            $next = null;
            foreach ($node['branches'] ?? [] as $branch) {
                if (self::branchMatches($branch['op'], (string) $branch['value'], $isSelected)) {
                    $next = $branch['node'];
                    break;
                }
            }
            if ($next === null) {
                return null;
            }
            $node = $next;
        }
        return (string) $node['class'];
    }

    /**
     * Prediction stage of the recommendation pipeline: program ids the model predicts
     * for the student (one course), or null when no model has been imported.
     *
     * @param array<int,array{id:int,title:string}> $programs active programs
     * @return int[]|null
     */
    public static function predict(array $topLetters, array $programs, ?array $model = null): ?array
    {
        $model ??= self::load();
        if ($model === null) {
            return null;
        }
        $label = self::predictLabel($model['tree'], $topLetters);
        if ($label === null) {
            return [];
        }
        $title = self::resolveLabel($label, array_column($programs, 'title'));
        foreach ($programs as $p) {
            if ($p['title'] === $title) {
                return [(int) $p['id']];
            }
        }
        return [];
    }

    /** Program title a dataset class label refers to (title, alias or abbreviation), or null. */
    public static function resolveLabel(string $label, array $titles): ?string
    {
        $key = self::key($label);
        $aliases = require __DIR__ . '/../config/course_aliases.php';
        foreach ($titles as $title) {
            if (self::key($title) === $key) {
                return $title;
            }
            foreach ($aliases[$title] ?? [] as $alias) {
                if (self::key($alias) === $key) {
                    return $title;
                }
            }
        }
        return null;
    }

    /**
     * Parses WEKA's printed J48 tree (the "J48 pruned tree" block of the classifier
     * output, or just the tree lines) into nested nodes:
     *   ['attribute' => 'C', 'branches' => [['op' => '=', 'value' => 'x', 'node' => ...], ...]]
     *   ['class' => 'BSIT']
     */
    public static function parseJ48(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $start = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^J48 (pruned|unpruned) tree/i', trim($line))) {
                $start = $i + 1;
                break;
            }
        }

        $root = null;
        $decisionAt = [];
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $raw = rtrim($lines[$i]);
            $trimmed = trim($raw);
            if ($trimmed === '' || preg_match('/^-+$/', $trimmed)) {
                if ($root !== null) {
                    break; // blank line after the tree ends it
                }
                continue;
            }
            if (preg_match('/^(Number of Leaves|Size of the tree)/i', $trimmed)) {
                break;
            }
            // Single-leaf tree: ": BSIT (10.0/2.0)"
            if ($root === null && preg_match('/^:\s*(.+?)\s*(\([^()]*\))?$/', $trimmed, $m)) {
                return ['class' => trim($m[1], " '\"")];
            }

            preg_match('/^((?:\|\s*)*)(.*)$/', $raw, $m);
            $depth = substr_count($m[1], '|');
            $content = $m[2];
            $class = null;
            if (preg_match('/^(.*?)\s*:\s*(.+?)\s*(\([^()]*\))?$/', $content, $mm)) {
                $content = $mm[1];
                $class = trim($mm[2], " '\"");
            }
            if (!preg_match('/^(.+?)\s*(<=|>=|!=|=|<|>)\s*(.*)$/', $content, $c)) {
                throw new InvalidArgumentException('Line ' . ($i + 1) . " is not a J48 tree line: \"$trimmed\"");
            }
            [$attr, $op, $value] = [trim($c[1]), $c[2], trim($c[3], " \t'\"")];

            if ($depth === 0) {
                $root ??= (object) ['attribute' => $attr, 'branches' => []];
                $decisionAt[0] = $root;
            }
            $decision = $decisionAt[$depth] ?? null;
            if ($decision === null) {
                throw new InvalidArgumentException('Line ' . ($i + 1) . ' is indented without a parent branch.');
            }
            $decision->attribute ??= $attr;

            $branch = (object) ['op' => $op, 'value' => $value, 'node' => null];
            if ($class !== null) {
                $branch->node = (object) ['class' => $class];
            } else {
                $branch->node = (object) ['attribute' => null, 'branches' => []];
                $decisionAt[$depth + 1] = $branch->node;
            }
            $decision->branches[] = $branch;
        }
        if ($root === null) {
            throw new InvalidArgumentException('No J48 tree found in the file.');
        }
        return json_decode(json_encode($root), true);
    }

    /** Every class label used in a tree's leaves. */
    public static function leafLabels(array $node): array
    {
        if (isset($node['class'])) {
            return [$node['class']];
        }
        $labels = [];
        foreach ($node['branches'] ?? [] as $b) {
            $labels = array_merge($labels, self::leafLabels($b['node']));
        }
        return array_values(array_unique($labels));
    }

    private static function branchMatches(string $op, string $value, bool $isSelected): bool
    {
        $v = strtolower(trim($value));
        if ($op === '=' || $op === '!=') {
            if (in_array($v, self::TRUE_VALUES, true)) {
                $eq = $isSelected;
            } elseif (in_array($v, self::FALSE_VALUES, true)) {
                $eq = !$isSelected;
            } else {
                return false;
            }
            return $op === '=' ? $eq : !$eq;
        }
        // Numeric attributes (1 = selected, 0 = not), as written by evaluation/cbf_eval.py.
        $x = $isSelected ? 1.0 : 0.0;
        $t = (float) $value;
        return match ($op) {
            '<=' => $x <= $t,
            '<' => $x < $t,
            '>' => $x > $t,
            '>=' => $x >= $t,
            default => false,
        };
    }

    private static function dimension(string $attribute): ?string
    {
        $a = strtoupper(trim($attribute, " \t'\""));
        if (in_array($a, CBFEngine::DIMENSIONS, true)) {
            return $a;
        }
        return self::DIMENSION_NAMES[$a] ?? null;
    }

    private static function key(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }
}
