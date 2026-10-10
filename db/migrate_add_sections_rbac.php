<?php
// Registers the 'sections' RBAC module so Section Management shows up in
// Security Configuration's permission table instead of being fixed in code.
// limited = can add sections; full = can also deactivate/reactivate them.
// Safe to re-run: existing rows (including any the admin has since changed)
// are left alone.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
foreach (['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'] as $role => $level) {
    $rbacStmt->execute(['sections', $role, $level]);
}

echo "Registered 'sections' RBAC module.\n";
