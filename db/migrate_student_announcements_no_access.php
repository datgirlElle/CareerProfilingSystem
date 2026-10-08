<?php
// The Announcements permission is about sending announcements, so the student role has no access to it
// (students still read the announcements sent to them). Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$n = Database::get()->exec(
    "UPDATE security_rbac SET access_level = 'none', updated_at = NOW()
     WHERE module = 'announcements' AND role = 'student' AND access_level <> 'none'"
);

echo "Student access to Announcements set to No Access ($n row(s) changed).\n";