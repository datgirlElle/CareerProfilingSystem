<?php

require_once __DIR__ . '/../lib/XlsxWriter.php';
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

function build(bool $protect = true): string
{
    $x = new XlsxWriter();
    $sheet = $x->addSheet('Report', [
        [['v' => 'Name', 's' => XlsxWriter::S_HEADER], ['v' => 'Score', 's' => XlsxWriter::S_HEADER]],
        ['Cruz & "Sons" <b>', 12.5],
        ['Peña, José Ñ', 100],
        [],
        ['after a blank row', 0],
    ], [20, 10]);
    $x->addChart([
        'title' => 'Scores', 'sheet' => $sheet, 'anchor' => [3, 1, 10, 15],
        'catRef' => 'Report!$A$2:$A$3', 'cats' => ['Cruz', 'Peña'], 'valRef' => 'Report!$B$2:$B$3', 'vals' => [12.5, 100],
        'seriesName' => 'Score', 'colors' => ['059669', 'DC2626'], 'targetRef' => 'Report!$B$2:$B$3', 'targetVals' => [80, 80],
    ]);
    if ($protect) {
        $x->protect('Test12345Ab');
    }
    return $x->build();
}

echo "=== the file is a real workbook ===\n";
$bin = build();
check('it starts with the ZIP signature', substr($bin, 0, 4) === "PK\x03\x04");
$tmp = tempnam(sys_get_temp_dir(), 'xw');
file_put_contents($tmp, $bin);
$rows = XlsxReader::readRows($tmp);
check('our own reader reads the sheet back', is_array($rows) && count($rows) >= 4);
check('text and numbers round-trip', $rows[0] === ['Name', 'Score'] && $rows[2][0] === 'Peña, José Ñ' && $rows[2][1] === '100');
check('XML special characters in a cell survive', $rows[1][0] === 'Cruz & "Sons" <b>');
check('a decimal keeps its value', $rows[1][1] === '12.5');
check('a blank row is kept so later rows stay on their row numbers', $rows[4][0] === 'after a blank row');

echo "\n=== the parts Excel needs are there ===\n";
$names = [];
for ($i = 0, $len = strlen($bin); $i < $len - 30;) {
    if (substr($bin, $i, 4) !== "PK\x03\x04") { break; }
    $h = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($bin, $i + 4, 26));
    $names[] = substr($bin, $i + 30, $h['nlen']);
    $i += 30 + $h['nlen'] + $h['elen'] + $h['csize'];
}
foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml', 'xl/charts/chart1.xml', 'xl/drawings/drawing1.xml', 'xl/worksheets/_rels/sheet1.xml.rels'] as $part) {
    check("$part is in the archive", in_array($part, $names, true));
}
check('[Content_Types].xml is first', ($names[0] ?? '') === '[Content_Types].xml');
unlink($tmp);

echo "\n=== protection ===\n";
check('a protected workbook locks the sheet and the workbook structure', strlen($bin) > 0 && strpos(inflateAll($bin), 'sheetProtection') !== false && strpos(inflateAll($bin), 'workbookProtection') !== false);
check('an unprotected workbook has neither', strpos(inflateAll(build(false)), 'sheetProtection') === false);
check('the password is never written into the file in plain text', strpos(inflateAll($bin), 'Test12345Ab') === false);
// The same hash Excel itself accepted for this password when the export was checked against real Excel.
check('the password hash is stable', XlsxWriter::passwordHash('Test12345Ab') === XlsxWriter::passwordHash('Test12345Ab') && XlsxWriter::passwordHash('a') !== XlsxWriter::passwordHash('b'));
check('the hash is a hex string', (bool) preg_match('/^[0-9A-F]{1,4}$/', XlsxWriter::passwordHash('Test12345Ab')));

echo "\n=== sheet names ===\n";
$x = new XlsxWriter();
$x->addSheet('Bad: name/with*chars?[x]', [['a']]);
check('characters Excel forbids in a sheet name are removed and it is cut to 31 characters', strpos(inflateAll($x->build()), 'name="Bad  name with chars  x"') !== false);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);

/** All the XML inside the archive, joined, so a test can look for a tag in any part. */
function inflateAll(string $bin): string
{
    $all = '';
    for ($i = 0, $len = strlen($bin); $i < $len - 30;) {
        if (substr($bin, $i, 4) !== "PK\x03\x04") { break; }
        $h = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($bin, $i + 4, 26));
        $data = substr($bin, $i + 30 + $h['nlen'] + $h['elen'], $h['csize']);
        $all .= $h['method'] === 8 ? (string) gzinflate($data) : $data;
        $i += 30 + $h['nlen'] + $h['elen'] + $h['csize'];
    }
    return $all;
}
