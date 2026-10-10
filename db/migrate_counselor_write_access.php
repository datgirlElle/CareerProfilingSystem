<?php
// Guidance Counselors (not the old admin-only rule) post announcements, set the assessment
// schedule and manage sections; Guidance Facilitators stay view-only in code (lib/Rbac.php).
// This raises the counselor row of those three modules to 'full' so the matrix matches.
// Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$n = $pdo->exec(
    "UPDATE security_rbac SET access_level = 'full', updated_at = NOW()
     WHERE role = 'counselor' AND module IN ('announcements', 'examinations', 'sections') AND access_level <> 'full'"
);

echo "Counselor access raised to Full for announcements, examinations and sections ($n row(s) changed).\n";
