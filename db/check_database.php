<?php
/**
 * Quick health check of the database the current settings point to (.env or the environment):
 * which database it is, how many rows the main tables have, and whether APP_AES_KEY can read
 * the encrypted program names. Reads only; changes nothing.
 *
 *   php db/check_database.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

$pdo = Database::get();
echo 'Database: ' . envValue('DB_NAME') . ' on ' . envValue('DB_HOST') . "\n\n";

foreach (['colleges', 'programs', 'assessment_questions', 'security_rbac', 'sections', 'users'] as $table) {
    try {
        $count = $pdo->query("SELECT count(*) FROM $table")->fetchColumn();
        echo str_pad($table, 22) . $count . "\n";
    } catch (Throwable $e) {
        echo str_pad($table, 22) . "MISSING (the setup has not created this table)\n";
    }
}

echo "\nKey check: ";
if (!envValue('APP_AES_KEY')) {
    echo "APP_AES_KEY is not set here, so the key cannot be checked.\n";
    exit;
}
try {
    $enc = $pdo->query('SELECT title_enc FROM programs ORDER BY id LIMIT 1')->fetchColumn();
    if ($enc === false) {
        echo "no programs to test with.\n";
    } else {
        echo 'OK, the first program reads as "' . Crypto::dec($enc) . "\" (this key matches the data).\n";
    }
} catch (Throwable $e) {
    echo "FAILED, this APP_AES_KEY cannot read the programs (they were written with a different key).\n";
}
