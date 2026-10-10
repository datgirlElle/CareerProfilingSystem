<?php

/**
 * The current Academic Year, worked out from today's date (school time,
 * Asia/Manila) instead of being typed in by an admin. An academic year runs
 * from July to June, so July 2026 through June 2027 is "2026-2027".
 */
class AcademicYear
{
    /** Month the academic year starts (1-12). */
    public const START_MONTH = 7;

    public static function current(?DateTimeImmutable $now = null): string
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $year = (int) $now->format('Y');
        $startYear = (int) $now->format('n') >= self::START_MONTH ? $year : $year - 1;
        return $startYear . '-' . ($startYear + 1);
    }
}
