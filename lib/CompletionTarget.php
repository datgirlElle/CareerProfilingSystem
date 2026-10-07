<?php

/**
 * The completion target for the dashboard's per-section graph: a section whose assessment
 * completion is at or above it shows green, below it shows red. The Head of Guidance (admin)
 * can change it; until the adviser confirms the figure it starts at 80%.
 * Stored in security_policies under 'analytics.completionTarget', so it needs no table of its own.
 */
class CompletionTarget
{
    public const KEY = 'analytics.completionTarget';
    public const DEFAULT_PERCENT = 80;

    public static function isValid(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 100;
    }

    public static function get(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $stmt->execute([self::KEY]);
        $value = $stmt->fetchColumn();
        return $value !== false && ctype_digit((string) $value) && self::isValid((int) $value) ? (int) $value : self::DEFAULT_PERCENT;
    }

    public static function set(PDO $pdo, int $percent, ?int $userId): void
    {
        $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        )->execute([self::KEY, (string) $percent, $userId]);
    }
}