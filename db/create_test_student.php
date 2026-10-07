<?php
/**
 * Creates (or resets) a test student with a finished RIASEC Assessment and Career
 * Electives Worksheet, and computes their Top Matches, so a result page can be
 * checked without answering 60 questions. FOR LOCAL TESTING ONLY.
 *
 *   php db/create_test_student.php                         aligned (match) student
 *   php db/create_test_student.php --mismatch              Preferred Course NOT in the Top Matches
 *   php db/create_test_student.php --id TEST-X --preferred "BS Nursing" --scores R=12,I=40,A=15,S=44,E=20,C=18
 *
 * Defaults: TEST-ALIGNED, STEM, RIASEC I-R-C, Preferred Course BS Information Technology
 * (an IRC program, so it is among the Top Matches). Log in with the printed username
 * and the password Test123!. Running it again with the same --id replaces that
 * student's assessment, worksheet and results.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/CBFData.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if (strtolower((string) envValue('APP_ENV')) === 'production') {
    exit("Refusing to create test students when APP_ENV=production.\n");
}

$opt = function (string $name, ?string $default) use ($argv): ?string {
    $i = array_search("--$name", $argv, true);
    return $i !== false && isset($argv[$i + 1]) ? $argv[$i + 1] : $default;
};
$mismatch = in_array('--mismatch', $argv, true);
$schoolId = strtoupper($opt('id', $mismatch ? 'TEST-MISMATCH' : 'TEST-ALIGNED'));
$preferredTitle = $opt('preferred', $mismatch ? 'BA Communication' : 'BS Information Technology');
$strand = strtoupper($opt('strand', 'STEM'));
$scores = ['R' => 38, 'I' => 45, 'A' => 18, 'S' => 20, 'E' => 22, 'C' => 35]; // I-R-C
foreach (array_filter(explode(',', (string) $opt('scores', ''))) as $pair) {
    [$k, $v] = array_map('trim', explode('=', $pair) + [1 => '']);
    if (!isset($scores[strtoupper($k)]) || !ctype_digit($v) || (int) $v < 10 || (int) $v > 50) {
        exit("Bad --scores entry \"$pair\": use R=..,I=..,A=..,S=..,E=..,C=.. with values 10-50.\n");
    }
    $scores[strtoupper($k)] = (int) $v;
}

$pdo = Database::get();
$programs = CBFData::activePrograms($pdo);
$preferred = null;
foreach ($programs as $p) {
    if (strcasecmp($p['title'], $preferredTitle) === 0) {
        $preferred = $p;
    }
}
if ($preferred === null) {
    exit("No active program named \"$preferredTitle\". Use the exact title from Career Data Set.\n");
}

$labels = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
$topTypes = json_encode(array_map(fn($l) => $labels[$l], CBFEngine::topRiasecLetters($scores, 3)));
$username = strtolower($schoolId);

$pdo->beginTransaction();
try {
    $existing = $pdo->prepare('SELECT user_id FROM students WHERE school_id = ?');
    $existing->execute([$schoolId]);
    $userId = $existing->fetchColumn();
    if ($userId === false) {
        $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at) VALUES ('student', ?, ?, ?, NOW())")
            ->execute([$username, password_hash('Test123!', PASSWORD_DEFAULT), "$username@example.test"]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level) VALUES (?, ?, ?, ?, ?, '11')")
            ->execute([$userId, $schoolId, Crypto::enc('Test'), Crypto::enc(ucwords(strtolower(str_replace('TEST-', '', $schoolId)))), $strand]);
    } else {
        $userId = (int) $userId;
        foreach (['monitoring_flags', 'recommendations', 'worksheets', 'assessments'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE student_id = ?")->execute([$userId]);
        }
    }

    $a = $pdo->prepare('INSERT INTO assessments (student_id, attempt_number, score_r, score_i, score_a, score_s, score_e, score_c, top_types)
                        VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
    $a->execute([$userId, $scores['R'], $scores['I'], $scores['A'], $scores['S'], $scores['E'], $scores['C'], $topTypes]);
    $assessmentId = (int) $a->fetchColumn();

    $w = $pdo->prepare('INSERT INTO worksheets (student_id, attempt_number, stated_program_id, electives, top_types) VALUES (?, 1, ?, ?, ?) RETURNING id');
    $w->execute([$userId, $preferred['id'], CBFData::textArrayLiteral(['Research Methods']), $topTypes]);
    $worksheetId = (int) $w->fetchColumn();

    $saved = CBFData::saveRecommendation($pdo, $userId, CBFData::studentProfile($pdo, $userId), $preferred['id'], $assessmentId, $worksheetId);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    exit('Failed: ' . $e->getMessage() . "\n(Have you run db/migrate_add_recommendation_status.php and db/migrate_add_recommendation_sets.php?)\n");
}

$inTop = in_array($preferred['title'], $saved['finalTitles'], true);
echo "Test student ready\n";
echo "  Login           : username $username / password Test123!\n";
echo '  RIASEC code     : ' . implode('-', CBFEngine::topRiasecLetters($scores, 3)) . "\n";
echo "  Preferred Course: {$preferred['title']} (" . ($inTop ? 'IN the Top Matches: aligned' : 'NOT in the Top Matches') . ")\n";
echo '  Top Matches     : ' . implode(', ', $saved['finalTitles']) . "\n";
echo "  Status          : {$saved['status']}" . ($saved['reason'] ? " ({$saved['reason']})" : '') . "\n";
