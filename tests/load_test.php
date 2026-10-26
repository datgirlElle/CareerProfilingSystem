<?php
// Staged load test of the student exam-day flow and the staff dashboards.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php          (same single-process server the live site uses)
//   DB_NAME=profilepath_test php tests/load_test.php http://127.0.0.1:8099 [serverPid] [stages e.g. 1,5,10,25,50]
// Runs only against a *_test database and a local address. Uses synthetic students named qa_load_NNN.
// Each stage fires N requests at the same moment (curl_multi) for each step, so the numbers show how the
// server copes with N students doing the same thing together, e.g. everybody pressing Submit at once.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) { fwrite(STDERR, "Refusing to run: only a local server is allowed\n"); exit(2); }
$pid = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : null;
$stages = array_map('intval', explode(',', $argv[3] ?? '1,5,10,25,50'));
$maxN = max($stages);
$pdo = Database::get();
$ay = AcademicYear::current();

// ---------------------------------------------------------------- setup
$hash = password_hash('Load-Test-1!', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM exam_schedules WHERE room = 'QA-LOAD'");
$pdo->prepare("INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code) VALUES (?, CURRENT_DATE, '00:00', '23:59', 'QA-LOAD', '11', 'STEM', 'S1193', 'LOAD01')")->execute([$ay]);
$ids = [];
for ($i = 1; $i <= $maxN; $i++) {
    $u = sprintf('qa_load_%03d', $i);
    $row = $pdo->query("SELECT id FROM users WHERE username = " . $pdo->quote($u))->fetchColumn();
    if (!$row) {
        $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at) VALUES ('student', ?, ?, ?, NOW()) RETURNING id");
        $s->execute([$u, $hash, "$u@example.test"]); $row = (int) $s->fetchColumn();
        $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?,?,?,?, 'STEM', '11', 'S1193', ?)")
            ->execute([$row, $u, Crypto::enc('Load'), Crypto::enc($u), $ay]);
    }
    $ids[$i] = (int) $row;
}
$resetStage = function () use ($pdo, $ids) {
    $in = implode(',', $ids);
    $pdo->exec("DELETE FROM monitoring_flags WHERE student_id IN ($in)");
    $pdo->exec("DELETE FROM recommendations WHERE student_id IN ($in)");
    $pdo->exec("DELETE FROM worksheets WHERE student_id IN ($in)");
    $pdo->exec("DELETE FROM assessments WHERE student_id IN ($in)");
    $pdo->exec("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id IN ($in)");
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'access-code:%'");
};

// ---------------------------------------------------------------- helpers
function pct(array $v, float $p): float { sort($v); $n = count($v); return $n ? $v[max(0, (int) ceil($p * $n) - 1)] : 0.0; }
function stats(array $times): array { $n = count($times); return ['avg' => $n ? array_sum($times) / $n : 0, 'median' => pct($times, 0.5), 'p95' => pct($times, 0.95), 'max' => $n ? max($times) : 0]; }
function procInfo(?int $pid): ?array {
    if (!$pid) { return null; }
    $out = shell_exec('powershell -NoProfile -Command "$p = Get-Process -Id ' . $pid . '; \"$($p.TotalProcessorTime.TotalSeconds) $($p.WorkingSet64)\""');
    if (!$out || !preg_match('/^([\d.,]+)\s+(\d+)/', trim($out), $m)) { return null; }
    return ['cpu' => (float) str_replace(',', '.', $m[1]), 'mem' => (int) $m[2]];
}
/** Sends one request per client at the same time. $make(i) returns [method, path, body|null]. Returns per-request results. */
function wave(string $base, array &$clients, callable $make): array {
    $mh = curl_multi_init(); $handles = [];
    foreach ($clients as $i => &$c) {
        [$method, $path, $body] = $make($i);
        $ch = curl_init($base . $path);
        $h = ['Content-Type: application/json'];
        if (!empty($c['csrf'])) { $h[] = 'X-CSRF-Token: ' . $c['csrf']; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $c['jar'], CURLOPT_COOKIEFILE => $c['jar'], CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 30]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
    }
    unset($c);
    $start = microtime(true);
    do { $st = curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 0.2); } } while ($running && $st === CURLM_OK);
    $wall = microtime(true) - $start; $res = [];
    foreach ($handles as $i => $ch) {
        $res[$i] = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME), 'body' => (string) curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return ['wall' => $wall, 'res' => $res];
}

$report = []; $allErrors = 0; $allRequests = 0;
printf("Load test against %s (database %s). Stages: %s students at the same moment.\n", $base, envValue('DB_NAME'), implode(', ', $stages));
foreach ($stages as $N) {
    $resetStage();
    $clients = [];
    for ($i = 1; $i <= $N; $i++) { $clients[$i] = ['jar' => tempnam(sys_get_temp_dir(), 'ld'), 'csrf' => null, 'user' => sprintf('qa_load_%03d', $i)]; }
    $p0 = procInfo($pid); $stageStart = microtime(true);
    $steps = [];
    $run = function (string $name, callable $make, int $okCode = 200, ?callable $after = null) use ($base, &$clients, &$steps, &$allErrors, &$allRequests) {
        $w = wave($base, $clients, $make);
        $times = []; $ok = 0;
        foreach ($w['res'] as $i => $r) {
            $times[] = $r['time'];
            $good = $r['code'] === $okCode;
            if ($good && $after) { $good = $after($i, $r) !== false; }
            $good ? $ok++ : $allErrors++;
            $allRequests++;
        }
        $steps[$name] = ['n' => count($times), 'ok' => $ok] + stats($times) + ['wall' => $w['wall']];
        return $w;
    };
    $run('Open page (session + CSRF token)', fn($i) => ['GET', '/api/session.php', null], 200, function ($i, $r) use (&$clients) { $j = json_decode($r['body'], true); $clients[$i]['csrf'] = $j['csrfToken'] ?? null; return $clients[$i]['csrf'] !== null; });
    $run('Sign in (bcrypt)', fn($i) => ['POST', '/api/login.php', ['username' => $clients[$i]['user'], 'password' => 'Load-Test-1!', 'portal' => 'student']], 200, fn($i, $r) => (json_decode($r['body'], true)['success'] ?? false) === true);
    $run('Enter access code', fn($i) => ['POST', '/api/verify-access-code.php', ['code' => 'LOAD01']], 200, fn($i, $r) => (json_decode($r['body'], true)['success'] ?? false) === true);
    $qs = null;
    $run('Load the 30 questions', fn($i) => ['GET', '/api/assessment-questions.php', null], 200, function ($i, $r) use (&$qs) { $j = json_decode($r['body'], true); $qs = $qs ?? count($j['questions'] ?? []); return ($j['questions'] ?? null) !== null; });
    $answers = array_fill(0, max(1, (int) $qs), 4);
    $run('Submit the assessment (all at once)', fn($i) => ['POST', '/api/assessment-submit.php', ['answers' => $answers]], 200, fn($i, $r) => (json_decode($r['body'], true)['success'] ?? false) === true);
    $run('Submit the Career Worksheet', fn($i) => ['POST', '/api/worksheet-submit.php', ['career' => 'Software Engineer', 'electives' => ['Computer Programming (JAVA)']]], 200, fn($i, $r) => (json_decode($r['body'], true)['success'] ?? false) === true);
    $run('Open Career Results', fn($i) => ['GET', '/api/recommendations.php', null], 200, fn($i, $r) => (json_decode($r['body'], true)['hasRecommendation'] ?? false) === true);

    // Staff: administrator opens the heavy dashboards while students are still active.
    $admin = [1 => ['jar' => tempnam(sys_get_temp_dir(), 'ad'), 'csrf' => null]];
    $w = wave($base, $admin, fn($i) => ['GET', '/api/session.php', null]); $admin[1]['csrf'] = json_decode($w['res'][1]['body'], true)['csrfToken'] ?? null;
    wave($base, $admin, fn($i) => ['POST', '/api/login.php', ['username' => 'admin', 'password' => 'ChangeMe123!', 'portal' => 'staff']]);
    $staffN = min(10, max(2, (int) ceil($N / 5))); $staff = [];
    for ($k = 1; $k <= $staffN; $k++) { $staff[$k] = ['jar' => $admin[1]['jar'], 'csrf' => $admin[1]['csrf']]; }
    $paths = ['/api/students.php?all=1', '/api/analytics.php?academicYear=' . urlencode($ay), '/api/monitoring.php', '/api/audit-log.php?limit=50', '/api/students.php?all=1&format=csv'];
    $w = wave($base, $staff, fn($k) => ['GET', $paths[($k - 1) % count($paths)], null]);
    $times = []; $ok = 0; foreach ($w['res'] as $r) { $times[] = $r['time']; $r['code'] === 200 ? $ok++ : $allErrors++; $allRequests++; }
    $steps["Staff dashboards ($staffN staff at once)"] = ['n' => count($times), 'ok' => $ok] + stats($times) + ['wall' => $w['wall']];

    $p1 = procInfo($pid); $elapsed = microtime(true) - $stageStart;
    $report[$N] = ['steps' => $steps, 'elapsed' => $elapsed, 'cpu' => $p0 && $p1 ? $p1['cpu'] - $p0['cpu'] : null, 'mem' => $p1['mem'] ?? null];
    printf("\n=== %d students at the same moment (stage took %.1f s%s) ===\n", $N, $elapsed, $p0 && $p1 ? sprintf('; server CPU %.1f s, memory %.0f MB', $p1['cpu'] - $p0['cpu'], $p1['mem'] / 1048576) : '');
    printf("%-44s %5s %5s %9s %9s %9s %9s\n", 'Step', 'sent', 'ok', 'avg ms', 'median', 'p95 ms', 'max ms');
    foreach ($steps as $name => $s) { printf("%-44s %5d %5d %9.0f %9.0f %9.0f %9.0f\n", $name, $s['n'], $s['ok'], $s['avg'] * 1000, $s['median'] * 1000, $s['p95'] * 1000, $s['max'] * 1000); }
    // Stop climbing if the server is clearly failing, so a shared machine is not hammered.
    $failed = array_sum(array_map(fn($s) => $s['n'] - $s['ok'], $steps)); $sent = array_sum(array_column($steps, 'n'));
    if ($sent && $failed / $sent > 0.25) { echo "\nStopping: more than 25% of requests failed in this stage.\n"; break; }
    foreach ($clients as $c) { @unlink($c['jar']); }
}
$resetStage();
$pdo->exec("DELETE FROM exam_schedules WHERE room = 'QA-LOAD'");
printf("\nTotal requests: %d, failed: %d (%.1f%%)\n", $allRequests, $allErrors, $allRequests ? 100 * $allErrors / $allRequests : 0);
file_put_contents(sys_get_temp_dir() . '/load_report.json', json_encode($report));
exit($allErrors ? 1 : 0);
