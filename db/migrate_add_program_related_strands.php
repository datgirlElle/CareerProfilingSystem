<?php
// Adds programs.related_strands — the SHS strand(s) MMCL aligns each program
// with, used as the "strand" block of the CBF feature vector (lib/CBFEngine.php).
//
// Existing programs get '{}' (no strands). The engine treats an empty list as
// "strand not specified" (an all-zero block that adds nothing to the cosine
// similarity), so existing recommendations and programs keep working until an
// admin fills this in via add-career.html / career-dataset.html.
//
// Stored as plain text like students.strand: it is a closed list of strand
// codes (validated in api/programs.php against config/cbf.php), not personal
// or free-text content.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec("ALTER TABLE programs ADD COLUMN IF NOT EXISTS related_strands TEXT[] NOT NULL DEFAULT '{}'");

echo "programs.related_strands column added (or already present).\n";
