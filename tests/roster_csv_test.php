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

echo "=== the template layout (Student Number, Student Name, Email) ===\n";
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n=\"123456789012\",\"Dela Cruz, Juan Santos\",JSDelaCruz@live.mcl.edu.ph\n210987654321,\"Reyes, Ana\",anareyes@live.mcl.edu.ph\n");
check('no file-level error', $r['error'] === null);
check('strand and section are read from the top', $r['strand'] === 'STEM' && $r['section'] === 'S1114');
check('both students are read', count($r['rows']) === 2);
check('the template\'s ="..." Student Number becomes plain digits', isset($r['rows']['123456789012']));
check('names are stored in UPPER CASE as "LASTNAME, FIRSTNAME MIDDLE"', $r['rows']['123456789012'][1] === 'DELA CRUZ, JUAN SANTOS');
check('the email is kept (lower case)', $r['rows']['123456789012'][2] === 'jsdelacruz@live.mcl.edu.ph');
check('every student has an email', $r['rows']['210987654321'][2] === 'anareyes@live.mcl.edu.ph');
check('no row-level problems', $r['details'] === []);

echo "\n=== the student's email is required ===\n";
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n123456789012,\"A, B\",\n210987654321,\"C, D\",cd@live.mcl.edu.ph\n");
check('a blank email is reported with its row number', count($r['details']) === 1 && strpos($r['details'][0], 'Row 4') === 0 && strpos($r['details'][0], 'email is required') !== false);
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name\n123456789012,\"A, B\"\n");
check('a file with no Email column is refused and asks for the latest template', $r['error'] !== null && strpos($r['error'], 'no Email column') !== false);

echo "\n=== older layouts still load, with an Email column ===\n";
$old = parseText("Strand:,STEM\nSection:,S1114\nLearning ID,Lastname,Firstname,Middle,Email\n123456789012,Dela Cruz,Juan,Santos,jsdelacruz@live.mcl.edu.ph\n210987654321,Reyes,Ana,,anareyes@live.mcl.edu.ph\n");
check('the older header with separate Lastname / Firstname / Middle columns works', $old['error'] === null && count($old['rows']) === 2);
check('the separate columns are joined and upper-cased', $old['rows']['123456789012'][1] === 'DELA CRUZ, JUAN SANTOS' && $old['rows']['210987654321'][1] === 'REYES, ANA');
$old = parseText("Strand:,STEM\nSection:,S1114\nLearning ID,Lastname,Firstname,Middle,Email\n123456789012,A,B,,ab@live.mcl.edu.ph\n");
check('the Learning ID header also works', $old['error'] === null && count($old['rows']) === 1);

echo "\n=== forgiving about how Excel saves it ===\n";
$r = parseText("\xEF\xBB\xBFStrand:,stem,,\nSection:,s1114,,\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n,,,\n");
check('byte-order mark, lower-case strand and lower-case section are accepted', $r['error'] === null && $r['strand'] === 'STEM' && $r['section'] === 'S1114');
check('blank lines are skipped', count($r['rows']) === 1);
$r = parseText("Strand: ABM\nSection: A1101\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
check('"Strand: ABM" in one cell works', $r['error'] === null && $r['strand'] === 'ABM' && $r['section'] === 'A1101' && count($r['rows']) === 1);

echo "\n=== problems are reported ===\n";
$r = parseText("LRN,First Name,Last Name,Strand,Section\n123456789012,Juan,Dela Cruz,STEM,S1114\n");
check('the old five-column file is rejected with the template message', $r['error'] === RosterCsv::FORMAT_HELP);
$r = parseText("Strand:,STEM\nSection:,S1114\nLRN,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
check('a file whose number column is headed LRN is not accepted: the column is Student Number', $r['error'] !== null && empty($r['rows']));
$r = parseText("Strand:,PE\nSection:,S1114\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
check('an unknown strand is rejected', $r['error'] !== null && strpos($r['error'], 'Strand must be') === 0);
$r = parseText("Strand:,STEM\nSection:,\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
check('an empty Section: row is rejected', $r['error'] !== null && strpos($r['error'], 'Section') !== false);
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n123,\"A, B\",ab@live.mcl.edu.ph\n123456789012,\n");
check('a bad Student Number and a missing name are listed per row', count($r['details']) === 2 && strpos($r['details'][0], 'Row 4') === 0 && strpos($r['details'][1], 'Row 5') === 0);
check('the bad number message says Student Number', strpos($r['details'][0], 'Student Number') !== false && strpos($r['details'][0], 'LRN') === false);
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n123456789012,Juan Dela Cruz\n");
check('a name with no comma is rejected with the format to use', count($r['details']) === 1 && strpos($r['details'][0], 'LASTNAME, FIRSTNAME') !== false);
$r = parseText('');
check('an empty file is reported', $r['error'] === 'The file is empty.');
$r = parseText("Strand:,STEM\nSection:,S1114\n");
check('a file with no header row is rejected', $r['error'] === RosterCsv::FORMAT_HELP);

echo "\n=== emails ===\n";
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n123456789012,\"A, B\",not-an-email\n210987654321,\"C, D\",someone@gmail.com\n");
check('an invalid email and a non-school email are both reported by row', count($r['details']) === 2 && strpos($r['details'][0], 'Row 4') === 0 && strpos($r['details'][1], '@live.mcl.edu.ph') !== false);

echo "\n=== duplicates inside a file ===\n";
$r = parseText("Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n123456789012,\"C, D\",cd@live.mcl.edu.ph\n");
check('the same Student Number twice is reported, naming both rows', count($r['details']) === 1 && strpos($r['details'][0], 'Row 5') === 0 && strpos($r['details'][0], 'row 4') !== false);
check('the first one is kept', $r['rows']['123456789012'][1] === 'A, B');

echo "\n=== section names are checked ===\n";
foreach ([
    ['STEM', 'S1114', true], ['ABM', 'A1101', true], ['ICT', 'I1102', true], ['HUMSS', 'H1202', true],
    ['STEM', 'A1114', false], ['STEM', 'S114', false], ['STEM', 'S11145', false], ['STEM', 'S1314', false], ['STEM', '1114', false], ['STEM', 'ZZXLSX', false],
] as [$strand, $code, $ok]) {
    $r = parseText("Strand:,$strand\nSection:,$code\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
    check("$strand $code is " . ($ok ? 'accepted' : 'rejected'), ($r['error'] === null) === $ok);
}
$r = parseText("Strand:,STEM\nSection:,A1101\nStudent Number,Student Name,Email\n123456789012,\"A, B\",ab@live.mcl.edu.ph\n");
check('a section from the wrong strand says which letter STEM uses', strpos($r['error'], 'start with S') !== false);

echo "\n=== names with Ñ and accents in every way Excel can save a CSV ===\n";
$header = "Strand:,STEM\nSection:,S1114\nStudent Number,Student Name,Email\n";
$row = "123456789012,\"Dela Pe\xC3\xB1a, Jos\xC3\xA9 \xC3\x91\",jose@live.mcl.edu.ph\n"; // Dela Peña, José Ñ  (UTF-8 bytes)
$r = parseText($header . $row);
check('CSV UTF-8 keeps Ñ and accents, in capitals', $r['rows']['123456789012'][1] === "DELA PE\xC3\x91A, JOS\xC3\x89 \xC3\x91");
$ansi = $header . "123456789012,\"Dela Pe\xF1a, Jos\xE9 \xD1\",jose@live.mcl.edu.ph\n"; // Windows-1252 bytes
$r = parseText($ansi);
$name = $r['rows']['123456789012'][1];
check('Excel\'s plain CSV (Windows-1252) is converted to the same text', $name === "DELA PE\xC3\x91A, JOS\xC3\x89 \xC3\x91");
check('and the stored name is valid UTF-8 that can be sent as JSON', mb_check_encoding($name, 'UTF-8') && json_encode($name) !== false);
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
