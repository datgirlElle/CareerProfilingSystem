<?php

require_once __DIR__ . '/Database.php';

/**
 * Generic sliding-window rate limiter backed by one shared table.
 *
 * One call does both jobs: check whether $key has already hit the limit,
 * and — if not — record this call as one of the attempts toward it. This
 * mirrors the same key-value-policy pattern already used elsewhere in the
 * app (e.g. login.php's lockoutPolicy()), just generalized so any endpoint
 * can reuse it with its own key/limit/window instead of writing its own
 * counting logic.
 */
class RateLimiter
{
    /**
     * @return bool true if $key has already hit $maxAttempts within the
     *              last $windowMinutes (caller should block/skip the
     *              action). false means this call was allowed AND has
     *              already been recorded — the caller doesn't need to
     *              record it separately.
     */
    public static function tooMany(string $key, int $maxAttempts, int $windowMinutes): bool
    {
        $pdo = Database::get();
        $cutoff = date('c', time() - $windowMinutes * 60);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ? AND created_at > ?');
        $countStmt->execute([$key, $cutoff]);
        $count = (int) $countStmt->fetchColumn();

        if ($count >= $maxAttempts) {
            return true;
        }

        $pdo->prepare('INSERT INTO rate_limit_hits (rate_key) VALUES (?)')->execute([$key]);

        // Opportunistic cleanup so this table doesn't grow forever — cheap
        // enough to run inline given this app's scale, no need for a
        // separate cron job.
        $pdo->exec("DELETE FROM rate_limit_hits WHERE created_at < NOW() - INTERVAL '1 day'");

        return false;
    }
}
