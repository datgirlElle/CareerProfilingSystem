<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Same gate as the upload it's meant to prepare for (api/roster-upload.php).
Rbac::requireAccess('rac', 'full');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="roster-template.csv"');
$out = fopen('php://output', 'w');
// One file is one section: the strand and section are written once at the top
// (read by lib/RosterCsv.php), then one student per row, so they never have to
// be copied onto every line. Upload one file per section.
fputcsv($out, ['Strand:', 'STEM'], escape: '\\');
fputcsv($out, ['Section:', 'S1114'], escape: '\\');
fputcsv($out, ['LRN', 'Lastname', 'Firstname', 'Middle'], escape: '\\');
// Excel auto-formats a bare 10+ digit CSV value as a number and displays it
// in scientific notation (e.g. "1.23E+11") the moment the file is opened —
// it's purely a display/formatting issue, the underlying value is untouched,
// but re-saving from Excel without fixing it first would bake that in as
// literal text and then fail LRN validation on upload. Wrapping it as
// ="..." makes Excel parse it as a formula evaluating to a plain text
// string instead, so it always displays (and re-saves) as plain digits.
// Replace the Strand, Section and this example row with the real ones before
// uploading — like any placeholder row, it isn't meant to be submitted as-is.
fputcsv($out, ['="123456789012"', 'Dela Cruz', 'Juan', 'Santos'], escape: '\\');
fclose($out);
