<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Sections.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    if (($body['type'] ?? '') === 'toggleActive') {
        $targetId = (int) ($body['userId'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $newState = !$row['is_active'];
        // PDOStatement::execute() stringifies a bound PHP bool — false
        // becomes '' (not '0'), which Postgres's boolean type rejects
        // outright ("invalid input syntax for type boolean"). Bind an int
        // instead; Postgres accepts 0/1 for boolean.
        $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $targetId]);
        // login.php already rejects any user (any role) with is_active =
        // false, so deactivating here immediately blocks sign-in -- no
        // separate enforcement needed.
        AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_student_account' : 'deactivate_student_account', 'user', (string) $targetId);
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }
    // Correct a student's section after registration. Only sections that belong
    // to the student's own strand are accepted (same allow-list as registration).
    // Exam gating and notifications read students.section on every request, so
    // the change applies immediately.
    if (($body['type'] ?? '') === 'updateSection') {
        $targetId = (int) ($body['userId'] ?? 0);
        $section = trim((string) ($body['section'] ?? ''));
        $stmt = $pdo->prepare('SELECT strand, section FROM students WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $student = $stmt->fetch();
        if (!$student) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        if (!Sections::isValid($pdo, $student['strand'], $section)) {
            jsonResponse(['success' => false, 'error' => 'Invalid section for this student\'s strand.'], 400);
        }
        if ($student['section'] !== $section) {
            $pdo->prepare('UPDATE students SET section = ? WHERE user_id = ?')->execute([$section, $targetId]);
            AuditLogger::log($user['id'], $user['role'], 'update_student_section', 'user', (string) $targetId, "Section: {$student['section']} -> $section");
        }
        jsonResponse(['success' => true, 'section' => $section]);
    }
    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Single-student lookup mode: student-profile.html loads a real record by
// schoolId instead of everything being smuggled through URL query params.
$schoolIdLookup = trim((string) ($_GET['schoolId'] ?? ''));
if ($schoolIdLookup !== '') {
    $stmt = $pdo->prepare(
        'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section,
                s.academic_year, s.registered_at, u.is_active, a.top_types, a.completed_at, a.score_r, a.score_i, a.score_a,
                a.score_s, a.score_e, a.score_c
         FROM students s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
         WHERE LOWER(s.school_id) = LOWER(?)'
    );
    $stmt->execute([$schoolIdLookup]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['error' => 'Student not found'], 404);
    }
    $hasAssessment = $row['completed_at'] !== null;
    // subject is encrypted (subject_enc), so it can't be matched with a
    // plain SQL WHERE clause — decrypt and compare in PHP instead. Cheap
    // here since it's scoped to one student's own requests.
    $counseledStmt = $pdo->prepare('SELECT subject_enc FROM help_requests WHERE student_id = ?');
    $counseledStmt->execute([(int) $row['user_id']]);
    $counseled = false;
    foreach ($counseledStmt->fetchAll(PDO::FETCH_COLUMN) as $subjectEnc) {
        if (Crypto::dec($subjectEnc) === 'Request for Academic Advising') {
            $counseled = true;
            break;
        }
    }

    // Full attempt history (not just is_latest), for the "Assessment
    // Attempts" section — cross-referenced with retake_grants so a retake
    // attempt is labeled as such, matching Phase 4's retake workflow.
    $attemptsStmt = $pdo->prepare(
        'SELECT id, attempt_number, top_types, completed_at, score_r, score_i, score_a, score_s, score_e, score_c
         FROM assessments WHERE student_id = ? ORDER BY attempt_number DESC'
    );
    $attemptsStmt->execute([(int) $row['user_id']]);
    $retakeAttemptNumbers = $pdo->prepare(
        'SELECT completed_attempt_number FROM retake_grants WHERE student_id = ? AND completed_attempt_number IS NOT NULL'
    );
    $retakeAttemptNumbers->execute([(int) $row['user_id']]);
    $retakeSet = array_flip(array_map('intval', $retakeAttemptNumbers->fetchAll(PDO::FETCH_COLUMN)));

    $attempts = array_map(fn($a) => [
        'attemptNumber' => (int) $a['attempt_number'],
        'completedAt' => $a['completed_at'],
        'riasec' => implode(', ', json_decode($a['top_types'], true)),
        'scores' => [
            'R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
            'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c'],
        ],
        'isRetake' => isset($retakeSet[(int) $a['attempt_number']]),
    ], $attemptsStmt->fetchAll());

    jsonResponse(['student' => [
        'userId' => (int) $row['user_id'],
        'schoolId' => $row['school_id'],
        'firstName' => Crypto::dec($row['first_name_enc']),
        'lastName' => Crypto::dec($row['last_name_enc']),
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
        'gradeLevel' => $row['grade_level'],
        'section' => $row['section'],
        'allowedSections' => (function () use ($pdo, $row) {
            $sections = Sections::byStrand($pdo)[$row['strand']] ?? [];
            // Keep the student's current section selectable even if it's
            // since been deactivated — otherwise the dropdown silently
            // wouldn't offer their own existing value.
            if ($row['section'] && !in_array($row['section'], $sections, true)) {
                $sections[] = $row['section'];
            }
            return $sections;
        })(),
        'academicYear' => $row['academic_year'],
        'isActive' => (bool) $row['is_active'],
        'status' => $hasAssessment ? 'Completed' : 'Pending',
        'riasec' => $hasAssessment ? implode(', ', json_decode($row['top_types'], true)) : '',
        'scores' => $hasAssessment ? [
            'R' => (int) $row['score_r'], 'I' => (int) $row['score_i'], 'A' => (int) $row['score_a'],
            'S' => (int) $row['score_s'], 'E' => (int) $row['score_e'], 'C' => (int) $row['score_c'],
        ] : null,
        'counseling' => $hasAssessment ? ($counseled ? 'Availed' : 'Did Not Avail') : '',
        'registeredAt' => $row['registered_at'],
        'attempts' => $attempts,
    ]]);
}

// ?accounts=1: the Student Accounts page (login/activation status only, no
// assessment/counseling data) — a separate view from the Student Assessment
// Overview above, per the split the counselors asked for.
if (isset($_GET['accounts'])) {
    $search = trim((string) ($_GET['search'] ?? ''));
    $rows = $pdo->query(
        'SELECT u.id AS user_id, u.username, u.email, u.is_active, u.created_at,
                s.first_name_enc, s.last_name_enc, s.strand, s.section
         FROM users u
         JOIN students s ON s.user_id = u.id
         ORDER BY u.created_at DESC'
    )->fetchAll();

    $accounts = array_map(fn($r) => [
        'userId' => (int) $r['user_id'],
        'username' => $r['username'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'section' => $r['section'],
        'email' => $r['email'],
        'isActive' => (bool) $r['is_active'],
        'createdAt' => $r['created_at'],
    ], $rows);

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $accounts = array_values(array_filter($accounts, fn($a) =>
            str_contains(mb_strtolower($a['name']), $needle) || str_contains(mb_strtolower($a['username']), $needle)
        ));
    }

    jsonResponse(['accounts' => $accounts, 'total' => count($accounts)]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// ?all=1 (used by the Announcements student-picker) returns everyone
// matching the filters in one response instead of paginating.
$pageSize = isset($_GET['all']) ? PHP_INT_MAX : 8;
$search = trim((string) ($_GET['search'] ?? ''));
$strandFilter = (string) ($_GET['strand'] ?? '');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$counselingFilter = (string) ($_GET['counseling'] ?? '');

$rows = $pdo->query(
    'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section, s.registered_at,
            u.is_active, a.top_types, a.completed_at
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     ORDER BY s.registered_at DESC'
)->fetchAll();

// A student "availed" counseling if they've submitted a Schedule Advising request
// from Help Center (results.html links there with a fixed subject line). This is
// distinct from monitoring escalation, which is a counselor-initiated review of a
// low-confidence recommendation, not the student asking for advising themselves.
//
// subject is now encrypted (subject_enc), so it can't be matched with a plain
// SQL WHERE clause anymore — encryption produces different ciphertext every
// time, even for the same input, so this has to decrypt and compare in PHP.
$counseledIds = [];
$helpRequestRows = $pdo->query(
    'SELECT student_id, subject_enc FROM help_requests WHERE student_id IS NOT NULL'
)->fetchAll();
foreach ($helpRequestRows as $hr) {
    if (Crypto::dec($hr['subject_enc']) === 'Request for Academic Advising') {
        $counseledIds[(int) $hr['student_id']] = true;
    }
}

$students = array_map(function ($r) use ($counseledIds) {
    $hasAssessment = $r['completed_at'] !== null;
    $status = $hasAssessment ? 'Completed' : 'Pending';
    $topTypes = $hasAssessment ? json_decode($r['top_types'], true) : [];
    $counseling = $hasAssessment ? (isset($counseledIds[(int) $r['user_id']]) ? 'Availed' : 'Did Not Avail') : '';

    return [
        'userId' => (int) $r['user_id'],
        'schoolId' => $r['school_id'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'gradeLevel' => $r['grade_level'],
        'section' => $r['section'],
        'isActive' => (bool) $r['is_active'],
        'status' => $status,
        'riasec' => implode(', ', $topTypes),
        'counseling' => $counseling,
        'registeredAt' => $r['registered_at'],
        'assessmentDate' => $r['completed_at'],
    ];
}, $rows);

$totalStudents = count($students);
$completedCount = count(array_filter($students, fn($s) => $s['status'] === 'Completed'));
$pendingCount = $totalStudents - $completedCount;
$counselingCount = count(array_filter($students, fn($s) => $s['counseling'] === 'Availed'));

$filtered = $students;
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filtered = array_values(array_filter($filtered, fn($s) => str_contains(mb_strtolower($s['name']), $needle) || str_contains(mb_strtolower($s['schoolId']), $needle)));
}
if ($strandFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['strand'] === $strandFilter));
}
if ($sectionFilter !== '') {
    $needleSection = mb_strtolower($sectionFilter);
    $filtered = array_values(array_filter($filtered, fn($s) => mb_strtolower($s['section']) === $needleSection));
}
if ($statusFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === $statusFilter));
}
if ($counselingFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['counseling'] === $counselingFilter));
}

$total = count($filtered);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$pageRows = array_slice($filtered, $offset, $pageSize);

jsonResponse([
    'students' => $pageRows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'startIndex' => $total > 0 ? $offset + 1 : 0,
    'summary' => [
        'totalStudents' => $totalStudents,
        'completedCount' => $completedCount,
        'pendingCount' => $pendingCount,
        'counselingCount' => $counselingCount,
    ],
]);
