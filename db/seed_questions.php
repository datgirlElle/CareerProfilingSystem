<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/QuestionBank.php';

// A new database starts with the 30 questions of the O*NET Mini Interest Profiler (5 per type). Staff can then edit,
// add or remove questions in the Question Bank.

$pdo = Database::get();

$existing = (int) $pdo->query('SELECT COUNT(*) FROM assessment_questions')->fetchColumn();
if ($existing > 0) {
    echo "Questions already seeded ($existing rows). Skipping.\n";
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active) VALUES (?, ?, ?, TRUE)'
);

foreach (QuestionBank::ONET_MINI_IP as $dimension => $questions) {
    foreach ($questions as $i => $text) {
        $stmt->execute([$dimension, Crypto::enc($text), $i + 1]);
    }
}

$count = $pdo->query('SELECT COUNT(*) FROM assessment_questions')->fetchColumn();
echo "Questions seeded. Total rows: $count\n";
