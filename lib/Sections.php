<?php

require_once __DIR__ . '/Database.php';

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

    public const FORMAT_MESSAGE = 'A section code is one letter and four digits, for example S1114. The letter must match the strand (S for STEM, A for ABM, I for ICT, H for HUMSS) and the first two digits are the grade level (11 or 12).';

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
