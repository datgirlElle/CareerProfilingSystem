<?php

require_once __DIR__ . '/StarterCareers.php';

/**
 * Helpers for the careers each program leads to (programs.careers, a Postgres
 * TEXT[]) and for the career a student states on the Career Worksheet.
 *
 * A program with no careers listed falls back to its starter list
 * (lib/StarterCareers.php) wherever a career is needed, so nothing breaks
 * before staff fill the lists in.
 */
class Careers
{
    public const MAX_PER_PROGRAM = 8;
    public const MAX_LENGTH = 80;

    /** @return array<int,string> */
    public static function parse(?string $raw): array
    {
        if ($raw === null || $raw === '' || $raw === '{}') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}"]+)/', $raw, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $value = ($match[1] ?? '') !== '' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $match[1]) : trim($match[2] ?? '');
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @param array<int,string> $values */
    public static function toLiteral(array $values): string
    {
        return '{' . implode(',', array_map(fn($v) => '"' . addcslashes($v, '\\"') . '"', $values)) . '}';
    }

    /**
     * Trims, drops blanks and case-insensitive duplicates, enforces the limits.
     *
     * @param mixed $input an array of strings, or a newline-separated string
     * @return array<int,string>
     * @throws InvalidArgumentException when a limit is exceeded
     */
    public static function normalize($input): array
    {
        if (is_string($input)) {
            $input = preg_split('/\r\n|\r|\n/', $input);
        }
        if (!is_array($input)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($input as $item) {
            $career = trim(preg_replace('/\s+/', ' ', (string) $item));
            if ($career === '') {
                continue;
            }
            if (mb_strlen($career) > self::MAX_LENGTH) {
                throw new InvalidArgumentException('Each career must be ' . self::MAX_LENGTH . ' characters or fewer.');
            }
            $key = mb_strtolower($career);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $career;
        }
        if (count($out) > self::MAX_PER_PROGRAM) {
            throw new InvalidArgumentException('A program can list at most ' . self::MAX_PER_PROGRAM . ' careers.');
        }
        return $out;
    }

    /**
     * The careers a student can choose / is shown for a program: its own list,
     * or the starter list for that program when staff haven't listed any yet.
     * A program name is never passed off as a career, so a program with
     * neither shows no careers.
     *
     * @param array<int,string> $careers
     * @return array<int,string>
     */
    public static function effective(array $careers, string $programTitle): array
    {
        return $careers !== [] ? $careers : StarterCareers::forProgram($programTitle);
    }
}
