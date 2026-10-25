<?php

require_once __DIR__ . '/StarterCareers.php';

/**
 * Helpers for the careers each program leads to (programs.careers, a Postgres
 * TEXT[]) and for the career a student states on the Career Worksheet.
 *
 * A program with no careers listed falls back to its starter list
 * (modules/CareerDataset/lib/StarterCareers.php) wherever a career is needed, so nothing breaks
 * before staff fill the lists in.
 */
class Careers
{
    // No school limit on how many careers a program lists. This is only a safety cap, so a runaway paste or a forged
    // request cannot store thousands (the official mapping lists 12 to 18 per program).
    public const MAX_PER_PROGRAM = 500;
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
        // Request-size guard, checked before any work: far more lines or text than the cap could ever need is refused
        // at once (repeats and blank lines are allowed some room, they are dropped below).
        $tooBig = 'That is too many careers at once. A program can list at most ' . self::MAX_PER_PROGRAM . ' careers.';
        if (is_string($input) && strlen($input) > self::MAX_PER_PROGRAM * (self::MAX_LENGTH + 2) * 2) {
            throw new InvalidArgumentException($tooBig);
        }
        if (is_array($input) && count($input) > self::MAX_PER_PROGRAM * 2) {
            throw new InvalidArgumentException($tooBig);
        }
        if (is_string($input)) {
            $input = preg_split('/\r\n|\r|\n/', $input);
        }
        if (!is_array($input)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($input as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                throw new InvalidArgumentException('Each career must be text.');
            }
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
     * Adds the careers of $official that $saved is missing, after the saved ones, never removing or reordering any
     * (compared like normalize(): spaces tidied, any capitals). Used by db/merge_official_careers.php.
     *
     * @param array<int,string> $saved
     * @param array<int,string> $official
     * @return array{0:array<int,string>,1:array<int,string>} [the merged list, the careers that were added]
     */
    public static function merge(array $saved, array $official): array
    {
        $key = fn(string $c) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $c)));
        $have = array_flip(array_map($key, $saved));
        $added = [];
        foreach ($official as $career) {
            $k = $key($career);
            if ($k !== '' && !isset($have[$k])) {
                $have[$k] = true;
                $added[] = trim(preg_replace('/\s+/', ' ', $career));
            }
        }
        return [array_merge($saved, $added), $added];
    }

    /**
     * How a list changed, for the Audit Log: careers added and removed (any capitals).
     *
     * @param array<int,string> $before
     * @param array<int,string> $after
     * @return array{added:int,removed:int}
     */
    public static function changes(array $before, array $after): array
    {
        $lower = fn(array $l) => array_map('mb_strtolower', $l);
        return [
            'added' => count(array_diff($lower($after), $lower($before))),
            'removed' => count(array_diff($lower($before), $lower($after))),
        ];
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
