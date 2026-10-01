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
// Column names and order match api/roster-upload.php's expected header row
// exactly (it does case-insensitive matching against these labels), plus
// one example row so it's obvious what belongs in each column.
fputcsv($out, ['LRN', 'First Name', 'Last Name', 'Strand', 'Section'], escape: '\\');
// Excel auto-formats a bare 10+ digit CSV value as a number and displays it
// in scientific notation (e.g. "1.23E+11") the moment the file is opened —
// it's purely a display/formatting issue, the underlying value is untouched,
// but re-saving from Excel without fixing it first would bake that in as
// literal text and then fail LRN validation on upload. Wrapping it as
// ="..." makes Excel parse it as a formula evaluating to a plain text
// string instead, so it always displays (and re-saves) as plain digits.
// Replace this example row with real students before uploading — like any
// placeholder row, it isn't meant to be submitted as-is.
fputcsv($out, ['="123456789012"', 'Juan', 'Dela Cruz', 'STEM', 'S1114'], escape: '\\');
fclose($out);
