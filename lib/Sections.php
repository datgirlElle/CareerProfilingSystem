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
