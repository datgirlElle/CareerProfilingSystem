<?php
/**
 * Adds the official Career-to-MMCL Program Mapping (modules/CareerDataset/lib/StarterCareers.php) to each program's saved
 * careers WITHOUT removing or replacing anything: careers staff already saved stay, in their order, and only the official
 * careers a program is missing are added after them (compared without regard to capitals or extra spaces). Programs are
 * matched by their decrypted title. Safe to run again: a second run finds nothing to add.
 *
 *   php db/merge_official_careers.php                         dry run: shows what would be added, changes nothing
 *   php db/merge_official_careers.php --apply                 saves a backup file first, then adds the missing careers
 *   php db/merge_official_careers.php --restore=FILE          dry run of putting a backup back
 *   php db/merge_official_careers.php --restore=FILE --apply  puts every program in the backup back exactly as it was
 *
 * Run it with the same .env (database and APP_AES_KEY) as the app. Unlike backfill_program_careers.php --overwrite, it
 * never discards staff edits. Backups go to db/backups/ (never served by the site, ignored by git). Each program it
 * changes gets one "Added official careers" entry in the Audit Log (actor: System).
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../modules/CareerDataset/lib/StarterCareers.php';
require_once __DIR__ . '/../modules/AuditLog/lib/AuditLogger.php';

$apply = in_array('--apply', $argv, true);
$restoreFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--restore=')) {
        $restoreFile = substr($arg, strlen('--restore='));
    }
}

$pdo = Database::get();
$dbName = (string) envValue('DB_NAME');
echo 'Database: ' . $dbName . ' on ' . envValue('DB_HOST') . ($apply ? '' : '  (dry run, nothing is changed)') . "\n\n";
$update = $pdo->prepare('UPDATE programs SET careers = ?::text[], updated_at = NOW() WHERE id = ?');
$fail = function (string $message) use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Nothing was changed. $message\n");
    exit(1);
};

// ---- Roll back from a backup made by an earlier --apply ----
if ($restoreFile !== null) {
    $backup = is_file($restoreFile) ? json_decode((string) file_get_contents($restoreFile), true) : null;
    if (!is_array($backup) || !isset($backup['programs']) || !is_array($backup['programs'])) {
        $fail("Not a backup file made by this script: $restoreFile");
    }
    if (($backup['database'] ?? null) !== $dbName) {
        $fail('This backup was made on database "' . ($backup['database'] ?? '?') . "\", not \"$dbName\".");
    }
    // Every entry is checked before anything is written.
    foreach ($backup['programs'] as $p) {
        if (!is_array($p) || !is_int($p['id'] ?? null) || !is_string($p['title'] ?? null) || !is_array($p['careers'] ?? null)
            || array_filter($p['careers'], fn($c) => !is_string($c)) !== []) {
            $fail('The backup file is damaged (an entry is not id, title and a list of careers).');
        }
    }
    $pdo->beginTransaction();
    $current = [];
    foreach ($pdo->query('SELECT id, title_enc, careers FROM programs FOR UPDATE')->fetchAll() as $r) {
        $current[(int) $r['id']] = ['title' => Crypto::dec($r['title_enc']), 'careers' => Careers::parse($r['careers'])];
    }
    $changes = 0;
    foreach ($backup['programs'] as $p) {
        $now = $current[$p['id']] ?? null;
        if ($now === null || $now['title'] !== $p['title']) {
            echo "  #{$p['id']} {$p['title']}: no longer here under that name, skipped\n";
            continue;
        }
        if ($now['careers'] === $p['careers']) {
            continue;
        }
        $changes++;
        echo "  #{$p['id']} {$p['title']}: " . count($now['careers']) . ' -> ' . count($p['careers']) . " careers\n";
        if ($apply) {
            $update->execute([Careers::toLiteral($p['careers']), $p['id']]);
        }
    }
    $apply ? $pdo->commit() : $pdo->rollBack();
    echo "\n" . ($changes ? ($apply ? "Restored $changes program(s)." : "$changes program(s) would be restored. Run again with --apply.") : 'Everything already matches the backup.') . "\n";
    exit;
}

// ---- Add the missing official careers ----
// With --apply the rows are read and locked inside the transaction, so a staff edit saved meanwhile is never lost.
if ($apply) {
    $pdo->beginTransaction();
}
$rows = $pdo->query('SELECT id, title_enc, careers FROM programs ORDER BY id' . ($apply ? ' FOR UPDATE' : ''))->fetchAll();
$plan = [];
$unmatched = [];
$full = [];
$totalAdded = 0;
foreach ($rows as $row) {
    $title = Crypto::dec($row['title_enc']);
    $official = StarterCareers::forProgram($title);
    if ($official === []) {
        $unmatched[] = $title;
        continue;
    }
    $saved = Careers::parse($row['careers']);
    [$merged, $added] = Careers::merge($saved, $official);
    if ($added === []) {
        continue;
    }
    if (count($merged) > Careers::MAX_PER_PROGRAM) {
        $full[] = $title;
        continue;
    }
    $plan[] = ['id' => (int) $row['id'], 'title' => $title, 'saved' => $saved, 'merged' => $merged, 'added' => $added];
    $totalAdded += count($added);
    echo sprintf("  #%d %s: %d saved + %d official = %d\n", $row['id'], $title, count($saved), count($added), count($merged));
    echo '      adds: ' . implode(', ', $added) . "\n";
}

echo "\n" . count($plan) . ' program(s) get ' . $totalAdded . ' career(s); '
    . (count($rows) - count($plan) - count($unmatched) - count($full)) . " already have every official career.\n";
if ($unmatched) {
    echo 'Not in the official list (left as they are): ' . implode('; ', $unmatched) . "\n";
}
if ($full) {
    echo 'Left as they are, they would go over ' . Careers::MAX_PER_PROGRAM . ' careers: ' . implode('; ', $full) . "\n";
}
if (!$plan || !$apply) {
    if ($apply) {
        $pdo->rollBack();
    }
    if ($plan) {
        echo "\nNothing was changed. Run again with --apply to add them (a backup file is saved first).\n";
    }
    exit;
}

// Backup first: every program this run changes, exactly as it is now. db/backups/ is never served by the site.
$dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    $fail("Could not create the backup folder $dir.");
}
$file = $dir . DIRECTORY_SEPARATOR . 'careers-backup-' . date('Ymd-His') . '.json';
try {
    $json = json_encode(['made' => date('c'), 'database' => $dbName, 'programs' => array_map(
        fn($p) => ['id' => $p['id'], 'title' => $p['title'], 'careers' => $p['saved']], $plan)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    $fail('Could not make the backup: ' . $e->getMessage());
}
if (file_put_contents($file, $json) !== strlen($json)) {
    $fail("Could not write the backup file $file.");
}
echo "\nBackup saved: $file\n";

try {
    foreach ($plan as $p) {
        $update->execute([Careers::toLiteral($p['merged']), $p['id']]);
        AuditLogger::log(null, null, 'merge_official_careers', 'program', (string) $p['id'],
            $p['title'] . ' (careers: ' . count($p['added']) . ' added from the official list, ' . count($p['merged']) . ' now)');
    }
    $pdo->commit();
} catch (Throwable $e) {
    $fail($e->getMessage() . " (the backup file is not needed: $file)");
}
echo 'Done: ' . count($plan) . " program(s) updated. To undo: php db/merge_official_careers.php --restore=\"$file\" --apply\n";
