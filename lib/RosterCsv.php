<?php

require_once __DIR__ . '/StudentNumber.php';
require_once __DIR__ . '/Sections.php';

/**
 * Reads a class-roster CSV in the template's layout: the strand and section
 * are written once at the top, then one student per row.
 *
 *   Strand:,STEM
 *   Section:,S1114
 *   Student Number,Student Name,Email
 *   123456789012,"DELA CRUZ, JUAN SANTOS",jsdelacruz@live.mcl.edu.ph
 *
 * Names are kept in UPPER CASE as "LASTNAME, FIRSTNAME MIDDLE". Every student needs an email: announcements
 * are emailed to it and the student must register with it. The older layout with separate Lastname,
 * Firstname and Middle columns and an "LRN" header still loads, but it needs the Email column too.
 *
 * A file is exactly one section. api/roster-upload.php replaces that
 * section's students for the current academic year and leaves the rest alone.
 */
class RosterCsv
{
    public const VALID_STRANDS = ['STEM', 'ABM', 'ICT', 'HUMSS'];
    public const FORMAT_HELP = 'The file must start with "Strand:" and "Section:" rows, then a header row of Student Number, Student Name, Email. Please download the roster template and use it.';

    /**
     * @param resource $handle an open CSV file
     * @return array{error:?string,strand:?string,section:?string,rows:array<string,array{0:string,1:string,2:string}>,details:array<int,string>}
     *         rows are keyed by Student Number: [number, "LASTNAME, FIRSTNAME MIDDLE", email]. On a
     *         problem with the file as a whole, `error` is set; per-row problems
     *         are listed in `details`.
     */
    public static function parse($handle): array
    {
        $lines = [];
        // $escape is passed explicitly: PHP 8.4 notices when it is left unset.
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            // Excel's plain "CSV (Comma delimited)" saves in Windows-1252, not UTF-8, so a name
            // like "Peña" arrives as a single byte that is not valid UTF-8 (and would later break
            // the student list). Convert any such cell; CSV UTF-8 files pass through unchanged.
            $lines[] = array_map(function ($cell) {
                $cell = (string) $cell;
                return mb_check_encoding($cell, 'UTF-8') ? $cell : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
            }, $line);
        }
        return self::parseLines($lines);
    }

    /**
     * Same layout, from rows already split into cells (a CSV read above, or a
     * worksheet read by XlsxReader). Row n of the file is $lines[n - 1].
     *
     * @param array<int,array<int,mixed>> $lines
     * @return array{error:?string,strand:?string,section:?string,rows:array<string,array{0:string,1:string,2:?string}>,details:array<int,string>}
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
        $section = Sections::normalizeCode($section);
        if ($section === '') {
            $result['error'] = 'The Section: row is empty. Enter the section code, for example S1114.';
            return $result;
        }
        $sectionError = Sections::formatError($strand, $section);
        if ($sectionError !== null) {
            $result['error'] = $sectionError;
            return $result;
        }
        $result['strand'] = $strand;
        $result['section'] = $section;

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
        $idxName = $col(['student name', 'studentname', 'name']);
        $idxLast = $col(['lastname', 'last name']);
        $idxFirst = $col(['firstname', 'first name']);
        $idxMiddle = $col(['middle', 'middle name', 'middlename']);
        $idxEmail = $col(['email', 'email address', 'student email']);
        $hasName = $idxName !== null || ($idxLast !== null && $idxFirst !== null);
        if ($idxId === null || !$hasName || $idxEmail === null) {
            $result['error'] = $idxId !== null && $hasName ? 'The file has no Email column. Every student needs their email, so please download the latest roster template and use it. ' . self::FORMAT_HELP : self::FORMAT_HELP;
            return $result;
        }

        $seen = [];
        foreach ($lines as $num => $line) {
            if ($num <= $headerLine) {
                continue;
            }
            if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }
            $number = trim((string) ($line[$idxId] ?? ''));
            if (preg_match('/^="?(\d+)"?$/', $number, $m)) {
                $number = $m[1]; // the template's ="123..." trick that keeps Excel from turning the number into 1.23E+11
            }

            if ($idxName !== null) {
                $name = trim(preg_replace('/\s+/u', ' ', (string) ($line[$idxName] ?? '')));
                $name = preg_replace('/\s*,\s*/u', ', ', $name);
                if ($name !== '' && !preg_match('/^[^,]+, ?[^,]+$/u', $name)) {
                    $result['details'][] = "Row $num: write the Student Name as LASTNAME, FIRSTNAME MIDDLE (with the comma), for example DELA CRUZ, JUAN SANTOS.";
                    continue;
                }
            } else {
                $last = trim((string) ($line[$idxLast] ?? ''));
                $first = trim((string) ($line[$idxFirst] ?? ''));
                $middle = $idxMiddle !== null ? trim((string) ($line[$idxMiddle] ?? '')) : '';
                $name = ($last !== '' && $first !== '') ? "$last, $first" . ($middle !== '' ? " $middle" : '') : '';
            }

            if ($number === '' || $name === '') {
                $result['details'][] = "Row $num: missing a required value (Student Number and Student Name are needed).";
                continue;
            }
            if (!StudentNumber::isValid($number)) {
                $result['details'][] = "Row $num: invalid Student Number \"$number\" — " . StudentNumber::INVALID_MESSAGE;
                continue;
            }

            $email = strtolower(trim((string) ($line[$idxEmail] ?? '')));
            if ($email === '') {
                $result['details'][] = "Row $num: the student's email is required (their @" . StudentNumber::EMAIL_DOMAIN . " address).";
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $result['details'][] = "Row $num: \"$email\" is not a valid email address.";
                continue;
            }
            if (!str_ends_with($email, '@' . StudentNumber::EMAIL_DOMAIN)) {
                $result['details'][] = "Row $num: the email must be the student's @" . StudentNumber::EMAIL_DOMAIN . " address (found \"$email\").";
                continue;
            }

            if (isset($seen[$number])) {
                $result['details'][] = "Row $num: Student Number $number is listed twice in this file (also on row {$seen[$number]}).";
                continue;
            }
            $seen[$number] = $num;
            $result['rows'][$number] = [$number, mb_strtoupper($name, 'UTF-8'), $email];
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
