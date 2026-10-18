<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/StaffPosition.php';
require_once __DIR__ . '/../lib/LoginDevice.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
// Students and guidance staff have separate sign-in pages; each says which one it is.
$portal = (string) ($body['portal'] ?? '');

if ($username === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'Username and password are required.'], 400);
}
// What the audit log keeps of the typed name: target_id holds 50 characters, and a longer value
// would make the log insert fail (a server error instead of "Invalid username or password").
$typed = mb_substr($username, 0, 50);

$pdo = Database::get();

// A bcrypt hash of a random throwaway text. Unknown usernames are checked against it, so they take
// as long to answer as real accounts and the timing does not tell which accounts exist.
const TIMING_DUMMY_HASH = '$2y$12$fm2fIi2HLx99eEzo4LSkLOGfj2W2JdC2COBwb3NQ7UBJ8H.aJlISy';
const LOCKED_MESSAGE = 'Account temporarily locked due to repeated failed attempts. Try again later, or ask the Guidance Office to unlock it.';

function lockoutPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'lockout.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'enabled' => ($rows['lockout.enabled'] ?? 'true') === 'true',
        'maxAttempts' => (int) ($rows['lockout.maxAttempts'] ?? 5),
        'lockoutMinutes' => (int) ($rows['lockout.lockoutMinutes'] ?? 15),
    ];
}

// No real username or email is this long; it is treated like an unknown one.
$user = false;
if (mb_strlen($username) <= 150) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
}
// Staff sign in with their email (they have no username to remember); students keep using their Student Number.
if (!$user && mb_strlen($username) <= 150 && strpos($username, '@') !== false) {
    $byEmail = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND role IN ('admin', 'counselor') ORDER BY id LIMIT 1");
    $byEmail->execute([$username]);
    $user = $byEmail->fetch();
}

$genericError = ['success' => false, 'error' => 'Invalid username or password.'];

$policy = lockoutPolicy($pdo);

if (!$user || !$user['is_active']) {
    password_verify($password, TIMING_DUMMY_HASH);
    // Unknown names get the same lock as real accounts after the same number of tries, so the
    // "locked" answer does not prove an account exists. The key is a hash of the typed name, so a
    // password typed into the username box by mistake is never stored as text.
    if ($policy['enabled'] && RateLimiter::tooMany('login-unknown:' . hash('sha256', mb_strtolower($typed)), $policy['maxAttempts'], $policy['lockoutMinutes'])) {
        AuditLogger::log(null, null, 'login_failed', 'user', $typed, 'Unknown or inactive username (locked)');
        jsonResponse(['success' => false, 'error' => LOCKED_MESSAGE], 423);
    }
    AuditLogger::log(null, null, 'login_failed', 'user', $typed, 'Unknown or inactive username');
    jsonResponse($genericError, 401);
}

if ($policy['enabled'] && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
    password_verify($password, TIMING_DUMMY_HASH);   // same time as a locked unknown name
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Account locked');
    jsonResponse(['success' => false, 'error' => LOCKED_MESSAGE], 423);
}

if (!password_verify($password, $user['password_hash'])) {
    $attempts = (int) $user['failed_login_attempts'] + 1;
    $lockedUntil = null;
    if ($policy['enabled'] && $attempts >= $policy['maxAttempts']) {
        $lockedUntil = date('c', time() + $policy['lockoutMinutes'] * 60);
        $attempts = 0; // reset counter once locked, so the next window starts fresh
    }
    $update = $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
    $update->execute([$attempts, $lockedUntil, $user['id']]);
    if ($lockedUntil !== null) {
        // One entry per lockout (not per wrong password): what the Security Overview's Account Lockouts counts.
        AuditLogger::log((int) $user['id'], $user['role'], 'login_lockout', 'user', $typed, "Locked for {$policy['lockoutMinutes']} minutes after {$policy['maxAttempts']} failed attempts");
    }

    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Incorrect password');
    jsonResponse($genericError, 401);
}

// Checked after the password, so the wrong page reveals nothing to someone who doesn't know it.
if ($portal === 'staff' && $user['role'] === 'student') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Student tried the staff sign in');
    jsonResponse(['success' => false, 'error' => 'This is the staff sign in. Students, please use the student sign in page.'], 403);
}
if ($portal === 'student' && $user['role'] !== 'student') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff tried the student sign in');
    jsonResponse(['success' => false, 'error' => 'This sign in is for students only.'], 403);
}

// Staff who signed up themselves wait for an admin: they confirm their email
// first, then stay 'pending' until approved in Account Management. (Checked
// only after the password is right, so the status never leaks to a stranger.)
if ($user['role'] === 'counselor' && $user['approval_status'] === 'pending') {
    if ($user['email_verified_at'] === null) {
        AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff email not verified');
        jsonResponse([
            'success' => false,
            'error' => 'Please verify your email first — check your inbox for the verification link. An administrator then needs to approve your account.',
            'needsVerification' => true,
        ], 403);
    }
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff account awaiting approval');
    jsonResponse(['success' => false, 'error' => 'Your account is waiting for an administrator to approve it. You will be able to sign in once it is approved.'], 403);
}
if ($user['role'] === 'counselor' && $user['approval_status'] === 'rejected') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff sign-up was rejected');
    jsonResponse(['success' => false, 'error' => 'Your sign-up was not approved. Please contact your system administrator.'], 403);
}

// Students self-register through the public page and must verify their email
// before signing in.
if ($user['role'] === 'student' && $user['email_verified_at'] === null) {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Email not verified');
    jsonResponse([
        'success' => false,
        'error' => 'Please verify your email before signing in — check your inbox for the verification link.',
        'needsVerification' => true,
    ], 403);
}

// Success — reset lockout state.
$reset = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
$reset->execute([$user['id']]);

$sessionData = [
    'id' => (int) $user['id'],
    'role' => $user['role'],
    'username' => $user['username'],
    'avatarUrl' => $user['avatar_data_url'],
];
// What staff see beside their name: their own name, not a login name.
if ($user['role'] !== 'student') {
    $sessionData['displayName'] = trim((string) ($user['full_name'] ?? '')) !== '' ? $user['full_name'] : $user['username'];
}
// Guidance counselors and facilitators share one role and one set of
// permissions; the position is just the title shown beside their name.
if ($user['role'] === 'counselor') {
    $sessionData['positionLabel'] = StaffPosition::label($user['staff_position'] ?? null);
}

$redirect = 'admin-dashboard';
if ($user['role'] === 'student') {
    $studentStmt = $pdo->prepare('SELECT school_id, first_name_enc, last_name_enc, strand, grade_level, section, registered_at FROM students WHERE user_id = ?');
    $studentStmt->execute([$user['id']]);
    $student = $studentStmt->fetch();
    if ($student) {
        $sessionData['schoolId'] = $student['school_id'];
        $sessionData['firstName'] = Crypto::dec($student['first_name_enc']);
        $sessionData['lastName'] = Crypto::dec($student['last_name_enc']);
        $sessionData['strand'] = $student['strand'];
        $sessionData['gradeLevel'] = $student['grade_level'];
        $sessionData['section'] = $student['section'];
        $sessionData['registeredAt'] = $student['registered_at'];
    }
    $redirect = 'assessment';
}

Auth::login($sessionData);
// Where this login came from. If a student signs in from another IP or device while an earlier login
// is still recent, it is flagged as a possible shared account (nobody is blocked).
$loginIp = $_SERVER['REMOTE_ADDR'] ?? null;
$loginDevice = LoginDevice::describe((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($user['role'] === 'student') {
    try {
        $conflict = LoginDevice::findConflict($pdo, (int) $user['id'], $loginIp, $loginDevice);
        if ($conflict !== null) {
            AuditLogger::log((int) $user['id'], 'student', 'login_shared_suspected', 'user', $user['username'], "Signed in from {$loginIp} ({$loginDevice}) while a login from {$conflict['ip']} ({$conflict['device']}) was still recent");
        }
    } catch (Throwable $e) {
        error_log('[login-device] check failed: ' . $e->getMessage());
    }
}
AuditLogger::log((int) $user['id'], $user['role'], 'login', 'user', $user['username'], 'Successful login — ' . $loginDevice);

jsonResponse(['success' => true, 'role' => $user['role'], 'redirect' => $redirect, 'user' => $sessionData]);
