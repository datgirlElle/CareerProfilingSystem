<?php
/**
 * Student list PDF export (modules/StudentManagement/lib/StudentListPdf.php): the page layout, every column heading
 * fitting its column, long names kept whole, status colors, and paging. No database needed.
 *
 *   php tests/student_list_pdf_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every name, Student Number and reference below is made up to check the software.
 */
require_once __DIR__ . '/../modules/StudentManagement/lib/StudentListPdf.php';

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

/** @return array<int,string> every page's drawing commands, decompressed */
function pageStreams(string $pdf): array
{
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    return array_map(fn($s) => (string) gzuncompress($s), $m[1]);
}

/** One row in StudentListPdf::COLUMNS order, without the # column (the PDF numbers the rows). */
function row(string $name, string $status = 'Pending', string $no = '2026-00001'): array
{
    return [$name, $no, 'STEM', 'S1101', 'Active', $status, $status === 'Pending' ? '-' : 'Oct 18, 2026', $status === 'Pending' ? '-' : 'RIA', 'No request'];
}

$meta = [
    'exportedBy' => 'Test, Staff A. (Guidance Counselor)',
    'exportedAt' => 'Oct 23, 2026 6:00 PM',
    'reference' => 'STU-20261023-ABCDEF12',
    'schoolYear' => '2026-2027',
    'filters' => 'Strand STEM; Assessment status: Pending',
    'counts' => 'In this list: 1 completed, 2 pending, 0 not registered, 0 mismatched.',
];

echo "=== layout ===\n";
$w = new PdfWriter();
check('the columns add up to the 720 points between the margins', abs(array_sum(array_column(StudentListPdf::COLUMNS, 1)) - 720.0) < 0.001);
$cut = array_filter(StudentListPdf::COLUMNS, fn($c) => $w->textWidth($c[0], 8.5, true) > $c[1] - 8);
check('every column heading fits its column (none cut short)', $cut === []);

$rows = [
    row('Dela Cruz-Villanueva, Maria Cristina Angelica'),
    row('Peña, José Ñino', 'Completed'),
    row('Santos, Ana', 'Mismatched'),
];
$pdf = StudentListPdf::build($rows, $meta);
$pages = pageStreams($pdf);
check('a real PDF', str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, "%%EOF\n"));
check('one page for three students', count($pages) === 1 && str_contains($pdf, '/Count 1'));
$p = $pages[0] ?? '';
check('header: title, who exported it, reference, school year, count, filters', str_contains($p, '(ProfilePath Student Information and Assessment Overview)')
    && str_contains($p, 'Exported by Test, Staff A. \\(Guidance Counselor\\)') && str_contains($p, 'STU-20261023-ABCDEF12')
    && str_contains($p, 'School year: 2026-2027') && str_contains($p, 'Students listed: 3') && str_contains($p, 'Filters: Strand STEM; Assessment status: Pending'));
check('the RA 10173 confidentiality line', str_contains($p, 'CONFIDENTIAL: contains personal data protected by the Data Privacy Act of 2012 \\(RA 10173\\)'));
check('every heading is printed whole', array_reduce(StudentListPdf::COLUMNS, fn($ok, $c) => $ok && str_contains($p, '(' . $c[0] . ')'), true));
check('a long name is printed whole, in a smaller size', str_contains($p, '(Dela Cruz-Villanueva, Maria Cristina Angelica)'));
check('accented names are written as Windows-1252 bytes', str_contains($p, "Pe\xF1a, Jos\xE9 \xD1ino"));
check('rows are numbered 1 to 3', str_contains($p, '(1)') && str_contains($p, '(2)') && str_contains($p, '(3)'));
/** True when $text is drawn in the color $rgb ("r g b"). */
function drawnIn(string $page, string $rgb, string $text): bool
{
    return preg_match('/' . preg_quote($rgb . ' rg BT ', '/') . '[^()]*' . preg_quote('(' . $text . ') Tj', '/') . '/', $page) === 1;
}
check('Pending is drawn in amber', drawnIn($p, '0.71 0.33 0.04', 'Pending'));
check('Completed is drawn in green', drawnIn($p, '0.08 0.5 0.24', 'Completed'));
check('Mismatched is drawn in red', drawnIn($p, '0.8 0.11 0.14', 'Mismatched'));
$fits = array_filter(['Not Registered', 'Mismatched', 'Completed', 'Pending'], fn($t) => $w->textWidth($t, 8.5) > StudentListPdf::COLUMNS[6][1] - 8);
check('every status fits the status column', $fits === []);
check('page number on the page', str_contains($p, '(Page 1 of 1)'));

echo "\n=== paging ===\n";
$perPage = StudentListPdf::rowsPerPage();
check("$perPage rows fit on a page (at least 20)", $perPage >= 20);
$many = [];
for ($i = 1; $i <= $perPage * 2 + 1; $i++) {
    $many[] = row(sprintf('Student %03d, Test', $i), 'Pending', sprintf('2026-%05d', $i));
}
$big = StudentListPdf::build($many, $meta);
$bigPages = pageStreams($big);
check(count($many) . ' rows make 3 pages', count($bigPages) === 3 && str_contains($big, '/Count 3'));
check('every page repeats the reference, the confidentiality line and the headings',
    array_reduce($bigPages, fn($ok, $pg) => $ok && str_contains($pg, 'STU-20261023-ABCDEF12') && str_contains($pg, 'CONFIDENTIAL') && str_contains($pg, '(Student No.)'), true));
check('numbering continues across pages (row ' . ($perPage + 1) . ' is first on page 2)', str_contains($bigPages[1] ?? '', '(' . ($perPage + 1) . ')') && !str_contains($bigPages[0], '(' . ($perPage + 1) . ')'));
check('"Page 3 of 3" on the last page', str_contains($bigPages[2] ?? '', '(Page 3 of 3)'));

$empty = pageStreams(StudentListPdf::build([], $meta));
check('no students: one page that says so', count($empty) === 1 && str_contains($empty[0], '(No students match these filters.)'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
