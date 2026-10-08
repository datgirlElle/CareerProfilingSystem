<?php
// Announcement templates the Guidance Counselor types and keeps (api/announcement-templates.php), so a
// new announcement can start from one. The table starts with the four templates the page used to have;
// they can be edited or deleted like any other. Safe to re-run: it only fills an empty table.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS announcement_templates (
        id          SERIAL PRIMARY KEY,
        name        VARCHAR(80) NOT NULL,
        title       VARCHAR(255) NOT NULL,
        body        TEXT NOT NULL,
        created_by  INT REFERENCES users(id) ON DELETE SET NULL,
        updated_by  INT REFERENCES users(id) ON DELETE SET NULL,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )'
);

if ((int) $pdo->query('SELECT COUNT(*) FROM announcement_templates')->fetchColumn() === 0) {
    $insert = $pdo->prepare('INSERT INTO announcement_templates (name, title, body) VALUES (?, ?, ?)');
    foreach (require __DIR__ . '/announcement_template_defaults.php' as [$name, $title, $body]) {
        $insert->execute([$name, $title, $body]);
    }
    echo "announcement_templates created and filled with the four starting templates.\n";
} else {
    echo "announcement_templates is already there (and has templates), left as it is.\n";
}
