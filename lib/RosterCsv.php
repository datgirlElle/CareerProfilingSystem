<?php

require_once __DIR__ . '/Lrn.php';

/**
 * Reads a class-roster CSV in the template's layout: the strand and section
 * are written once at the top, then one student per row.
 *
 *   Strand:,STEM
 *   Section:,S1114
 *   LRN,Lastname,Firstname,Middle
 *   123456789012,Dela Cruz,Juan,Santos
 *
 * A file is exactly one section. api/roster-upload.php replaces that
 * section's students for the current academic year and leaves the rest alone.
 */
class RosterCsv
{
    public const VALID_STRANDS = ['STEM', 'ABM', 'ICT', 'HUMSS'];
    public const FORMAT_HELP = 'The file must start with "Strand:" and "Section:" rows, then a header row of LRN, Lastname, Firstname, Middle. Please download the roster template and use it.';

    /**
     * @param resource $handle an open CSV file
     * @return array{error:?string,strand:?string,section:?string,rows:array<string,array{0:string,1:string}>,details:array<int,string>}
     *         rows are keyed by LRN: [LRN, "Lastname, Firstname Middle"]. On a
     *         problem with the file as a whole, `error` is set; per-row problems
     *         are listed in `details`.
     */
    public static function parse($handle): array
    {
        $lines = [];
        // $escape is passed explicitly: PHP 8.4 notices when it is left unset.
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lines[] = $line;
        }
        return self::parseLines($lines);
    }

    /**
     * Same layout, from rows already split into cells (a CSV read above, or a
     * worksheet read by XlsxReader). Row n of the file is $lines[n - 1].
     *
     * @param array<int,array<int,mixed>> $lines
     * @return array{error:?string,strand:?string,section:?string,rows:array<string,array{0:string,1:string}>,details:array<int,string>}
     */
    public static function parseLines(array $lines): array
    {
        $result = ['error' => null, 'strand' => null, 'section' => null, 'rows' => [], 'details' => []];

        $numbered = [];
        foreach (array_values($lines) as $i => $line) {
            $numbered[$i + 1] = $line; // 1-based, matching the row numbers people see
        }
        $lines = $numbered;
        if (isset($lines[1][0])) {
            $lines[1][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $lines[1][0]); // Excel's UTF-8 byte-order mark
        }
        if (!array_filter($lines, fn($l) => count(array_filter($l, fn($v) => trim((string) $v) !== '')) > 0)) {
            $result['error'] = 'The file is empty.';
            return $result;
        }

        // Strand / Section rows, then the header row, in the first few lines.
        $headerLine = null;
        foreach ($lines as $num => $line) {
            if ($num > 8) {
                break;
            }
            $first = trim((string) ($line[0] ?? ''));
            if ($first === '') {
                continue;
            }
            if (preg_match('/^(strand|section)\s*:\s*(.*)$/i', $first, $m)) {
                $value = trim($m[2]);
                if ($value === '') {
                    foreach (array_slice($line, 1) as $cell) { // "Strand:" in one cell, the value in the next
                        if (trim((string) $cell) !== '') {
                            $value = trim((string) $cell);
                            break;
                        }
                    }
                }
                $result[strtolower($m[1])] = $value;
                continue;
            }
            if (self::isHeader($line)) {
                $headerLine = $num;
                break;
            }
        }

        if ($result['strand'] === null || $result['section'] === null || $headerLine === null) {
            $result['error'] = self::FORMAT_HELP;
            return $result;
        }

        $strand = strtoupper($result['strand']);
        $section = $result['section'];
        if (!in_array($strand, self::VALID_STRANDS, true)) {
            $result['error'] = 'Strand must be one of ' . implode(', ', self::VALID_STRANDS) . ($strand === '' ? ' (the Strand: row is empty).' : ' (found "' . $result['strand'] . '").');
            return $result;
        }
        if ($section === '' || mb_strlen($section) > 20) {
            $result['error'] = $section === '' ? 'The Section: row is empty. Enter the section code, for example S1114.' : 'Section code is too long.';
            return $result;
        }
        $result['strand'] = $strand;

        $header = array_map(fn($h) => strtolower(preg_replace('/\s+/', ' ', trim((string) $h))), $lines[$headerLine]);
        $col = function (array $names) use ($header): ?int {
            foreach ($names as $name) {
                $i = array_search($name, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }
            return null;
        };
        $idxId = $col(['student number', 'studentnumber', 'learning id', 'learningid', 'lrn']); // the older names still work
        $idxLast = $col(['lastname', 'last name']);
        $idxFirst = $col(['firstname', 'first name']);
        $idxMiddle = $col(['middle', 'middle name', 'middlename']);
        if ($idxId === null || $idxLast === null || $idxFirst === null) {
            $result['error'] = self::FORMAT_HELP;
            return $result;
        }

        foreach ($lines as $num => $line) {
            if ($num <= $headerLine) {
                continue;
            }
            if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }
            $lrn = trim((string) ($line[$idxId] ?? ''));
            if (preg_match('/^="?(\d+)"?$/', $lrn, $m)) {
                $lrn = $m[1]; // the template's ="123..." trick that keeps Excel from turning an LRN into 1.23E+11
            }
            $last = trim((string) ($line[$idxLast] ?? ''));
            $first = trim((string) ($line[$idxFirst] ?? ''));
            $middle = $idxMiddle !== null ? trim((string) ($line[$idxMiddle] ?? '')) : '';

            if ($lrn === '' || $last === '' || $first === '') {
                $result['details'][] = "Row $num: missing a required value (LRN, Lastname and Firstname are needed).";
                continue;
            }
            if (!Lrn::isValid($lrn)) {
                $result['details'][] = "Row $num: invalid LRN \"$lrn\" — " . Lrn::INVALID_MESSAGE;
                continue;
            }
            $result['rows'][$lrn] = [$lrn, "$last, $first" . ($middle !== '' ? " $middle" : '')];
        }

        return $result;
    }

    /** @param array<int,mixed> $line */
    private static function isHeader(array $line): bool
    {
        $first = strtolower(preg_replace('/\s+/', ' ', trim((string) ($line[0] ?? ''))));
        return in_array($first, ['student number', 'studentnumber', 'learning id', 'learningid', 'lrn'], true);
    }
}
