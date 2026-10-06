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

    /** True from a session's start time until its end time on its date (school time, Asia/Manila). */
    public static function isOpen(string $examDate, string $startTime, string $endTime, ?DateTimeImmutable $now = null): bool
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $start = new DateTimeImmutable($examDate . ' ' . substr($startTime, 0, 8), $tz);
        $end = new DateTimeImmutable($examDate . ' ' . substr($endTime, 0, 8), $tz);
        return $start <= $now && $now < $end;
    }

    /**
     * Where a student's group stands: the assessment is "activated" only while one of its
     * scheduled sessions is open. 'open' (now), 'upcoming' (next one not started),
     * 'ended' (all over) or 'none' (nothing scheduled).
     *
     * @param array<int,array{examDate:string,startTime:string,endTime:string,room?:string}> $sessions
     * @return array{state:string,examDate?:string,startTime?:string,endTime?:string,room?:string}
     */
    public static function windowFor(array $sessions, ?DateTimeImmutable $now = null): array
    {
        if (!$sessions) {
            return ['state' => 'none'];
        }
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $upcoming = null;
        $upcomingStart = null;
        foreach ($sessions as $s) {
            if (self::isOpen($s['examDate'], $s['startTime'], $s['endTime'], $now)) {
                return ['state' => 'open'] + self::pick($s);
            }
            $start = new DateTimeImmutable($s['examDate'] . ' ' . substr($s['startTime'], 0, 8), $tz);
            if ($start > $now && ($upcomingStart === null || $start < $upcomingStart)) {
                $upcoming = $s;
                $upcomingStart = $start;
            }
        }
        return $upcoming !== null ? ['state' => 'upcoming'] + self::pick($upcoming) : ['state' => 'ended'];
    }

    /** @return array{examDate:string,startTime:string,endTime:string,room:string} */
    private static function pick(array $s): array
    {
        return [
            'examDate' => $s['examDate'],
            'startTime' => substr($s['startTime'], 0, 5),
            'endTime' => substr($s['endTime'], 0, 5),
            'room' => (string) ($s['room'] ?? ''),
        ];
    }

    /**
     * The sessions scheduled for this student's group (same matching rule as the access-code
     * check: grade level, strand and section, where an empty column means everyone).
     *
     * @return array<int,array{examDate:string,startTime:string,endTime:string,room:string}>
     */
    public static function sessionsForStudent(PDO $pdo, int $studentUserId): array
    {
        $studentStmt = $pdo->prepare('SELECT strand, section, grade_level, academic_year FROM students WHERE user_id = ?');
        $studentStmt->execute([$studentUserId]);
        $student = $studentStmt->fetch();
        if (!$student || !$student['academic_year']) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT exam_date::text AS exam_date, start_time::text AS start_time, end_time::text AS end_time, room
             FROM exam_schedules
             WHERE academic_year = ?
               AND (grade_level IS NULL OR grade_level = ?)
               AND (strand IS NULL OR strand = ?)
               AND (section IS NULL OR section = ?)'
        );
        $stmt->execute([$student['academic_year'], $student['grade_level'], $student['strand'], $student['section']]);
        return array_map(fn($r) => [
            'examDate' => $r['exam_date'], 'startTime' => $r['start_time'], 'endTime' => $r['end_time'], 'room' => $r['room'],
        ], $stmt->fetchAll());
    }
}