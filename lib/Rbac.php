<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

/**
 * Checks a logged-in user's access level for a module against the
 * security_rbac table (module, role) -> full|limited|none. This is what
 * actually enforces the RBAC matrix shown in security-configuration.html —
 * previously nothing in the app consulted it at all.
 */
class Rbac
{
    private const MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'sections'];

    /**
     * A Guidance Facilitator (users.staff_position = 'facilitator') can look at these but never
     * change them, whatever the matrix says: announcements, assessment schedules, sections.
     * Class rosters follow the same rule (see requireRosterEditor). Guidance Counselors and the
     * administrator (Head of Guidance) get whatever the matrix grants.
     */
    public const FACILITATOR_VIEW_ONLY = ['announcements', 'examinations', 'sections'];

    /** True for a signed-in Guidance Facilitator. */
    public static function isFacilitator(array $user): bool
    {
        if (($user['role'] ?? '') !== 'counselor') {
            return false;
        }
        $stmt = Database::get()->prepare('SELECT staff_position FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        return $stmt->fetchColumn() === 'facilitator';
    }

    private static function denyViewOnly(): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Guidance Facilitators have view-only access to this.']);
        exit;
    }

    /** Uploading or replacing a class roster: Guidance Counselor and administrator only. */
    public static function requireRosterEditor(): array
    {
        $user = self::requireAccess('rac', 'full');
        if (self::isFacilitator($user)) {
            self::denyViewOnly();
        }
        return $user;
    }

    public static function accessLevel(string $module, string $role): string
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException("Unknown RBAC module: $module");
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT access_level FROM security_rbac WHERE module = ? AND role = ?');
        $stmt->execute([$module, $role]);
        $level = $stmt->fetchColumn();
        $level = $level !== false ? $level : 'none';
        // Students read the announcements sent to them (and mark them read); they never post, edit or delete one,
        // so even a tampered table row can't give them more than view-only here.
        if ($role === 'student' && $module === 'announcements' && $level === 'full') {
            return 'limited';
        }
        return $level;
    }

    /**
     * Requires the logged-in user's role to have at least $minLevel access
     * to $module. Sends 401/403 and exits otherwise. Returns the current
     * user array on success, for convenience.
     */
    public static function requireAccess(string $module, string $minLevel = 'limited'): array
    {
        $user = Auth::requireLogin();
        $level = self::accessLevel($module, $user['role']);

        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        if (($rank[$level] ?? 0) < ($rank[$minLevel] ?? 1)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions for this action.']);
            exit;
        }
        if ($minLevel === 'full' && in_array($module, self::FACILITATOR_VIEW_ONLY, true) && self::isFacilitator($user)) {
            self::denyViewOnly();
        }
        return $user;
    }

    /**
     * Requires the logged-in user's role to be one of $roles — for
     * endpoints gated by a simple "must be this role" rule rather than the
     * module access-level matrix above (e.g. Staff Accounts, Security
     * Configuration). Sends 403 and exits otherwise. Returns the current
     * user array on success.
     */
    public static function requireRole(string ...$roles): array
    {
        $user = Auth::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        return $user;
    }
}
