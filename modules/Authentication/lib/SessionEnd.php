<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/DbSessionHandler.php';

/**
 * Signs one person out of their other sessions (sessions.user_id, db/migrate_add_session_user.php):
 *  - other_device      a student signed in on another device (one device at a time, api/login.php)
 *  - deactivated       the account was turned off (api/students.php, api/staff-approvals.php)
 *  - password_changed  the password was changed or reset (api/change-password.php, api/verify-password-change.php,
 *                      api/password-reset.php)
 * An ended session is emptied, not deleted, so the sign-in page on that device can say why (api/session.php) until
 * the person signs in again there (Auth::login gives the device a new session). Keeping the row also stops a request
 * that was still running from bringing the old sign-in back (modules/Authentication/lib/DbSessionHandler.php write()).
 * Known limit: two student sign-ins at the very same moment can both stay, because each new session is saved when
 * its request ends.
 */
class SessionEnd
{
    public const REASONS = ['other_device', 'deactivated', 'password_changed'];

    /** What the sign-in page says on the device that was signed out. */
    public const MESSAGES = [
        'other_device' => 'You were signed out because your account was used to sign in on another device. If that wasn\'t you, change your password.',
        'deactivated' => 'Your account was turned off. Please contact the Guidance Office.',
        'password_changed' => 'Your password was changed, so this device was signed out. Please sign in with your new password.',
    ];

    /**
     * Ends every session of $userId except $keepSessionId (the device making the change, or null for none).
     * Returns how many sessions were ended.
     */
    public static function endFor(PDO $pdo, int $userId, string $reason, ?string $keepSessionId = null): int
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown session end reason: $reason");
        }
        if (!DbSessionHandler::hasUserColumns() || $pdo->query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'sessions' AND column_name = 'ended_reason'"
        )->fetchColumn() === false) {
            // Before the migration: find the sessions by the user id inside the stored session text (the old way).
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE data LIKE ? AND id <> ?');
            $stmt->execute(['%user|a:%{s:2:"id";i:' . $userId . ';%', (string) $keepSessionId]);
            return $stmt->rowCount();
        }
        $stmt = $pdo->prepare("UPDATE sessions SET data = '', ended_reason = ?, user_id = NULL WHERE user_id = ? AND id <> ?");
        $stmt->execute([$reason, $userId, (string) $keepSessionId]);
        $ended = $stmt->rowCount();
        // Sessions from before the migration have no user_id until their next request: find those in the stored text.
        $old = $pdo->prepare("UPDATE sessions SET data = '', ended_reason = ? WHERE user_id IS NULL AND ended_reason IS NULL AND data LIKE ? AND id <> ?");
        $old->execute([$reason, '%user|a:%{s:2:"id";i:' . $userId . ';%', (string) $keepSessionId]);
        return $ended + $old->rowCount();
    }
}
