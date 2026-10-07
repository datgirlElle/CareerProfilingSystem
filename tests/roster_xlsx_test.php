<?php

require_once __DIR__ . '/../lib/RosterCsv.php';
require_once __DIR__ . '/../lib/XlsxReader.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

// tests/fixtures/roster_excel.xlsx was saved by Excel: Strand STEM, Section ZZXLSX,
// three students — one LRN typed as a plain number (what breaks in a CSV), one as
// text, one with accents after an empty row.
echo "=== a roster saved from Excel as .xlsx ===\n";
$rows = XlsxReader::readRows(__DIR__ . '/fixtures/roster_excel.xlsx');
check('rows are read with their sheet row numbers (the empty row 6 is kept)', count($rows) === 7 && $rows[5] === []);
check('header row is read', $rows[2] === ['Learning ID', 'Lastname', 'Firstname', 'Middle']);
check('a number-formatted 12-digit LRN comes back as whole digits', $rows[3][0] === '444400000001');
$rows[1][1] = 'S1114'; // the fixture's own section code is a test marker, not a valid one
// The fixture was saved before the Email column existed: add it, as the current template has.
$rows[2][4] = 'Email';
foreach ($rows as $n => $row) { if ($n >= 3 && $row) { $rows[$n] = array_pad($row, 4, ''); $rows[$n][4] = 'student' . $n . '@live.mcl.edu.ph'; } }
$r = RosterCsv::parseLines($rows);
check('it parses as a roster: STEM / S1114', $r['error'] === null && $r['strand'] === 'STEM' && $r['section'] === 'S1114');
check('three students, no row errors', count($r['rows']) === 3 && $r['details'] === []);
check('names keep accents (in capitals) and the optional Middle', $r['rows']['444400000003'][1] === 'PEÑA, JOSÉ REYES' && $r['rows']['444400000002'][1] === 'LIM, ANA');

echo "\n=== files that are not a workbook ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'xl');
$bad = ['plain text' => 'this is not a zip', 'empty file' => '', 'truncated zip' => substr((string) file_get_contents(__DIR__ . '/fixtures/roster_excel.xlsx'), 0, 200)];
foreach ($bad as $label => $content) {
    file_put_contents($tmp, $content);
    $threw = false;
    try {
        XlsxReader::readRows($tmp);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check("$label is rejected with an error, not a crash", $threw);
}
unlink($tmp);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
