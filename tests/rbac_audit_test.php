<?php

require_once __DIR__ . '/../lib/Rbac.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

echo "=== Rbac::accessLevel against the real seeded security_rbac table ===\n";

$expected = [
    'career'          => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'rac'             => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'recommendations' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'full'],
    'counselor'       => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'monitoring'      => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    // Guidance Counselors post announcements, set schedules and manage sections (a Guidance Facilitator is view-only in code).
    'announcements'   => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'examinations'    => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'counselingNotes' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'sections'        => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'staffAccounts'   => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
];

foreach ($expected as $module => $roles) {
    foreach ($roles as $role => $level) {
        check("$module/$role == $level", Rbac::accessLevel($module, $role) === $level);
    }
}

echo "\n=== a student has no access to sending announcements ===\n";
$pdoCap = Database::get();
$pdoCap->beginTransaction();
$pdoCap->prepare("UPDATE security_rbac SET access_level = 'full' WHERE module = 'announcements' AND role = 'student'")->execute();
check('even if the table is tampered to Full, a student has no access to announcements', Rbac::accessLevel('announcements', 'student') === 'none');
check('the cap is only for students: the counselor row is untouched', Rbac::accessLevel('announcements', 'counselor') === 'full');
$pdoCap->rollBack();
check('the real student row is No Access', Rbac::accessLevel('announcements', 'student') === 'none');
check('a student is still not cut off from other modules (Recommendations stays Full)', Rbac::accessLevel('recommendations', 'student') === 'full');

echo "\n=== Guidance Facilitators have a column of their own ===\n";
$facilitator = [
    'career' => 'limited', 'rac' => 'full', 'recommendations' => 'full', 'counselor' => 'full', 'monitoring' => 'full',
    'announcements' => 'full', 'examinations' => 'full', 'counselingNotes' => 'none', 'sections' => 'limited', 'staffAccounts' => 'none',
];
foreach ($facilitator as $module => $level) {
    check("$module/facilitator == $level", Rbac::accessLevel($module, 'facilitator') === $level);
}
check('the matrix has four columns', Rbac::ROLES === ['admin', 'counselor', 'facilitator', 'student']);
check('facilitator defaults follow the counselor, capped on sections, notes and staff accounts',
    Rbac::facilitatorDefault('sections', 'full') === 'limited' && Rbac::facilitatorDefault('sections', 'none') === 'none'
    && Rbac::facilitatorDefault('counselingNotes', 'full') === 'none' && Rbac::facilitatorDefault('staffAccounts', 'limited') === 'none'
    && Rbac::facilitatorDefault('announcements', 'full') === 'full' && Rbac::facilitatorDefault('career', 'limited') === 'limited');
$pdoFac = Database::get();
$pdoFac->beginTransaction();
$pdoFac->exec("DELETE FROM security_rbac WHERE role = 'facilitator'");
check('with no facilitator rows (an older database) the level is derived from the counselor: sections View Only', Rbac::accessLevel('sections', 'facilitator') === 'limited');
check('...and counseling notes No Access', Rbac::accessLevel('counselingNotes', 'facilitator') === 'none');
check('...and announcements as the counselor has them', Rbac::accessLevel('announcements', 'facilitator') === Rbac::accessLevel('announcements', 'counselor'));
$pdoFac->rollBack();
check('the Facilitator column can be changed independently of the counselor', (function () use ($pdoFac) {
    $pdoFac->beginTransaction();
    $pdoFac->exec("UPDATE security_rbac SET access_level = 'full' WHERE module = 'sections' AND role = 'facilitator'");
    $ok = Rbac::accessLevel('sections', 'facilitator') === 'full' && Rbac::accessLevel('sections', 'counselor') === Rbac::accessLevel('sections', 'counselor');
    $pdoFac->rollBack();
    return $ok;
})());check('a student is never a facilitator', Rbac::isFacilitator(['id' => 0, 'role' => 'student']) === false);
check('the administrator is never a facilitator', Rbac::isFacilitator(['id' => 1, 'role' => 'admin']) === false);

check(
    'Unknown role for a valid module falls back to none',
    Rbac::accessLevel('career', 'nonexistent_role') === 'none'
);

try {
    Rbac::accessLevel('not_a_real_module', 'admin');
    check('Unknown module throws', false);
} catch (InvalidArgumentException $e) {
    check('Unknown module throws', true);
}

echo "\n=== AuditLogger round-trip ===\n";

$pdo = Database::get();
$marker = 'test-marker-' . bin2hex(random_bytes(4));
AuditLogger::log(1, 'admin', 'test_action', 'test_target', 'target-123', $marker);

$stmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = 'test_action' AND target_id = 'target-123' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$row = $stmt->fetch();

check('Audit row was written', $row !== false);
if ($row) {
    check('actor_user_id stored correctly', (int) $row['actor_user_id'] === 1);
    check('actor_role stored correctly', $row['actor_role'] === 'admin');
    check('target_type stored correctly', $row['target_type'] === 'test_target');
    check('detail_enc decrypts to the original marker', Crypto::dec($row['detail_enc']) === $marker);
    check('detail_enc is not the plaintext marker (actually encrypted)', $row['detail_enc'] !== $marker);
    // clean up the test row so it doesn't pollute the real audit trail
    $pdo->prepare('DELETE FROM audit_log WHERE id = ?')->execute([$row['id']]);
}

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
