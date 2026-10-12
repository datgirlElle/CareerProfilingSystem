<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

// [college, title, original code (crosswalk), final code (validator, Aug 7 2026)]
$programs = require __DIR__ . '/program_codes.php';

$pdo = Database::get();
$collegeIds = $pdo->query('SELECT code, id FROM colleges')->fetchAll(PDO::FETCH_KEY_PAIR);

$existing = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
if ($existing > 0) {
    echo "Programs already seeded ($existing rows). Skipping.\n";
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO programs (college_id, title_enc, holland_code_enc, original_holland_code_enc, status) VALUES (?, ?, ?, ?, ?)'
);

foreach ($programs as [$collegeCode, $title, $originalCode, $finalCode]) {
    if (!isset($collegeIds[$collegeCode])) {
        throw new RuntimeException("Unknown college code: $collegeCode — run seed_colleges.php first");
    }
    $stmt->execute([
        $collegeIds[$collegeCode],
        Crypto::enc($title),
        Crypto::enc($finalCode),
        Crypto::enc($originalCode),
        'Active',
    ]);
}

$count = $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
echo "Programs seeded. Total rows: $count\n";
