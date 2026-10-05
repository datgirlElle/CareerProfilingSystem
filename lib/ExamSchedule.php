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

    /** Rooms are compared ignoring case and surrounding spaces ("Room 301" = " room 301 "). */
    public static function roomKey(string $room): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $room)));
    }

    /** Two time ranges on the same day overlap when each starts before the other ends (touching ends don't count). */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $a1 = substr($startA, 0, 5); $a2 = substr($endA, 0, 5);
        $b1 = substr($startB, 0, 5); $b2 = substr($endB, 0, 5);
        return $a1 < $b2 && $b1 < $a2;
    }

    /**
     * Pairs of sessions booked into the same room at overlapping times on the same date.
     *
     * @param array<int,array{id:int,examDate:string,startTime:string,endTime:string,room:string}> $sessions
     * @return array<int,array{room:string,examDate:string,a:array,b:array}>
     */
    public static function findConflicts(array $sessions): array
    {
        $conflicts = [];
        $n = count($sessions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $sessions[$i];
                $b = $sessions[$j];
                if ($a['examDate'] === $b['examDate']
                    && self::roomKey($a['room']) === self::roomKey($b['room'])
                    && self::timesOverlap($a['startTime'], $a['endTime'], $b['startTime'], $b['endTime'])) {
                    $conflicts[] = ['room' => $a['room'], 'examDate' => $a['examDate'], 'a' => $a, 'b' => $b];
                }
            }
        }
        return $conflicts;
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
