<?php

// Co-admin: a Guidance Counselor the administrator gave the shared admin work (users.is_co_admin,
// modules/Authentication/lib/Rbac.php). Checks what a co-admin may and may not do in the access rules, and that the
// database rule keeps the flag off facilitators. No database needed (role names only, no accounts).
//   php tests\co_admin_test.php

require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

$root = __DIR__ . '/..';
$co = Rbac::COADMIN;

echo "=== a co-admin's access ===\n";
$levels = Rbac::levelsFor($co);
check('Full on every feature', array_keys($levels) === Rbac::modules() && array_unique(array_values($levels)) === ['full']);
check('no sidebar page is closed', Rbac::closedPages($co) === []);
check('lands on the first sidebar page', Rbac::firstOpenPage($co) === Rbac::SIDEBAR[0], Rbac::firstOpenPage($co));
check('opens the Audit Log', Rbac::pageOpen('audit-log', $co));
check('never opens Security Configuration', !Rbac::pageOpen('security-configuration', $co));
check('the administrator still opens both', Rbac::pageOpen('audit-log', 'admin') && Rbac::pageOpen('security-configuration', 'admin'));
check('counselors and facilitators open neither', !Rbac::pageOpen('audit-log', 'counselor') && !Rbac::pageOpen('audit-log', 'facilitator')
    && !Rbac::pageOpen('security-configuration', 'counselor'));
check('the co-admin pages are administrator pages', array_diff(Rbac::COADMIN_PAGES, Rbac::ADMIN_PAGES) === []);

echo "\n=== only the administrator changes permissions ===\n";
$editable = [];
foreach (Rbac::modules() as $m) {
    foreach (['admin', 'counselor', 'facilitator', 'student'] as $r) {
        if (Rbac::editableLevels($co, $m, $r, []) !== []) {
            $editable[] = "$m/$r";
        }
    }
}
check('a co-admin can change no cell of the table', $editable === [], implode(', ', $editable));
check('the administrator still can', Rbac::editableLevels('admin', 'analytics', 'counselor', []) === Rbac::LEVELS);
check('a co-admin is not a column of the table', !in_array($co, Rbac::ROLES, true));

echo "\n=== who can be a co-admin ===\n";
check('the administrator is not a co-admin (no database needed for a non-counselor)', !Rbac::isCoAdmin(['id' => 1, 'role' => 'admin'])
    && Rbac::roleKey(['id' => 1, 'role' => 'admin']) === 'admin');
check('a student is never a co-admin', !Rbac::isCoAdmin(['id' => 2, 'role' => 'student']) && !Rbac::isAdminOrCoAdmin(['id' => 2, 'role' => 'student']));
check('the administrator does the shared admin work', Rbac::isAdminOrCoAdmin(['id' => 1, 'role' => 'admin']));
check('at most a few co-admins', Rbac::CO_ADMIN_MAX >= 1 && Rbac::CO_ADMIN_MAX <= 5, (string) Rbac::CO_ADMIN_MAX);

$rule = "CHECK (NOT is_co_admin OR (role = 'counselor' AND staff_position IS DISTINCT FROM 'facilitator'))";
$schema = file_get_contents("$root/db/schema.sql");
$migration = file_get_contents("$root/db/migrate_add_co_admin.php");
check('schema.sql keeps the flag on Guidance Counselors only', str_contains($schema, 'is_co_admin             BOOLEAN NOT NULL DEFAULT FALSE')
    && str_contains($schema, $rule));
check('the migration adds the same column and rule', str_contains($migration, 'ADD COLUMN IF NOT EXISTS is_co_admin BOOLEAN NOT NULL DEFAULT FALSE')
    && str_contains($migration, $rule));
$rbacSource = file_get_contents("$root/modules/Authentication/lib/Rbac.php");
check('the automatic step adds the same column and rule', Rbac::CO_ADMIN_RULE === $rule
    && str_contains($rbacSource, 'ADD COLUMN IF NOT EXISTS is_co_admin BOOLEAN NOT NULL DEFAULT FALSE'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
