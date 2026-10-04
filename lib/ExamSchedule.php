<?php

/**
 * When an exam schedule counts as over. An exam is over once its end time on
 * its exam date has passed (school time, Asia/Manila — the same clock the rest
 * of the app uses, see config/env.php), not merely when the date has passed.
 *
 * Over exams are "archived": they leave the active list and their access code
 * stops working (api/verify-access-code.php). The row itself is never deleted,
 * since audit logs and historical reports still refer to it.
 */
class ExamSchedule
{
    /**
     * SQL condition (no placeholders) true for schedules whose exam has ended.
     * Assumes the exam_schedules columns are unqualified; pass an alias to qualify them.
     */
    public static function endedSql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "(({$p}exam_date + {$p}end_time) < (NOW() AT TIME ZONE 'Asia/Manila'))";
    }

    /** @param string $examDate Y-m-d  @param string $endTime H:i or H:i:s */
    public static function hasEnded(string $examDate, string $endTime, ?DateTimeImmutable $now = null): bool
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $end = new DateTimeImmutable($examDate . ' ' . substr($endTime, 0, 8), $tz);
        return $end < $now;
    }
}
