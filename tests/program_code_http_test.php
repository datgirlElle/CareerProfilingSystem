<?php
// Changing a program's Holland code on the Career Dataset page: the audit log records old -> new,
// every saved recommendation is recomputed with the new code, and invalid or unauthorised edits change nothing.
//   DB_NAME=x_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=x_test php tests/program_code_http_test.php http://127.0.0.1:8099
// Uses the seeded test-database admin, a synthetic program and the test database's students only;
// refuses to run unless DB_NAME ends in _test. Needs at least one student with a Career Worksheet.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/AuditLog/lib/AuditChange.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'pc'); }
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) { $this->csrf = $this->raw('GET', '/api/session.php')['json']['csrfToken'] ?? null; }
        return $this->raw($method, $path, $body, true);
    }
    function raw(string $method, string $path, $body = null, bool $csrf = false): array {
        $ch = curl_init($this->base . $path);
        $h = ['Content-Type: application/json'];
        if ($csrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $r = (string) curl_exec($ch);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($r, true) ?? []];
    }
    function login(string $u, string $p, string $portal = 'staff'): int { $this->csrf = null; $c = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal])['code']; $this->csrf = null; return $c; }
}

$title = 'QA Code Change Program';
$findProgram = function () use ($pdo, $title): ?int {
    foreach ($pdo->query('SELECT id, title_enc FROM programs')->fetchAll() as $r) {
        if (Crypto::dec($r['title_enc']) === $title) { return (int) $r['id']; }
    }
    return null;
};
$recCount = fn() => (int) $pdo->query('SELECT COUNT(*) FROM recommendations')->fetchColumn();
$withWorksheet = (int) $pdo->query(
    'SELECT COUNT(*) FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     WHERE EXISTS (SELECT 1 FROM worksheets w WHERE w.student_id = s.user_id)'
)->fetchColumn();
$lastAudit = function (string $action, int $programId) use ($pdo): ?string {
    $st = $pdo->prepare("SELECT detail_enc FROM audit_log WHERE action = ? AND target_type = 'program' AND target_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$action, (string) $programId]);
    $enc = $st->fetchColumn();
    return $enc ? Crypto::dec($enc) : null;
};

// ---- synthetic program (letters A, E, C: never the best match for the test students' S/I/R profiles) ----
if ($old = $findProgram()) { $pdo->prepare("UPDATE programs SET status = 'Inactive' WHERE id = ?")->execute([$old]); }
$collegeId = (int) $pdo->query('SELECT MIN(id) FROM colleges')->fetchColumn();
$ins = $pdo->prepare("INSERT INTO programs (college_id, title_enc, holland_code_enc, description_enc, careers, status) VALUES (?, ?, ?, NULL, '{}', 'Active') RETURNING id");
$ins->execute([$collegeId, Crypto::enc($title), Crypto::enc('AEC')]);
$pid = (int) $ins->fetchColumn();
$payload = fn(string $code, string $desc = '') => ['id' => $pid, 'title' => $title, 'collegeId' => $collegeId, 'hollandCode' => $code,
    'status' => 'Active', 'description' => $desc, 'careers' => ['Synthetic Career']];

echo "=== Access ===\n";
$anon = new Client($base);
check('not signed in -> refused', in_array($anon->req('PUT', '/api/programs.php', $payload('ECA'))['code'], [401, 403], true));
$a = new Client($base);
check('admin login', $a->login('admin', 'ChangeMe123!') === 200);
// The server must use this same _test database: it has to see the program inserted above. Stop before any write otherwise.
$serverSees = in_array($pid, array_map(fn($p) => (int) $p['id'], array_filter($a->req('GET', '/api/programs.php?all=1')['json']['programs'] ?? [],
    fn($p) => $p['title'] === $title)), true);
check('the server uses this test database (it sees the synthetic program)', $serverSees);
if (!$serverSees) {
    fwrite(STDERR, "Stopping: the server at $base is not using DB_NAME=" . envValue('DB_NAME') . "\n");
    $pdo->prepare('DELETE FROM programs WHERE id = ?')->execute([$pid]);
    exit(2);
}
check("test data has students with a worksheet ($withWorksheet)", $withWorksheet > 0);

echo "\n=== Who may edit: admin and Guidance Counselors yes, Facilitators view only ===\n";
$staffAccount = function (string $username, string $position) use ($pdo): void {
    $hash = password_hash('QaPc-Pass-2026!', PASSWORD_BCRYPT);
    $st = $pdo->prepare("UPDATE users SET password_hash = ?, is_active = TRUE, failed_login_attempts = 0, locked_until = NULL WHERE username = ?");
    $st->execute([$hash, $username]);
    if ($st->rowCount() === 0) {
        $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position)
                       VALUES ('counselor', ?, ?, ?, NOW(), 'approved', ?, ?)")
            ->execute([$username, $hash, "$username@example.invalid", 'QA ' . ucfirst($position), $position]);
    }
};
$staffAccount('qa_pc_counselor', 'counselor');
$staffAccount('qa_pc_facilitator', 'facilitator');
$canEdit = fn(Client $c) => $c->req('GET', '/api/programs.php?all=1')['json']['canEdit'] ?? null;
check('admin: page shows the edit controls (canEdit)', $canEdit($a) === true);
$co = new Client($base);
check('Guidance Counselor login', $co->login('qa_pc_counselor', 'QaPc-Pass-2026!') === 200);
check('Guidance Counselor: canEdit', $canEdit($co) === true);
$r = $co->req('PUT', '/api/programs.php', $payload('AEC', 'Edited by a counselor'));
check('Guidance Counselor can save a program edit', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
$fa = new Client($base);
check('Facilitator login', $fa->login('qa_pc_facilitator', 'QaPc-Pass-2026!') === 200);
check('Facilitator: can view the list but canEdit is false', $canEdit($fa) === false);
$r = $fa->req('PUT', '/api/programs.php', $payload('ECA', 'Edited by a facilitator'));
check('Facilitator cannot edit a program (403)', $r['code'] === 403, (string) $r['code']);
$r = $fa->req('POST', '/api/programs.php', ['type' => 'toggleActive', 'id' => $pid]);
check('Facilitator cannot deactivate a program (403)', $r['code'] === 403, (string) $r['code']);
$st = $pdo->prepare('SELECT holland_code_enc FROM programs WHERE id = ?');
$st->execute([$pid]);
check('the facilitator\'s attempt changed nothing (code still AEC)', Crypto::dec((string) $st->fetchColumn()) === 'AEC');

echo "\n=== Edit without a code change: audit records what changed, nothing recomputed ===\n";
$before = $recCount();
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description'));
check('saved', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
check('reported as no code change', ($r['json']['hollandCodeChanged'] ?? null) === false);
check('no recommendations recomputed', $recCount() === $before);
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$fields = array_column($detail['changes'] ?? [], 'field');
check('audit: "Description" listed as changed, Holland code not', in_array('Description', $fields, true)
    && !in_array('Holland code', $fields, true), json_encode($fields));

echo "\n=== Related SHS strands: optional, saved, returned, audited, never recomputed ===\n";
$strandsOf = function () use ($a, $pid): ?array {
    foreach ($a->req('GET', '/api/programs.php?all=1')['json']['programs'] ?? [] as $p) {
        if ((int) $p['id'] === $pid) { return $p['relatedStrands'] ?? null; }
    }
    return null;
};
check('starts with no strands', $strandsOf() === []);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => ['ict', 'STEM']]);
check('saving STEM and ICT accepted', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
check('returned as saved (allowed list order)', $strandsOf() === ['STEM', 'ICT'], json_encode($strandsOf()));
check('no recommendations recomputed for a strand change', $recCount() === $before);
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$st = array_values(array_filter($detail['changes'] ?? [], fn($c) => $c['field'] === 'Related SHS strands'));
check('audit: "Related SHS strands" before (none) and after STEM, ICT', isset($st[0]) && $st[0]['after'] === 'STEM, ICT', json_encode($st));
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description'));
check('an edit that does not send strands keeps them', $r['code'] === 200 && $strandsOf() === ['STEM', 'ICT']);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => ['STEM', 'XYZ']]);
check('unknown strand XYZ -> 400, nothing changed', $r['code'] === 400 && $strandsOf() === ['STEM', 'ICT'], (string) $r['code']);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => []]);
check('clearing all strands saved', $r['code'] === 200 && $strandsOf() === []);

echo "\n=== Invalid codes are refused and change nothing ===\n";
foreach (['RRC' => 'repeated letter', 'RIX' => 'letter outside RIASEC', 'RI' => 'two letters'] as $code => $why) {
    $r = $a->req('PUT', '/api/programs.php', $payload($code));
    check("$code ($why) -> 400", $r['code'] === 400, (string) $r['code']);
}
check('no recommendations recomputed by refused edits', $recCount() === $before);

echo "\n=== Code change: audit old -> new, every saved recommendation recomputed ===\n";
$r = $a->req('PUT', '/api/programs.php', $payload('ECA', 'Synthetic description'));
$j = $r['json'];
check('saved', $r['code'] === 200 && ($j['success'] ?? false), json_encode($j));
check('reported: AEC -> ECA', ($j['hollandCodeChanged'] ?? null) === true && ($j['previousHollandCode'] ?? null) === 'AEC' && ($j['hollandCode'] ?? null) === 'ECA');
check("every student with a worksheet recomputed ($withWorksheet), none failed",
    ($j['recomputed'] ?? null) === $withWorksheet && ($j['recomputeFailed'] ?? null) === 0, json_encode($j));
check('one new recommendation row per student (older rows kept)', $recCount() === $before + $withWorksheet);
$latest = $pdo->query('SELECT DISTINCT ON (student_id) scores FROM recommendations ORDER BY student_id, computed_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN);
$codes = [];
foreach ($latest as $json) {
    foreach (json_decode($json, true) ?: [] as $sc) {
        if ((int) $sc['programId'] === $pid) { $codes[] = $sc['hollandCode']; }
    }
}
check('every latest result uses the new code ECA', count($codes) === $withWorksheet && array_unique($codes) === ['ECA'], json_encode(array_unique($codes)));
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$hc = array_values(array_filter($detail['changes'] ?? [], fn($c) => $c['field'] === 'Holland code'));
check('audit: "Holland code: AEC -> ECA"', isset($hc[0]) && $hc[0]['before'] === 'AEC' && $hc[0]['after'] === 'ECA', json_encode($hc));
$rc = (string) $lastAudit('recompute_recommendations', $pid);
check('audit: recompute entry names the change and the count', str_contains($rc, 'AEC -> ECA') && str_contains($rc, "$withWorksheet students"), $rc);

echo "\n=== Program list changes: add, deactivate, reactivate, status edit -> recompute ===\n";
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
$latestScores = function () use ($pdo): array {   // student_id => [programId => hollandCode] of the latest saved result
    $out = [];
    foreach ($pdo->query('SELECT DISTINCT ON (student_id) student_id, scores FROM recommendations ORDER BY student_id, computed_at DESC, id DESC')->fetchAll() as $row) {
        $out[(int) $row['student_id']] = array_column(json_decode($row['scores'], true) ?: [], 'hollandCode', 'programId');
    }
    return $out;
};
$assessmentsBefore = $pdo->query('SELECT id, student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, question_set_id, scoring_version FROM assessments ORDER BY id')->fetchAll();
$historyBefore = $pdo->query('SELECT id, scores FROM recommendations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$studentWithWs = (int) $pdo->query(
    'SELECT s.user_id FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     WHERE EXISTS (SELECT 1 FROM worksheets w WHERE w.student_id = s.user_id) ORDER BY s.user_id LIMIT 1'
)->fetchColumn();
$recFor = fn() => $a->req('GET', '/api/recommendations.php?studentId=' . $studentWithWs)['json'];
$allRecommended = function (array $j): array {
    return array_map(fn($p) => (int) $p['id'], array_merge($j['bestRiasecMatch'] ?? [], $j['alternatives'] ?? []));
};

$before = $recCount();
$r = $a->req('POST', '/api/programs.php', ['type' => 'toggleActive', 'id' => $pid]);
$j = $r['json'];
check('deactivate: saved and reported Inactive', $r['code'] === 200 && ($j['status'] ?? null) === 'Inactive', json_encode($j));
check("deactivate: every student with a worksheet recomputed ($withWorksheet), none failed",
    ($j['recomputed'] ?? null) === $withWorksheet && ($j['recomputeFailed'] ?? null) === 0, json_encode($j));
check('deactivate: one new row per student, older rows kept', $recCount() === $before + $withWorksheet);
check('deactivate: no latest result compares the inactive program', !array_filter($latestScores(), fn($s) => isset($s[$pid])));
$rc = (string) $lastAudit('recompute_recommendations', $pid);
check('deactivate: audit names the change and the count', str_contains($rc, "Deactivated $title") && str_contains($rc, "$withWorksheet students"), $rc);
$j = $recFor();
check('results page: up to date (needsRecompute false), inactive program not recommended',
    ($j['needsRecompute'] ?? null) === false && !in_array($pid, $allRecommended($j), true), json_encode(['needs' => $j['needsRecompute'] ?? null]));

$r = $a->req('POST', '/api/programs.php', ['type' => 'toggleActive', 'id' => $pid]);
$j = $r['json'];
check('reactivate: Active, everyone recomputed', ($j['status'] ?? null) === 'Active' && ($j['recomputed'] ?? null) === $withWorksheet && ($j['recomputeFailed'] ?? null) === 0, json_encode($j));
check('reactivate: every latest result compares it again, with its code ECA', count(array_filter($latestScores(), fn($s) => ($s[$pid] ?? null) === 'ECA')) === $withWorksheet);

$r = $a->req('PUT', '/api/programs.php', ['status' => 'Inactive'] + $payload('ECA', 'Synthetic description'));
$j = $r['json'];
check('edit form status -> Inactive: statusChanged, recomputed, no code change',
    ($j['statusChanged'] ?? null) === true && ($j['hollandCodeChanged'] ?? null) === false && ($j['recomputed'] ?? null) === $withWorksheet, json_encode($j));
check('... the program is gone from every latest result', !array_filter($latestScores(), fn($s) => isset($s[$pid])));
$r = $a->req('PUT', '/api/programs.php', $payload('ECA', 'Synthetic description'));
check('edit form status -> Active again: recomputed', ($r['json']['statusChanged'] ?? null) === true && ($r['json']['recomputed'] ?? null) === $withWorksheet, json_encode($r['json']));

// A new Active program that matches the test students' S/I/R profiles.
$newTitle = 'QA Added Program';
$before = $recCount();
$r = $a->req('POST', '/api/programs.php', ['title' => $newTitle, 'collegeId' => $collegeId, 'hollandCode' => 'SIR', 'status' => 'Active',
    'description' => '', 'careers' => ['Synthetic Career']]);
$j = $r['json'];
$newId = (int) ($j['id'] ?? 0);
check('add Active program: saved, everyone recomputed', $r['code'] === 200 && $newId > 0 && ($j['recomputed'] ?? null) === $withWorksheet && ($j['recomputeFailed'] ?? null) === 0, json_encode($j));
check('add: every latest result compares the new program', count(array_filter($latestScores(), fn($s) => ($s[$newId] ?? null) === 'SIR')) === $withWorksheet);
$j = $recFor();
$profile = CBFData::studentProfile($pdo, $studentWithWs);
$expected = CBFEngine::compute($profile['riasec'], CBFData::activePrograms($pdo));
check('results page Best Match = CBFEngine on the current program list (same implementation)',
    array_map(fn($p) => (int) $p['id'], $j['bestRiasecMatch'] ?? []) == CBFEngine::orderByLetterRank($expected['candidates'],
        array_column(CBFData::activePrograms($pdo), 'hollandCode', 'id'), $profile['riasec']), json_encode(array_column($j['bestRiasecMatch'] ?? [], 'id')));
$before = $recCount();
$r = $a->req('POST', '/api/programs.php', ['title' => 'QA Added Inactive Program', 'collegeId' => $collegeId, 'hollandCode' => 'SIA', 'status' => 'Inactive',
    'description' => '', 'careers' => ['Synthetic Career']]);
$inactiveNewId = (int) ($r['json']['id'] ?? 0);
check('add Inactive program: saved, not compared, nothing recomputed', $r['code'] === 200 && !isset($r['json']['recomputed']) && $recCount() === $before, json_encode($r['json']));

echo "\n=== A change made outside the page (e.g. a db/ script) is reported as stale ===\n";
$setCode = $pdo->prepare('UPDATE programs SET holland_code_enc = ? WHERE id = ?');
$setCode->execute([Crypto::enc('IRS'), $newId]);   // no recompute
check('results page: needsRecompute true after an unrecomputed code change', ($recFor()['needsRecompute'] ?? null) === true);
$res = CBFData::recomputeAll($pdo);
check('db/recompute_recommendations.php path (recomputeAll) clears it', $res['failed'] === 0 && ($recFor()['needsRecompute'] ?? null) === false);

echo "\n=== No Active program at all ===\n";
$activeIds = $pdo->query("SELECT id FROM programs WHERE status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
$before = $recCount();
$pdo->exec("UPDATE programs SET status = 'Inactive' WHERE status = 'Active'");
$res = CBFData::recomputeAll($pdo);
check("nothing saved, every student counted as failed ($withWorksheet)", $res['recomputed'] === 0 && $res['failed'] === $withWorksheet && $recCount() === $before, json_encode($res));
check('results page reports the old result as needing a recompute', ($recFor()['needsRecompute'] ?? null) === true);
$pdo->prepare('UPDATE programs SET status = \'Active\' WHERE id IN (' . implode(',', array_map('intval', $activeIds)) . ')')->execute();
$res = CBFData::recomputeAll($pdo);
check('programs restored and recomputed', $res['failed'] === 0 && ($recFor()['needsRecompute'] ?? null) === false);

echo "\n=== History and the endpoint contract ===\n";
check('assessments untouched (scores, attempts, question sets, scoring version)',
    $pdo->query('SELECT id, student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, question_set_id, scoring_version FROM assessments ORDER BY id')->fetchAll() === $assessmentsBefore);
$historyNow = $pdo->query('SELECT id, scores FROM recommendations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
check('every earlier recommendation row kept unchanged', array_intersect_key($historyNow, $historyBefore) === $historyBefore);
$bad = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT ON (student_id) student_id, source_assessment_id FROM recommendations ORDER BY student_id, computed_at DESC, id DESC) l
    JOIN assessments a ON a.id = l.source_assessment_id WHERE a.is_latest = FALSE')->fetchColumn();
check('every latest result was computed from the student\'s latest assessment', $bad === 0);
$j = $recFor();
$keys = ['hasRecommendation', 'computedAt', 'bestRiasecMatch', 'alternatives', 'preferredCourse', 'finalBestMatch', 'sources', 'isComplete',
    'statedProgramId', 'statedCareer', 'electives', 'matchStatus', 'mismatchReason', 'needsRecompute', 'riasecProfile', 'prediction', 'cbf', 'predictionDetail'];
check('staff response keeps every field the pages read', !array_diff($keys, array_keys($j)), json_encode(array_values(array_diff($keys, array_keys($j)))));
check('each recommended program carries its cosine (0-1) and isActive', !array_filter(array_merge($j['bestRiasecMatch'], $j['alternatives']),
    fn($p) => !isset($p['cosineSimilarity'], $p['isActive']) || $p['cosineSimilarity'] < 0 || $p['cosineSimilarity'] > 1));

echo "\n=== Clean up ===\n";
foreach ([$newId, $inactiveNewId] as $extra) {
    if ($extra > 0) {
        $pdo->prepare("UPDATE programs SET status = 'Inactive' WHERE id = ?")->execute([$extra]);
    }
}
$pdo->prepare("UPDATE programs SET status = 'Inactive' WHERE id = ?")->execute([$pid]);
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
$res = CBFData::recomputeAll($pdo);   // latest results no longer include the synthetic program
check('results recomputed without the synthetic program', $res['failed'] === 0);
foreach ([$pid, $newId, $inactiveNewId] as $synthetic) {
    try {
        $pdo->prepare('DELETE FROM programs WHERE id = ?')->execute([$synthetic]);
        echo "  synthetic program $synthetic deleted\n";
    } catch (PDOException $e) {
        echo "  synthetic program $synthetic kept as Inactive (referenced by saved history)\n";
    }
}

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
