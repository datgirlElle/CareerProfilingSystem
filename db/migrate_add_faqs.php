<?php
// Help Center FAQs the Guidance Office can add, edit, reorder and delete (api/faqs.php) instead of being
// written into the pages. audience 'student' shows on the student Help Center, 'staff' on the staff one.
// The table starts with the FAQs the pages used to have. Safe to re-run: it only seeds an empty table.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS faqs (
        id          SERIAL PRIMARY KEY,
        audience    VARCHAR(10) NOT NULL CHECK (audience IN ('student', 'staff')),
        question    VARCHAR(300) NOT NULL,
        answer      TEXT NOT NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        created_by  INT REFERENCES users(id) ON DELETE SET NULL,
        updated_by  INT REFERENCES users(id) ON DELETE SET NULL,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_faqs_audience_order ON faqs (audience, sort_order, id)');

if ((int) $pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 0) {
    $insert = $pdo->prepare('INSERT INTO faqs (audience, question, answer, sort_order) VALUES (?, ?, ?, ?)');
    foreach (require __DIR__ . '/faq_defaults.php' as $audience => $items) {
        foreach ($items as $i => [$question, $answer]) {
            $insert->execute([$audience, $question, $answer, $i + 1]);
        }
    }
    echo "faqs table created and filled with the existing FAQs.\n";
} else {
    echo "faqs table is already there (and has FAQs), left as it is.\n";
}
