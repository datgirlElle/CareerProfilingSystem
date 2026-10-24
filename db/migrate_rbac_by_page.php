<?php
// The permission table follows the system's features, grouped by the sidebar page they live on (Rbac::DEFAULTS and
// Rbac::PAGES in modules/Authentication/lib/Rbac.php), instead of the older feature list (career, rac, counselor,
// counselingNotes, sections). The defaults are the permission table agreed with the team on 2026-10-24.
//
// First run (the older rows are still there): every row is set to the new default and the older rows are removed. The
// older levels do not carry over, since the rows mean different things now. Later runs only add rows that are missing,
// so a level the administrator changed in Security Configuration is never overwritten. Safe to run twice.

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$pdo = Database::get();
$modules = Rbac::modules();

// The first run is recognised by the older features that only the earlier migrations wrote (a stray unknown row alone
// never resets the table, so the administrator's own levels are safe).
$placeholders = implode(', ', array_fill(0, count($modules), '?'));
$retired = $pdo->query("SELECT COUNT(*) FROM security_rbac WHERE module IN ('career', 'rac', 'counselor', 'counselingNotes', 'sections')");
$firstRun = (int) $retired->fetchColumn() > 0;

$sql = 'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?) ON CONFLICT (module, role) DO '
    . ($firstRun ? 'UPDATE SET access_level = EXCLUDED.access_level, updated_at = NOW(), updated_by = NULL' : 'NOTHING');
$insert = $pdo->prepare($sql);

$pdo->beginTransaction();
$written = 0;
foreach (Rbac::DEFAULTS as $module => $defaults) {
    $levels = [
        'admin' => 'full',
        'counselor' => $defaults['counselor'],
        'facilitator' => $defaults['facilitator'],
        'student' => in_array($module, Rbac::STUDENT_MODULES, true) ? 'full' : 'none',
    ];
    foreach ($levels as $role => $level) {
        $insert->execute([$module, $role, $level]);
        $written += $insert->rowCount();
    }
}
$removed = 0;
if ($firstRun) {
    $delete = $pdo->prepare("DELETE FROM security_rbac WHERE module NOT IN ($placeholders)");
    $delete->execute($modules);
    $removed = $delete->rowCount();
}
$pdo->commit();

echo $firstRun
    ? "Permission table set to the new defaults ($written row(s) written, $removed older row(s) removed).\n"
    : "Permission table already uses the new features ($written missing row(s) added).\n";
