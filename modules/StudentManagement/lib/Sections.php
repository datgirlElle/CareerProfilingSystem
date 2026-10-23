<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';

/**
 * Server-side source of truth for every place a section is accepted
 * (registration, exam scheduling, staff corrections) — client-side
 * dropdowns can't be trusted alone, and a typo'd section silently never
 * matches a real student (the exam access-code gate compares it exactly).
 *
 * Backed by the `sections` table (admin-manageable via api/sections.php +
 * Security Configuration's Section Management card) rather than a fixed
 * list, so a new school year's sections don't require a code change.
 */
class Sections
{
    public const STRANDS = ['STEM', 'ABM', 'ICT', 'HUMSS'];

    /** The letter every section code of a strand starts with: S1114 is STEM, A1101 is ABM. */
    public const STRAND_LETTER = ['STEM' => 'S', 'ABM' => 'A', 'ICT' => 'I', 'HUMSS' => 'H'];

    public const FORMAT_MESSAGE = 'A section code is one letter and four digits, for example S1114. The letter must match the strand (S for STEM, A for ABM, I for ICT, H for HUMSS), the first two digits are the grade level (11 or 12) and the last two are the section number (01 to 99).';

    /** Upper case and no spaces: " s1114 " -> "S1114". */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    /** Null when the code is acceptable for the strand, otherwise what is wrong with it. */
    public static function formatError(string $strand, string $code): ?string
    {
        $letter = self::STRAND_LETTER[$strand] ?? null;
        if ($letter === null) {
            return 'Invalid strand.';
        }
        if (!preg_match('/^([A-Z])(1[12])([0-9]{2})$/', $code, $m)) {
            return "\"$code\" is not a valid section code. " . self::FORMAT_MESSAGE;
        }
        if ($m[1] !== $letter) {
            return "Section $code does not belong to $strand: $strand sections start with $letter. " . self::FORMAT_MESSAGE;
        }
        if ($m[3] === '00') {
            return "\"$code\" has section number 00. The last two digits are the section number, from 01 to 99. " . self::FORMAT_MESSAGE;
        }
        return null;
    }

    /** Grade level a section code stands for ("S1114" -> "11"), or null if the code is not well-formed. */
    public static function gradeLevel(string $code): ?string
    {
        return preg_match('/^[A-Z](1[12])[0-9]{2}$/', $code, $m) ? $m[1] : null;
    }

    /** @return array<string,array<int,string>> e.g. ['STEM' => ['S1114','S1109'], ...] */
    public static function byStrand(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT strand, code FROM sections';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = TRUE';
        }
        $sql .= ' ORDER BY strand, code';

        $result = array_fill_keys(self::STRANDS, []);
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $result[$row['strand']][] = $row['code'];
        }
        return $result;
    }

    /**
     * What still refers to a section (by strand and code): registered students, class roster entries and assessment
     * schedules. $upcomingOnly counts only schedules that have not happened yet (what matters when deactivating).
     *
     * @return array<string,int> e.g. ['students' => 2, 'roster' => 1, 'schedules' => 0]
     */
    public static function usage(PDO $pdo, string $strand, string $code, bool $upcomingOnly = false): array
    {
        $queries = [
            'students' => 'SELECT COUNT(*) FROM students WHERE strand = ? AND LOWER(section) = LOWER(?)',
            'roster' => 'SELECT COUNT(*) FROM assessment_roster WHERE strand = ? AND LOWER(section) = LOWER(?)',
            'schedules' => 'SELECT COUNT(*) FROM exam_schedules WHERE strand = ? AND LOWER(section) = LOWER(?)'
                . ($upcomingOnly ? ' AND exam_date >= CURRENT_DATE' : ''),
        ];
        $result = [];
        foreach ($queries as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$strand, $code]);
            $result[$key] = (int) $stmt->fetchColumn();
        }
        return $result;
    }

    /** "2 registered students, 1 class roster entry", or '' when nothing uses the section. */
    public static function describeUsage(array $usage, bool $upcomingOnly = false): string
    {
        $labels = [
            'students' => ['registered student', 'registered students'],
            'roster' => ['class roster entry', 'class roster entries'],
            'schedules' => $upcomingOnly ? ['upcoming assessment schedule', 'upcoming assessment schedules'] : ['assessment schedule', 'assessment schedules'],
        ];
        $parts = [];
        foreach ($labels as $key => [$one, $many]) {
            $n = $usage[$key] ?? 0;
            if ($n > 0) {
                $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
            }
        }
        return implode(', ', $parts);
    }

    public static function isValid(PDO $pdo, string $strand, string $code, bool $activeOnly = true): bool
    {
        $sql = 'SELECT 1 FROM sections WHERE strand = ? AND code = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = TRUE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$strand, $code]);
        return (bool) $stmt->fetchColumn();
    }
}
