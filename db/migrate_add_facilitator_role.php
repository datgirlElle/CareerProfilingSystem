<?php
// Guidance Facilitators get their own column in the access matrix (they used to share the Guidance Counselor column).
// Each module's Facilitator level starts from the Guidance Counselor's, except Sections (View Only), Counseling Notes and
// Staff Accounts (No Access), which matches what facilitators could do before. The administrator can change any of it in
// Security Configuration, Edit Roles. Never overwrites a level that is already saved, so it is safe to re-run.

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$pdo = Database::get();
$insert = $pdo->prepare('INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?) ON CONFLICT (module, role) DO NOTHING');
$added = 0;
foreach ($pdo->query("SELECT module, access_level FROM security_rbac WHERE role = 'counselor'")->fetchAll() as $r) {
    $insert->execute([$r['module'], 'facilitator', Rbac::facilitatorDefault($r['module'], $r['access_level'])]);
    $added += $insert->rowCount();
}
echo "Facilitator column ready ($added row(s) added).\n";