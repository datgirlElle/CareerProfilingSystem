<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/AuditChain.php';

/**
 * How long audit log entries are kept (Security Configuration > Audit Log Retention). With "Delete older
 * logs automatically" on, entries older than the set number of days are deleted at most once a day; the
 * Audit Log page starts the check (api/audit-log-cleanup.php). Off by default: deleting is permanent, so
 * nothing is deleted until an administrator turns it on.
 */
class AuditRetention
{
    public const MIN_DAYS = 90;
    public const MAX_DAYS = 365;
    public const DEFAULT_DAYS = 365;

    /** security_policies keys. The days keep their older key, so a value saved before stays. */
    public const DAYS_KEY = 'monitoring.retentionDays';
    public const ON_KEY = 'retention.enabled';
    public const LAST_RUN_KEY = 'retention.lastRun';

    /** Days kept: a saved number held between MIN_DAYS and MAX_DAYS; nothing saved (or not a number) is DEFAULT_DAYS. */
    public static function days(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_DAYS;
        }
        return max(self::MIN_DAYS, min(self::MAX_DAYS, (int) $value));
    }

    /**
     * The setting from security_policies rows (key => value).
     *
     * @return array{autoDelete:bool,days:int}
     */
    public static function fromRows(array $rows): array
    {
        return [
            'autoDelete' => ($rows[self::ON_KEY] ?? 'false') === 'true',
            'days' => self::days($rows[self::DAYS_KEY] ?? null),
        ];
    }

    /** @return array{autoDelete:bool,days:int} */
    public static function settings(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT key, value FROM security_policies WHERE key IN (?, ?)');
        $stmt->execute([self::ON_KEY, self::DAYS_KEY]);
        return self::fromRows($stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Marks today's cleanup as done, so the first one after the switch is turned on runs tomorrow: a day
     * to export the log before anything is deleted.
     */
    public static function startTomorrow(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO security_policies (key, value) VALUES (?, CURRENT_DATE::text)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()'
        )->execute([self::LAST_RUN_KEY]);
    }

    /**
     * Deletes the entries older than the set days when the switch is on and today's cleanup has not run
     * yet, and logs it as System. Returns how many were deleted, or null when nothing ran.
     *
     * @param string $startedBy name of the person whose visit started it (kept in the encrypted detail)
     */
    public static function runIfDue(PDO $pdo, string $startedBy): ?int
    {
        $setting = self::settings($pdo);
        if (!$setting['autoDelete']) {
            return null;
        }
        // Claim today's run first: only one request a day gets the row back, even when two people open the page at once.
        $claim = $pdo->prepare(
            "INSERT INTO security_policies (key, value) VALUES (?, CURRENT_DATE::text)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
             WHERE security_policies.value IS DISTINCT FROM EXCLUDED.value
             RETURNING key"
        );
        $claim->execute([self::LAST_RUN_KEY]);
        if ($claim->fetchColumn() === false) {
            return null;
        }

        // The delete and its log entry are saved together or not at all, so entries never go without a
        // record. (A caller already inside a transaction keeps control of it.)
        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }
            $cutoff = $pdo->prepare(
                "SELECT NOW() - make_interval(days => CAST(? AS int)) AS at,
                        to_char(NOW() - make_interval(days => CAST(? AS int)), 'Mon FMDD, YYYY') AS shown"
            );
            $cutoff->execute([$setting['days'], $setting['days']]);
            $before = $cutoff->fetch();
            // Only an unbroken run from the oldest entry goes: old entries up to the first one that stays.
            // The tamper-evident chain (modules/AuditLog/lib/AuditChain.php) then still runs in one line from its new start.
            $last = $pdo->prepare(
                'SELECT MAX(id) FROM audit_log
                 WHERE created_at < CAST(? AS timestamptz)
                   AND id < COALESCE((SELECT MIN(id) FROM audit_log WHERE created_at >= CAST(? AS timestamptz)), 9223372036854775807)'
            );
            $last->execute([$before['at'], $before['at']]);
            $lastId = $last->fetchColumn();
            $deleted = is_numeric($lastId) ? AuditChain::removeUpTo($pdo, (int) $lastId) : 0;
            // Nothing old enough means nothing to report: a row every day would only add noise.
            if ($deleted > 0) {
                AuditLogger::log(null, null, 'delete_old_audit_logs', 'audit_log', null,
                    $deleted . ' ' . ($deleted === 1 ? 'entry' : 'entries') . ' older than ' . $setting['days'] . ' days (from before '
                        . $before['shown'] . '). Started when ' . $startedBy . ' opened the Audit Log.');
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[audit-retention] ' . $e->getMessage());
            AuditLogger::log(null, null, 'delete_old_audit_logs_failed', 'audit_log', null, 'The daily cleanup could not finish. It runs again tomorrow.');
            return null;
        }
        return $deleted;
    }
}
