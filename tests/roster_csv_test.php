<?php

require_once __DIR__ . '/../lib/RosterCsv.php';

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

function parseText(string $csv): array
{
    $h = fopen('php://memory', 'w+');
    fwrite($h, $csv);
    rewind($h);
    $r = RosterCsv::parse($h);
    fclose($h);
    return $r;
}

echo "=== the template layout ===\n";
$r = parseText("Strand:,STEM\nSection:,S1114\nLearning ID,Lastname,Firstname,Middle\n=\"123456789012\",Dela Cruz,Juan,Santos\n210987654321,Reyes,Ana,\n");
check('no file-level error', $r['error'] === null);
check('strand and section are read from the top', $r['strand'] === 'STEM' && $r['section'] === 'S1114');
check('both students are read', count($r['rows']) === 2);
check('the template\'s ="..." LRN becomes plain digits', isset($r['rows']['123456789012']));
check('name is "Lastname, Firstname Middle"', $r['rows']['123456789012'][1] === 'Dela Cruz, Juan Santos');
check('a blank Middle leaves no trailing space', $r['rows']['210987654321'][1] === 'Reyes, Ana');
check('no row-level problems', $r['details'] === []);

echo "\n=== forgiving about how Excel saves it ===\n";
$r = parseText("\xEF\xBB\xBFStrand:,stem,,\nSection:,S1114,,\nLearning ID,Lastname,Firstname,Middle\n123456789012,A,B,\n,,,\n");
check('byte-order mark and lower-case strand are accepted', $r['error'] === null && $r['strand'] === 'STEM');
check('blank lines are skipped', count($r['rows']) === 1);
$r = parseText("Strand: ABM\nSection: A1101\nLRN,Last Name,First Name\n123456789012,A,B\n");
check('"Strand: ABM" in one cell and the LRN / Last Name / First Name headers work', $r['error'] === null && $r['strand'] === 'ABM' && $r['section'] === 'A1101' && count($r['rows']) === 1);

echo "\n=== problems are reported ===\n";
$r = parseText("LRN,First Name,Last Name,Strand,Section\n123456789012,Juan,Dela Cruz,STEM,S1114\n");
check('the old five-column file is rejected with the template message', $r['error'] === RosterCsv::FORMAT_HELP);
$r = parseText("Strand:,PE\nSection:,S1114\nLearning ID,Lastname,Firstname,Middle\n123456789012,A,B,\n");
check('an unknown strand is rejected', $r['error'] !== null && strpos($r['error'], 'Strand must be') === 0);
$r = parseText("Strand:,STEM\nSection:,\nLearning ID,Lastname,Firstname,Middle\n123456789012,A,B,\n");
check('an empty Section: row is rejected', $r['error'] !== null && strpos($r['error'], 'Section') !== false);
$r = parseText("Strand:,STEM\nSection:,S1114\nLearning ID,Lastname,Firstname,Middle\n123,A,B,\n123456789012,,B,\n");
check('a bad LRN and a missing name are listed per row', count($r['details']) === 2 && strpos($r['details'][0], 'Row 4') === 0 && strpos($r['details'][1], 'Row 5') === 0);
$r = parseText('');
check('an empty file is reported', $r['error'] === 'The CSV file is empty.');
$r = parseText("Strand:,STEM\nSection:,S1114\n");
check('a file with no header row is rejected', $r['error'] === RosterCsv::FORMAT_HELP);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
