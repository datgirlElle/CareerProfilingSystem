<?php
// The Save Career feature (Saved Careers page, heart button, api/saved-programs.php, saved_programs table) was removed.
// This checks that nothing of it is left and that the results of a student still load.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/saved_careers_removed_http_test.php http://127.0.0.1:8099
// Seeds one synthetic student (username "qa_nosave_stu") with one result, and removes only that student.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'qa'); }
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) {
            $ch = curl_init($this->base . '/api/session.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
            $this->csrf = json_decode((string) curl_exec($ch), true)['csrfToken'] ?? '';
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $r = (string) curl_exec($ch);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $r];
    }
}

$pw = 'Qa-Test-Pass1!';
$pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['qa_nosave_stu']);
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name) VALUES ('student', 'qa_nosave_stu', ?, 'qa_nosave_stu@example.test', NOW(), 'approved', 'QA') RETURNING id");
$s->execute([password_hash($pw, PASSWORD_BCRYPT)]);
$sid = (int) $s->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, 'qa_nosave_stu', ?, ?, 'STEM', '11', 'QA-NS')")
    ->execute([$sid, Crypto::enc('Qa'), Crypto::enc('NoSave')]);

try {
    echo "=== The database ===\n";
    $t = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'saved_programs'")->fetchColumn();
    check('a new database has no saved_programs table', (int) $t === 0, (string) $t);

    echo "\n=== The student's result still loads ===\n";
    $a = $pdo->prepare("INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types) VALUES (?, 1, TRUE, 40, 50, 10, 10, 10, 30, '[]') RETURNING id");
    $a->execute([$sid]);
    $rec = CBFData::saveRecommendation($pdo, $sid, CBFData::studentProfile($pdo, $sid, []), null, (int) $a->fetchColumn(), null);
    check('a recommendation is saved for the test student', ($rec['recommendationId'] ?? 0) > 0);
    $c = new Client($base);
    $r = $c->req('POST', '/api/login.php', ['username' => 'qa_nosave_stu', 'password' => $pw, 'portal' => 'student']); $c->csrf = null;
    check('the test student signs in', $r['code'] === 200, (string) $r['code']);
    $r = $c->req('GET', '/api/recommendations.php');
    $j = json_decode($r['body'], true) ?: [];
    check('the results API answers 200 with a recommendation', $r['code'] === 200 && ($j['hasRecommendation'] ?? false) === true, $r['code'] . ' ' . substr($r['body'], 0, 120));

    echo "\n=== The feature is gone ===\n";
    $r = $c->req('GET', '/saved-careers');
    check('the Saved Careers page is not found (404)', $r['code'] === 404, (string) $r['code']);
    $r = $c->req('GET', '/api/saved-programs.php');
    check('GET api/saved-programs.php is not found (404)', $r['code'] === 404, (string) $r['code']);
    $r = $c->req('POST', '/api/saved-programs.php', ['programId' => 1]);
    check('POST api/saved-programs.php is not found (404)', $r['code'] === 404, (string) $r['code']);
    foreach (['assessment', 'assessment-instructions', 'riasec-assessment', 'career-worksheet', 'results', 'worksheet-results',
              'student-help-center', 'student-notifications', 'student-settings'] as $page) {
        $r = $c->req('GET', '/' . $page);
        check("$page opens (200) and has no Saved Careers link or button",
            $r['code'] === 200 && stripos($r['body'], 'saved-careers') === false && stripos($r['body'], 'Saved Careers') === false
            && stripos($r['body'], 'saved-programs') === false && stripos($r['body'], 'fav-btn') === false, (string) $r['code']);
    }
} finally {
    foreach (['DELETE FROM monitoring_flags WHERE student_id = ?', 'DELETE FROM recommendations WHERE student_id = ?', 'DELETE FROM assessments WHERE student_id = ?',
              'DELETE FROM audit_log WHERE actor_user_id = ?', 'DELETE FROM sessions WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
        try { $pdo->prepare($sql)->execute([$sid]); } catch (PDOException $e) { echo "  cleanup note: " . $e->getMessage() . "\n"; }
    }
}
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
