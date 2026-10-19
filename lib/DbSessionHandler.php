<?php

require_once __DIR__ . '/Database.php';

/**
 * Stores PHP sessions in Postgres instead of local disk. Render's free web
 * service tier spins the container down after ~15 minutes idle and boots a
 * fresh one on the next request — local disk (including whatever
 * session.save_path points at) does not survive that, so file-based
 * sessions get silently wiped mid-use. Postgres is the one thing in this
 * deployment that actually persists across restarts.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    /** Why this request's session was ended by lib/SessionEnd.php (other_device, deactivated, password_changed), or null. */
    public static ?string $endedReason = null;

    /** False until db/migrate_add_session_user.php has run (no user_id / ended_reason columns): the old queries are used. */
    private static bool $hasUserColumns = true;

    public function __construct()
    {
        $this->pdo = Database::get();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if (self::$hasUserColumns) {
            try {
                $stmt = $this->pdo->prepare('SELECT data, ended_reason FROM sessions WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                // An ended session reads as empty (signed out); the reason is kept for api/session.php to show until the next sign-in.
                self::$endedReason = $row && $row['ended_reason'] !== null ? $row['ended_reason'] : null;
                return $row && $row['ended_reason'] === null ? $row['data'] : '';
            } catch (PDOException $e) {
                if ($e->getCode() !== '42703') { // 42703 = column does not exist (migration not run); anything else is a real error
                    throw $e;
                }
                self::$hasUserColumns = false;
            }
        }
        $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();
        return $data !== false ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        if (self::$hasUserColumns) {
            try {
                // "WHERE ended_reason IS NULL": a request that was still running when the session was ended cannot bring it back.
                $this->pdo->prepare(
                    'INSERT INTO sessions (id, data, last_activity, user_id) VALUES (?, ?, NOW(), ?)
                     ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = NOW(), user_id = EXCLUDED.user_id
                     WHERE sessions.ended_reason IS NULL'
                )->execute([$id, $data, isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null]);
                return true;
            } catch (PDOException $e) {
                if ($e->getCode() !== '42703') { // only a missing column falls back; the fallback has no ended_reason guard
                    throw $e;
                }
                self::$hasUserColumns = false;
            }
        }
        $this->pdo->prepare(
            'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, NOW())
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = NOW()'
        )->execute([$id, $data]);
        return true;
    }

    /** False until the migration has run; lib/SessionEnd.php then falls back to the old way. */
    public static function hasUserColumns(): bool
    {
        return self::$hasUserColumns;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_activity < NOW() - (? * INTERVAL '1 second')");
        $stmt->execute([$max_lifetime]);
        return $stmt->rowCount();
    }
}
