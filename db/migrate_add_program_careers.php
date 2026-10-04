<?php
// Adds programs.careers (the careers each MMCL program leads to) and
// worksheets.stated_career (the career a student says they want to take on
// the Career Worksheet). Both are additive and nullable/defaulted, so existing
// programs and worksheets keep working: a program with no careers listed falls
// back to its own title wherever a career is needed.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec("ALTER TABLE programs ADD COLUMN IF NOT EXISTS careers TEXT[] NOT NULL DEFAULT '{}'");
$pdo->exec('ALTER TABLE worksheets ADD COLUMN IF NOT EXISTS stated_career VARCHAR(120)');
// Students type their career freely now; if it can't be linked to a program
// there is no stated program, so this column must allow NULL.
$pdo->exec('ALTER TABLE worksheets ALTER COLUMN stated_program_id DROP NOT NULL');

echo "programs.careers and worksheets.stated_career added; worksheets.stated_program_id now nullable.\n";
