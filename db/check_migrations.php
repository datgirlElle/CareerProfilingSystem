<?php
/**
 * Read-only check of a database against db/schema.sql: which tables, columns and indexes it lacks,
 * and which db/migrate_*.php script adds each one, in the order db/setup_new_database.php runs them.
 * It also shows today's exam sessions and recent activity, so a migration can be run at a quiet time.
 *
 * Everything runs inside a READ ONLY transaction, so this script cannot change the database.
 *
 *   php db/check_migrations.php                              the database in .env (or the environment)
 *   php db/check_migrations.php --expect=profilepath_g5xj    stop unless connected to that database
 *
 * Exit code: 0 nothing missing, 1 something missing, 2 not the expected database (or a bad option).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../shared/lib/Database.php';

/**
 * The tables (with their columns) and the indexes that db/schema.sql creates.
 *
 * @return array{tables: array<string, string[]>, indexes: string[]}
 */
function schemaObjects(string $sql): array
{
    $tables = [];
    preg_match_all('/^CREATE TABLE (\w+) \((.*?)^\);/ms', $sql, $blocks, PREG_SET_ORDER);
    foreach ($blocks as [, $table, $body]) {
        $tables[$table] = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            // A column line starts with the column's name; comments and table constraints such as
            // "PRIMARY KEY (module, role)" do not.
            if ($line === '' || str_starts_with($line, '--') || preg_match('/^(PRIMARY KEY|UNIQUE|CHECK|CONSTRAINT|FOREIGN KEY)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^(\w+)\s/', $line, $m)) {
                $tables[$table][] = $m[1];
            }
        }
    }
    preg_match_all('/^CREATE (?:UNIQUE )?INDEX (\w+)/m', $sql, $indexes);
    return ['tables' => $tables, 'indexes' => $indexes[1]];
}

/**
 * Which migration adds each table, column ("table.column") and index: the first one in the order
 * db/setup_new_database.php runs them (migrations it does not list come last).
 *
 * @return array{map: array<string, string>, order: string[]}
 */
function migrationMap(string $dbDir): array
{
    preg_match_all("/'(migrate_\w+\.php)'/", (string) file_get_contents("$dbDir/setup_new_database.php"), $listed);
    $order = array_values(array_unique(array_merge($listed[1], array_map('basename', glob("$dbDir/migrate_*.php") ?: []))));
    $map = [];
    foreach ($order as $file) {
        $php = (string) @file_get_contents("$dbDir/$file");
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+)/i', $php, $tables);
        preg_match_all('/ALTER TABLE (\w+) ADD COLUMN (?:IF NOT EXISTS )?(\w+)/i', $php, $columns, PREG_SET_ORDER);
        preg_match_all('/CREATE (?:UNIQUE )?INDEX (?:IF NOT EXISTS )?(\w+)/i', $php, $indexes);
        foreach ([...$tables[1], ...array_map(fn($c) => "$c[1].$c[2]", $columns), ...$indexes[1]] as $object) {
            $map[$object] ??= $file;
        }
    }
    return ['map' => $map, 'order' => $order];
}

// ---- main: only when run directly (tests can require this file for the two functions above)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$expect = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--expect=')) {
        $expect = substr($arg, strlen('--expect='));
    } else {
        echo "Unknown option: $arg\n";
        exit(2);
    }
}

$pdo = Database::get();
// Any write inside a READ ONLY transaction fails, so nothing below can change the database.
$pdo->exec('START TRANSACTION READ ONLY');

$current = (string) $pdo->query('SELECT current_database()')->fetchColumn();
echo "Database: $current on " . envValue('DB_HOST') . "\n";
if ($expect !== null && $current !== $expect) {
    echo "This is not $expect. Stopped (nothing was checked).\n";
    exit(2);
}

$have = [];
foreach ($pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'") as $row) {
    $have[$row['table_name']][] = $row['column_name'];
}
$haveIndexes = $pdo->query("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN);

$schema = schemaObjects((string) file_get_contents(__DIR__ . '/schema.sql'));
['map' => $map, 'order' => $order] = migrationMap(__DIR__);

// [kind, name, migration that adds it or null]
$missing = [];
foreach ($schema['tables'] as $table => $columns) {
    if (!isset($have[$table])) {
        $missing[] = ['table', $table, $map[$table] ?? null];
        continue;
    }
    foreach (array_diff($columns, $have[$table]) as $column) {
        $missing[] = ['column', "$table.$column", $map["$table.$column"] ?? null];
    }
}
foreach (array_diff($schema['indexes'], $haveIndexes) as $index) {
    $missing[] = ['index', $index, $map[$index] ?? null];
}

echo "\nCompared with db/schema.sql (" . count($schema['tables']) . ' tables, ' . count($schema['indexes']) . " indexes):\n";
if (!$missing) {
    echo "  Nothing missing.\n";
} else {
    foreach ($missing as [$kind, $name, $file]) {
        echo '  MISSING ' . str_pad($kind, 7) . str_pad($name, 34) . ($file !== null ? "db/$file" : 'no db/migrate_*.php adds this; check by hand') . "\n";
    }
    $toRun = array_values(array_intersect($order, array_unique(array_filter(array_column($missing, 2)))));
    if ($toRun) {
        echo "\n  To add them, run in this order (each is safe to run twice):\n";
        foreach ($toRun as $file) {
            echo "    php db/$file\n";
        }
    }
}

// Objects the database has but db/schema.sql does not: leftovers, not a problem by themselves.
$extra = [];
foreach ($have as $table => $columns) {
    if (!isset($schema['tables'][$table])) {
        $extra[] = "table $table";
        continue;
    }
    foreach (array_diff($columns, $schema['tables'][$table]) as $column) {
        $extra[] = "column $table.$column";
    }
}
if ($extra) {
    echo "\nIn the database but not in db/schema.sql (leftovers, not a problem by themselves):\n  " . implode("\n  ", $extra) . "\n";
}

// When it is quiet: exam sessions today and recent activity (no names or other personal data).
echo "\nToday (" . date('D, M j, Y g:i A') . ", school time):\n";
$scheduleColumns = $have['exam_schedules'] ?? [];
if (!in_array('exam_date', $scheduleColumns, true) || !in_array('start_time', $scheduleColumns, true) || !in_array('end_time', $scheduleColumns, true)) {
    echo "  Exam sessions: cannot be read (exam_schedules is missing or incomplete).\n";
} else {
    $sessions = $pdo->query(
        "SELECT to_char(start_time, 'HH12:MI AM') AS starts, to_char(end_time, 'HH12:MI AM') AS ends,
                CASE WHEN LOCALTIME < start_time THEN 'later today'
                     WHEN LOCALTIME <= end_time THEN 'OPEN NOW'
                     ELSE 'ended' END AS state
         FROM exam_schedules WHERE exam_date = CURRENT_DATE ORDER BY start_time"
    )->fetchAll();
    if (!$sessions) {
        echo "  Exam sessions: none today.\n";
    }
    foreach ($sessions as $s) {
        echo "  Exam session {$s['starts']} to {$s['ends']}: {$s['state']}\n";
    }
}
if (isset($have['sessions'])) {
    $active = $pdo->query("SELECT count(*) FROM sessions WHERE last_activity > NOW() - INTERVAL '15 minutes'")->fetchColumn();
    echo "  Browser sessions active in the last 15 minutes (signed in or not): $active\n";
}

$pdo->exec('ROLLBACK');
exit($missing ? 1 : 0);
