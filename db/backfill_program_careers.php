<?php
/**
 * One-time backfill: gives every seeded program a starter list of careers
 * (programs.careers). Programs are matched by decrypted title, so it works
 * whatever ids they have. By default only programs whose list is still empty
 * are filled, so careers staff have edited in Career Data Set are never
 * overwritten. Safe to re-run.
 *
 *   php db/backfill_program_careers.php               fill empty lists only
 *   php db/backfill_program_careers.php --overwrite   reset EVERY program to these starter lists
 *                                                     (discards staff edits — use after changing this file)
 *
 * These are starter lists for the counselor to review and edit. They also feed
 * the Career Worksheet: a career a student types is linked to the program whose
 * list (or title) it matches (lib/CareerMatcher.php), so common job names that
 * graduates of a program actually take are worth listing here (max 8 each).
 */
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/Careers.php';
require_once __DIR__ . '/../lib/StarterCareers.php';

$overwrite = in_array('--overwrite', $argv ?? [], true);

$careersByProgram = StarterCareers::LISTS;

$pdo = Database::get();
$rows = $pdo->query('SELECT id, title_enc, careers FROM programs')->fetchAll();

$update = $pdo->prepare('UPDATE programs SET careers = ?::text[], updated_at = NOW() WHERE id = ?');
$filled = 0;
$skipped = 0;
$unmatched = [];

foreach ($rows as $row) {
    $title = Crypto::dec($row['title_enc']);
    if (!$overwrite && Careers::parse($row['careers']) !== []) {
        $skipped++;
        continue;
    }
    if (!isset($careersByProgram[$title])) {
        $unmatched[] = $title;
        continue;
    }
    $update->execute([Careers::toLiteral(Careers::normalize($careersByProgram[$title])), (int) $row['id']]);
    $filled++;
}

echo "Careers filled for $filled programs; $skipped already had careers (left alone).\n";
if ($unmatched) {
    echo 'No starter list for: ' . implode('; ', $unmatched) . "\n";
}
