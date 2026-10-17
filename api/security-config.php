<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AcademicYear.php';
require_once __DIR__ . '/../lib/OfficeHours.php';
require_once __DIR__ . '/../lib/BookingLink.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();

// 'announcements' was added to lib/Rbac.php's MODULES and to
// security-configuration.html's UI but this const was never updated to
// match — meaning loadRbac() silently omitted that row and any RBAC change
// posted for it was silently dropped by the in_array() guard below. Fixed
// here alongside adding 'examinations' the same way.
const RBAC_MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'sections', 'staffAccounts'];
const RBAC_ROLES = ['admin', 'counselor', 'facilitator', 'student'];
const RBAC_LEVELS = ['full', 'limited', 'none'];

function loadRbac(PDO $pdo): array
{
    $rows = $pdo->query('SELECT module, role, access_level FROM security_rbac')->fetchAll();
    $rbac = [];
    $seenFacilitator = [];
    foreach (RBAC_MODULES as $m) {
        $rbac[$m] = array_fill_keys(RBAC_ROLES, 'none');
    }
    foreach ($rows as $r) {
        if (isset($rbac[$r['module']]) && in_array($r['role'], RBAC_ROLES, true)) {
            $rbac[$r['module']][$r['role']] = $r['access_level'];
            if ($r['role'] === 'facilitator') {
                $seenFacilitator[$r['module']] = true;
            }
        }
    }
    // A database from before the Facilitator column: show what Rbac::accessLevel() will actually apply.
    foreach (RBAC_MODULES as $m) {
        if (!isset($seenFacilitator[$m])) {
            $rbac[$m]['facilitator'] = Rbac::facilitatorDefault($m, $rbac[$m]['counselor']);
        }
    }
    // A Student holds only what Rbac::STUDENT_MODULES allows; show what is enforced.
    foreach (RBAC_MODULES as $m) {
        if (!in_array($m, Rbac::STUDENT_MODULES, true)) {
            $rbac[$m]['student'] = 'none';
        }
    }
    return $rbac;
}

/** Which levels the signed-in person may set for each cell, so the page offers only what the server will accept. */
function editableMatrix(array $user, array $rbac): array
{
    $out = [];
    foreach (RBAC_MODULES as $m) {
        foreach (RBAC_ROLES as $r) {
            $out[$m][$r] = Rbac::editableLevels(Rbac::roleKey($user), $m, $r, $rbac);
        }
    }
    return $out;
}

function loadPolicies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, value FROM security_policies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b = fn($k, $d) => isset($rows[$k]) ? $rows[$k] === 'true' : $d;
    $i = fn($k, $d) => isset($rows[$k]) ? (int) $rows[$k] : $d;
    $s = fn($k, $d) => $rows[$k] ?? $d;
    return [
        'policies' => [
            'password' => [
                'enabled' => $b('password.enabled', true), 'minLength' => $i('password.minLength', 8),
                'requireUpper' => $b('password.requireUpper', true), 'requireLower' => $b('password.requireLower', true),
                'requireNumber' => $b('password.requireNumber', true), 'requireSymbol' => $b('password.requireSymbol', true),
            ],
            'lockout' => [
                'enabled' => $b('lockout.enabled', true), 'maxAttempts' => $i('lockout.maxAttempts', 5),
                'lockoutMinutes' => $i('lockout.lockoutMinutes', 15),
            ],
            'monitoring' => [
                'enabled' => $b('monitoring.enabled', true), 'retentionDays' => $i('monitoring.retentionDays', 90),
            ],
        ],
        'settings' => [
            'twoFactor' => $b('twoFactor', true),
            'sessionTimeoutEnabled' => $b('sessionTimeoutEnabled', true),
            'timeoutMinutes' => $i('timeoutMinutes', 30),
        ],
        // Detected from today's date (lib/AcademicYear.php) — read-only here.
        'academicYear' => [
            'current' => AcademicYear::current(),
        ],
        // Chosen day by day; 	ext is the sentence built from it that students read.
        'officeHours' => [
            'text' => $s('officeHours.text', 'Mon–Fri, 8:00 AM–5:00 PM'),
            'schedule' => OfficeHours::fromJson($s('officeHours.schedule', '')),
        ],
        'booking' => ['url' => $s('booking.url', '')],
        'principal' => [
            'name' => $s('principal.name', ''),
            'email' => $s('principal.email', ''),
            'lastSentAt' => $s('principal.lastSentAt', ''),
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastUpdated = $pdo->query(
        'SELECT GREATEST(
            (SELECT MAX(updated_at) FROM security_rbac),
            (SELECT MAX(updated_at) FROM security_policies)
         )'
    )->fetchColumn();

    // A lockout is logged (api/login.php) the moment an account reaches the maximum failed attempts,
    // so this counts accounts that were actually locked out, not every wrong password.
    $lockouts7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_lockout' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $sharedLogins7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_shared_suspected' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $activeUsersToday = (int) $pdo->query(
        "SELECT COUNT(DISTINCT actor_user_id) FROM audit_log WHERE created_at::date = CURRENT_DATE"
    )->fetchColumn();
    $pendingFlags = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status = 'pending'")->fetchColumn();

    $rbacNow = loadRbac($pdo);
    // The permission table is for the Head of Guidance and Guidance Counselors. A Guidance Facilitator is sent only their own
    // column (the pages need it to know what they may do), never the other roles' permissions, and cannot change anything.
    $facilitator = Rbac::isFacilitator($user);
    $rbacShown = $facilitator ? array_map(fn($levels) => ['facilitator' => $levels['facilitator']], $rbacNow) : $rbacNow;
    jsonResponse([
        'rbac' => $rbacShown,
        'rbacVisible' => !$facilitator,
        // what this person may change in the table (empty for a fixed cell), and which changes ask for a confirmation
        'rbacEditable' => $facilitator ? (object) [] : editableMatrix($user, $rbacNow),
        'rbacSensitive' => ['modules' => Rbac::SENSITIVE_MODULES, 'roles' => ['counselor']],
        // Uploading class rosters: administrator or Guidance Counselor with Full access to the roster module.
        'canEditRoster' => Rbac::accessLevel('rac', Rbac::roleKey($user)) === 'full'
            && (Rbac::roleKey($user) !== 'facilitator' || Rbac::accessLevel('sections', 'facilitator') === 'full'),
        'lastUpdated' => $lastUpdated,
        'overview' => [
            'lockouts7d' => $lockouts7d,
            'sharedLogins7d' => $sharedLogins7d,
            'activeUsersToday' => $activeUsersToday,
            'pendingFlags' => $pendingFlags,
        ],
    ] + loadPolicies($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    // The Guidance Office hours and the counseling booking link are the Guidance Counselors' to keep up to date (the Counseling
    // Notes permission: the Head of Guidance and Guidance Counselors, not Facilitators). Everything else here is the administrator's.
    if (in_array($type, ['officeHours', 'bookingLink'], true)) {
        $user = Rbac::requireAccess('counselingNotes', 'full');
    } elseif ($type === 'rbac') {
        // The permission table is changed by the Head of Guidance and by Guidance Counselors (what each may change is set by
        // Rbac::editableLevels); a Facilitator or anyone else is refused.
        if ($user['role'] !== 'admin' && !($user['role'] === 'counselor' && !Rbac::isFacilitator($user))) {
            jsonResponse(['success' => false, 'error' => 'Only the Head of Guidance and Guidance Counselors can change permissions.'], 403);
        }
    } elseif ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Only administrators can change security configuration.'], 403);
    }

    if ($type === 'rbac') {
        $changes = $body['changes'] ?? [];
        if (!is_array($changes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid changes payload.'], 400);
        }
        $moduleLabels = [
            'career' => 'Career Profile Input', 'rac' => 'RIASEC Assessment', 'recommendations' => 'Recommendations', 'counselor' => 'Guidance Counselor accounts',
            'monitoring' => 'Student Monitoring', 'announcements' => 'Announcements', 'examinations' => 'Assessment Scheduling',
            'counselingNotes' => 'Counseling Notes', 'staffAccounts' => 'Staff Accounts', 'sections' => 'Section Management',
        ];
        $roleLabels = ['admin' => 'Administrator', 'counselor' => 'Guidance Counselor', 'facilitator' => 'Guidance Facilitator', 'student' => 'Student'];
        $levelLabels = ['full' => 'Full Access', 'limited' => 'View Only', 'none' => 'No Access'];

        $actorKey = Rbac::roleKey($user);
        $current = loadRbac($pdo);
        $toApply = [];
        foreach ($changes as $c) {
            $module = is_array($c) ? (string) ($c['module'] ?? '') : '';
            $role = is_array($c) ? (string) ($c['role'] ?? '') : '';
            $level = is_array($c) ? (string) ($c['level'] ?? '') : '';
            if (!in_array($module, RBAC_MODULES, true) || !in_array($role, RBAC_ROLES, true) || !in_array($level, RBAC_LEVELS, true)) {
                jsonResponse(['success' => false, 'error' => 'Invalid permission change.'], 400);
            }
            $from = $current[$module][$role];
            if ($level === $from) {
                continue; // nothing to change
            }
            // The server decides, not the page: an administrator or Student cell, an account module for a counselor, or a level
            // above what a counselor holds is refused, and nothing in the request is saved.
            if (!in_array($level, Rbac::editableLevels($actorKey, $module, $role, $current), true)) {
                jsonResponse(['success' => false, 'error' => 'You cannot set ' . $roleLabels[$role] . ' to ' . $levelLabels[$level] . ' for ' . $moduleLabels[$module] . '.'], 403);
            }
            $toApply["$module|$role"] = ['module' => $module, 'role' => $role, 'from' => $from, 'to' => $level];
        }

        if (!$toApply) {
            jsonResponse(['success' => true, 'applied' => 0, 'rbac' => $current, 'rbacEditable' => editableMatrix($user, $current)]);
        }

        // A change to account modules or to the Guidance Counselor column only goes through once it was confirmed.
        $sensitive = array_values(array_filter($toApply, fn($c) => Rbac::isSensitiveChange($c['module'], $c['role'])));
        if ($sensitive && empty($body['confirmed'])) {
            jsonResponse(['success' => false, 'needsConfirmation' => true, 'error' => 'Confirm the changes to account and staff permissions before saving.', 'sensitive' => $sensitive], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO security_rbac (module, role, access_level, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (module, role) DO UPDATE SET access_level = EXCLUDED.access_level, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $pdo->beginTransaction();
        try {
            foreach ($toApply as $c) {
                $stmt->execute([$c['module'], $c['role'], $c['to'], $user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not save the permissions. Please try again.'], 500);
        }
        // One audit entry per change: who made it (the actor), the previous and the new value, and when (the entry's time).
        foreach ($toApply as $c) {
            AuditLogger::log(
                $user['id'], $user['role'], 'update_rbac', 'security_rbac', $c['module'] . ':' . $c['role'],
                $roleLabels[$c['role']] . ', ' . $moduleLabels[$c['module']] . ': ' . $levelLabels[$c['from']] . ' -> ' . $levelLabels[$c['to']]
                    . (Rbac::isSensitiveChange($c['module'], $c['role']) ? ' (sensitive, confirmed)' : '')
            );
        }
        $fresh = loadRbac($pdo);
        jsonResponse(['success' => true, 'applied' => count($toApply), 'rbac' => $fresh, 'rbacEditable' => editableMatrix($user, $fresh)]);
    }

    if ($type === 'policy') {
        $key = $body['key'] ?? '';
        if (!in_array($key, ['password', 'lockout', 'monitoring'], true)) {
            jsonResponse(['success' => false, 'error' => 'Unknown policy.'], 400);
        }

        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };

        $enabled = !empty($body['enabled']) ? 'true' : 'false';
        $set("$key.enabled", $enabled);

        if ($key === 'password') {
            $set('password.minLength', (string) max(6, min(32, (int) ($body['minLength'] ?? 8))));
            $set('password.requireUpper', !empty($body['requireUpper']) ? 'true' : 'false');
            $set('password.requireLower', !empty($body['requireLower']) ? 'true' : 'false');
            $set('password.requireNumber', !empty($body['requireNumber']) ? 'true' : 'false');
            $set('password.requireSymbol', !empty($body['requireSymbol']) ? 'true' : 'false');
        } elseif ($key === 'lockout') {
            $set('lockout.maxAttempts', (string) max(1, min(20, (int) ($body['maxAttempts'] ?? 5))));
            $set('lockout.lockoutMinutes', (string) max(1, min(1440, (int) ($body['lockoutMinutes'] ?? 15))));
        } else {
            $set('monitoring.retentionDays', (string) max(7, min(365, (int) ($body['retentionDays'] ?? 90))));
        }

        AuditLogger::log($user['id'], $user['role'], 'update_policy', 'security_policies', $key, json_encode($body));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'officeHours') {
        // Office hours are picked day by day (see lib/OfficeHours.php), never typed as text.
        $checked = OfficeHours::validate($body['schedule'] ?? null);
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $text = OfficeHours::text($checked['schedule']);
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute(['officeHours.schedule', json_encode($checked['schedule']), $user['id']]);
        $stmt->execute(['officeHours.text', $text, $user['id']]); // what api/public-settings.php sends to students

        AuditLogger::log($user['id'], $user['role'], 'update_office_hours', 'security_policies', 'officeHours.text', "Set to: $text");
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'bookingLink') {
        $checked = BookingLink::validate((string) ($body['url'] ?? ''));
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute([BookingLink::KEY, $checked['url'], $user['id']]);
        AuditLogger::log($user['id'], $user['role'], 'update_booking_link', 'security_policies', BookingLink::KEY, $checked['url'] === '' ? 'Cleared' : 'Set to: ' . $checked['url']);
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'principalContact') {
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            jsonResponse(['success' => false, 'error' => 'Principal name must be 1-100 characters.'], 400);
        }
        if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
        }
        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };
        $set('principal.name', $name);
        $set('principal.email', $email);

        AuditLogger::log($user['id'], $user['role'], 'update_principal_contact', 'security_policies', 'principal', "Set to: $name <$email>");
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'settings') {
        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };
        $set('twoFactor', !empty($body['twoFactor']) ? 'true' : 'false');
        $set('sessionTimeoutEnabled', !empty($body['sessionTimeoutEnabled']) ? 'true' : 'false');
        $set('timeoutMinutes', (string) max(5, min(240, (int) ($body['timeoutMinutes'] ?? 30))));

        AuditLogger::log($user['id'], $user['role'], 'update_settings', 'security_policies', 'settings', json_encode($body));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
