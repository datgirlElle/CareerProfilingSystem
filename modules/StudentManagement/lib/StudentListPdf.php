<?php

require_once __DIR__ . '/../../../shared/lib/PdfWriter.php';

/**
 * The Student Information and Assessment Overview list as a PDF (api/students.php?format=pdf), with the
 * filters the page had on. Takes rows already written the way the page shows them, so it needs no
 * database and can be tested on its own (tests/student_list_pdf_test.php). Same layout as the audit log
 * export (modules/AuditLog/lib/AuditLogPdf.php).
 *
 * Every page repeats who exported it, when, the school year, the filters, the reference code (also saved
 * in the audit log entry for this export) and an RA 10173 confidentiality line, because pages of a long
 * list get separated.
 */
class StudentListPdf
{
    private const MARGIN = 36.0;
    private const FONT = 8.5;
    private const ROW_H = 15.0;
    private const HEADER_TOP = 130.0;   // grey table header box
    private const HEADER_H = 18.0;
    private const FIRST_ROW = 161.0;    // baseline of the first row
    private const LAST_ROW = 572.0;     // lowest baseline a row may use (the footer sits below)
    private const FOOTER_Y = 590.0;

    private const RED = [0.80, 0.11, 0.14];
    private const AMBER = [0.71, 0.33, 0.04];   // Pending, as on the page
    private const GREEN = [0.08, 0.50, 0.24];   // Completed
    private const INK = [0.12, 0.13, 0.15];
    private const GREY_TEXT = [0.42, 0.45, 0.50];
    private const GREY_FILL = [0.94, 0.95, 0.96];
    private const GREY_LINE = [0.88, 0.89, 0.91];

    /** Column titles and widths in points (they add up to the 720 points between the margins). */
    public const COLUMNS = [
        ['#', 26.0],
        ['Name', 140.0],
        ['Student No.', 72.0],
        ['Strand', 46.0],
        ['Section', 52.0],
        ['Account', 62.0],
        ['Assessment Status', 96.0],
        ['Assessment Date', 88.0],
        ['Top 3 RIASEC', 70.0],
        ['Counseling', 68.0],
    ];
    private const NAME_COLUMN = 1;
    private const STATUS_COLUMN = 6;
    private const MIN_NAME_FONT = 6.5;

    public static function rowsPerPage(): int
    {
        return (int) floor((self::LAST_ROW - self::FIRST_ROW) / self::ROW_H) + 1;
    }

    /**
     * @param list<list<string>> $rows one list per student, in COLUMNS order (the # column is filled in here)
     * @param array{title?:string,exportedBy:string,exportedAt:string,reference:string,schoolYear:string,filters:string,counts:string} $meta
     * @return string the bytes of the .pdf file
     */
    public static function build(array $rows, array $meta): string
    {
        $pdf = new PdfWriter();
        $pdf->setTitle('ProfilePath Student List ' . $meta['reference']);
        $chunks = $rows ? array_chunk($rows, self::rowsPerPage()) : [[]];
        $total = count($chunks);
        $number = 0;

        foreach ($chunks as $i => $chunk) {
            $pdf->addPage();
            self::pageHeader($pdf, $meta, count($rows));
            if (!$chunk) {
                $pdf->text(self::MARGIN + 4, self::FIRST_ROW, 'No students match these filters.', self::FONT, false, self::GREY_TEXT);
            }
            $y = self::FIRST_ROW;
            foreach ($chunk as $row) {
                $number++;
                $cells = array_values($row);
                array_unshift($cells, (string) $number);
                $x = self::MARGIN;
                foreach (self::COLUMNS as $c => [, $width]) {
                    $value = (string) ($cells[$c] ?? '');
                    $color = self::INK;
                    if ($c === self::STATUS_COLUMN) {
                        $color = $value === 'Mismatched' || $value === 'Not Registered' ? self::RED
                            : ($value === 'Pending' ? self::AMBER : ($value === 'Completed' ? self::GREEN : self::INK));
                    }
                    // A long name is set smaller rather than cut short, so the student can still be identified;
                    // only one that does not fit even then is shortened.
                    $size = self::FONT;
                    while ($c === self::NAME_COLUMN && $size > self::MIN_NAME_FONT && $pdf->textWidth($value, $size) > $width - 8) {
                        $size -= 0.25;
                    }
                    $pdf->text($x + 4, $y, $pdf->fit($value, $width - 8, $size), $size, false, $color);
                    $x += $width;
                }
                $pdf->line(self::MARGIN, $y + 5, PdfWriter::WIDTH - self::MARGIN, $y + 5, self::GREY_LINE);
                $y += self::ROW_H;
            }
            $pdf->text(self::MARGIN, self::FOOTER_Y, 'Reference ' . $meta['reference'] . '  |  Confidential', 8, false, self::GREY_TEXT);
            $pageText = 'Page ' . ($i + 1) . ' of ' . $total;
            $pdf->text(PdfWriter::WIDTH - self::MARGIN - $pdf->textWidth($pageText, 8), self::FOOTER_Y, $pageText, 8, false, self::GREY_TEXT);
        }
        return $pdf->output();
    }

    private static function pageHeader(PdfWriter $pdf, array $meta, int $count): void
    {
        $left = self::MARGIN;
        $wide = PdfWriter::WIDTH - 2 * self::MARGIN;
        $pdf->text($left, 50, $meta['title'] ?? 'ProfilePath Student Information and Assessment Overview', 14, true);
        $pdf->text($left, 66, $pdf->fit('Exported by ' . $meta['exportedBy'] . ' on ' . $meta['exportedAt'], $wide, 9), 9);
        $pdf->text($left, 79, $pdf->fit('Reference: ' . $meta['reference'] . '    School year: ' . $meta['schoolYear']
            . '    Students listed: ' . number_format($count), $wide, 9, true), 9, true);
        $pdf->text($left, 92, $pdf->fit('Filters: ' . $meta['filters'], $wide, 9), 9);
        $pdf->text($left, 105, $pdf->fit($meta['counts'], $wide, 9), 9, false, self::GREY_TEXT);
        $pdf->text($left, 120, 'CONFIDENTIAL: contains personal data protected by the Data Privacy Act of 2012 (RA 10173). '
            . 'For authorized guidance staff only. Do not share or post.', 8, true, self::RED);

        $pdf->rect($left, self::HEADER_TOP, $wide, self::HEADER_H, self::GREY_FILL);
        $x = $left;
        foreach (self::COLUMNS as [$title, $width]) {
            $pdf->text($x + 4, self::HEADER_TOP + 12, $pdf->fit($title, $width - 8, self::FONT, true), self::FONT, true, [0.25, 0.27, 0.30]);
            $x += $width;
        }
    }
}
