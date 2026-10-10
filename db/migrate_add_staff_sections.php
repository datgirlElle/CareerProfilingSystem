<?php
require_once __DIR__ . '/../lib/Database.php';

// Which sections each guidance counselor / facilitator handles. The administrator assigns them in
// Account Management; a staff member with none assigned sees every section (see lib/StaffScope.php).
$pdo = Database::get();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS staff_sections (
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        strand  VARCHAR(10) NOT NULL CHECK (strand IN (\'STEM\', \'ABM\', \'ICT\', \'HUMSS\')),
        section VARCHAR(20) NOT NULL,
        PRIMARY KEY (user_id, strand, section)
    )'
);

echo "staff_sections table created (or already present).\n";
