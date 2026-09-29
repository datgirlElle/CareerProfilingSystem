<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sections (
        id          SERIAL PRIMARY KEY,
        strand      VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
        code        VARCHAR(20) NOT NULL,
        is_active   BOOLEAN NOT NULL DEFAULT TRUE,
        created_by  INT REFERENCES users(id),
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sections_strand_code ON sections (strand, LOWER(code))");

// Seed with the sections that were previously hardcoded in lib/Sections.php,
// so this migration doesn't remove any section currently in use.
$existing = [
    ['STEM', 'S1114'],
    ['STEM', 'S1109'],
    ['ABM', 'A1101'],
    ['ABM', 'A1102'],
    ['ICT', 'I1101'],
    ['ICT', 'I1102'],
    ['HUMSS', 'H1102'],
];
$insert = $pdo->prepare('INSERT INTO sections (strand, code) VALUES (?, ?) ON CONFLICT DO NOTHING');
foreach ($existing as [$strand, $code]) {
    $insert->execute([$strand, $code]);
}

$count = $pdo->query('SELECT COUNT(*) FROM sections')->fetchColumn();
echo "sections table created (or already present). Total rows: $count\n";
