<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/Auth.php';

/**
 * Checks a logged-in user's access level for a feature against the security_rbac table (module, role) ->
 * full|limited|none. This is what actually enforces the permission table shown on Security Configuration.
 *
 * The rows are the system's features, grouped under the sidebar page each one lives on (PAGES). A page whose features
 * are all No Access for someone is closed to them: the sidebar grays it out (js/admin-nav.js) and router.php refuses it.
 */
class Rbac
{
    /**
     * Each feature, in the order of the sidebar, with its default level for the Guidance Counselor and the Guidance
     * Facilitator (the permission table agreed with the team, 2026-10-24). The administrator always has Full. Used when a
     * row is missing from the table, by db/seed_security_defaults.php and by db/migrate_rbac_by_page.php.
     */
    public const DEFAULTS = [
        // Student Information
        'dashboard'         => ['counselor' => 'limited', 'facilitator' => 'limited'],
        'studentInfo'       => ['counselor' => 'full',    'facilitator' => 'limited'],
        'studentAccounts'   => ['counselor' => 'limited', 'facilitator' => 'none'],
        'assessmentResults' => ['counselor' => 'full',    'facilitator' => 'limited'],
        'recommendations'   => ['counselor' => 'full',    'facilitator' => 'limited'],
        'notesCreate'       => ['counselor' => 'full',    'facilitator' => 'full'],
        'exportResults'     => ['counselor' => 'limited', 'facilitator' => 'none'],
        // Analytics & Report
        'analytics'         => ['counselor' => 'limited', 'facilitator' => 'none'],
        'analyticsEmail'    => ['counselor' => 'none',    'facilitator' => 'none'],
        // Assessment
        // Guidance Counselors add and edit programs and their RIASEC codes; Facilitators view them.
        'programs'          => ['counselor' => 'full',    'facilitator' => 'limited'],
        'questionBank'      => ['counselor' => 'full',    'facilitator' => 'full'],
        'examinations'      => ['counselor' => 'full',    'facilitator' => 'full'],
        'monitoring'        => ['counselor' => 'full',    'facilitator' => 'limited'],
        // Student Support
        'announcements'     => ['counselor' => 'limited', 'facilitator' => 'limited'],
        'counselingHistory' => ['counselor' => 'full',    'facilitator' => 'limited'],
        'counselingStatus'  => ['counselor' => 'full',    'facilitator' => 'none'],
        'helpCenter'        => ['counselor' => 'full',    'facilitator' => 'limited'],
        // Administration
        'roster'            => ['counselor' => 'limited', 'facilitator' => 'none'],
        'staffAccounts'     => ['counselor' => 'none',    'facilitator' => 'none'],
    ];

    /**
     * The staff pages and the features on each. A page is open to someone when at least one of its features is View Only
     * or Full for them. The first ten (SIDEBAR) are the sidebar's work pages, in its order; the rest are pages reached
     * from them. Pages not listed here or in ADMIN_PAGES (My Access, staff profile, notifications) are open to all staff.
     */
    public const PAGES = [
        'admin-dashboard'         => ['dashboard', 'studentInfo'],
        'analytics'               => ['analytics'],
        'career-dataset'          => ['programs'],
        'question-bank'           => ['questionBank'],
        'assessment-scheduling'   => ['examinations'],
        'monitoring'              => ['monitoring'],
        'announcements'           => ['announcements'],
        'counseling-requests'     => ['counselingHistory', 'counselingStatus'],
        'staff-help-center'       => ['helpCenter'],
        'account-management'      => ['roster', 'staffAccounts'],
        'student-profile'         => ['studentInfo'],
        'monitoring-details'      => ['monitoring'],
        'add-career'              => ['programs'],
        'all-recommended-careers' => ['recommendations'],
    ];

    /** The sidebar's work pages, in its order: staff land on the first one open to them. */
    public const SIDEBAR = [
        'admin-dashboard', 'analytics', 'career-dataset', 'question-bank', 'assessment-scheduling', 'monitoring',
        'announcements', 'counseling-requests', 'staff-help-center', 'account-management',
    ];

    /**
     * The Head of Guidance's own tools: only the administrator ever opens them (they are not in the permission table and are
     * not shown to other staff at all). Counselors and facilitators see their own access on My Access instead.
     */
    public const ADMIN_PAGES = ['security-configuration', 'audit-log'];

    /** Where staff go when every sidebar page is closed to them: their own access, which says why. */
    public const FALLBACK_PAGE = 'my-access';

    /** The roles in the access matrix. Guidance Counselors and Guidance Facilitators share the 'counselor' system role but have a column each. */
    public const ROLES = ['admin', 'counselor', 'facilitator', 'student'];

    /**
     * Used only by the older migrations (db/migrate_add_facilitator_role.php and the ones before it) that seeded the
     * previous feature list. db/migrate_rbac_by_page.php replaces those rows.
     */
    public const FACILITATOR_DEFAULT_LIMITS = ['sections' => 'limited', 'staffAccounts' => 'none'];

    /** The starting level of a Guidance Facilitator for $module, given the Guidance Counselor's level (older migrations only). */
    public static function facilitatorDefault(string $module, string $counselorLevel): string
    {
        $cap = self::FACILITATOR_DEFAULT_LIMITS[$module] ?? null;
        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        if ($cap === null || ($rank[$counselorLevel] ?? 0) <= $rank[$cap]) {
            return $counselorLevel;
        }
        return $cap;
    }

    /** The three levels, from most to least access. */
    public const LEVELS = ['full', 'limited', 'none'];

    /** The only feature a Student can ever hold: reading their own recommendations. Everything else is No Access, whatever the table says. */
    public const STUDENT_MODULES = ['recommendations'];

    /** Features about accounts: changing who holds them asks for a confirmation before it is saved. */
    public const SENSITIVE_MODULES = ['staffAccounts', 'studentAccounts'];

    /** Every feature in the table. */
    public static function modules(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Changing the Guidance Counselor column, or any account feature, is a sensitive change. */
    public static function isSensitiveChange(string $module, string $targetRole): bool
    {
        return in_array($module, self::SENSITIVE_MODULES, true) || $targetRole === 'counselor';
    }

    /**
     * The levels $actorKey may set for a cell of the matrix (module x role), or [] when the cell is fixed for them.
     *  - Only the administrator (Head of Guidance) changes permissions: the Guidance Counselor and Guidance Facilitator
     *    columns. Everyone else only looks at their own access.
     *  - The administrator column and the Student column are never editable (nobody can lock the Head of Guidance out, and a
     *    Student can never be given a staff feature).
     *
     * @param array<string,array<string,string>> $matrix module => role => level (kept for callers; not needed any more)
     * @return array<int,string>
     */
    public static function editableLevels(string $actorKey, string $module, string $targetRole, array $matrix): array
    {
        if (!isset(self::DEFAULTS[$module]) || !in_array($targetRole, ['counselor', 'facilitator'], true) || $actorKey !== 'admin') {
            return [];
        }
        return self::LEVELS;
    }

    /** The matrix column for a signed-in user: 'admin', 'counselor', 'facilitator' or 'student'. */
    public static function roleKey(array $user): string
    {
        return self::isFacilitator($user) ? 'facilitator' : (string) ($user['role'] ?? '');
    }

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

    /** Uploading or replacing a class roster, adding or deactivating sections: Full on Student roster management. */
    public static function requireRosterEditor(): array
    {
        return self::requireAccess('roster', 'full');
    }

    /** @var array<string,array<string,string>> role => saved rows, read once per request */
    private static array $saved = [];

    /** Every feature's level for $role: the saved row, else the default. One query per role per request. */
    public static function levelsFor(string $role): array
    {
        if (!isset(self::$saved[$role])) {
            $stmt = Database::get()->prepare('SELECT module, access_level FROM security_rbac WHERE role = ?');
            $stmt->execute([$role]);
            self::$saved[$role] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $levels = [];
        foreach (self::DEFAULTS as $module => $defaults) {
            if ($role === 'admin') {
                // The administrator always has every feature, whatever the table says, so a missing or changed row can
                // never lock the Head of Guidance out.
                $levels[$module] = 'full';
            } elseif ($role === 'student') {
                // A Student holds only what is in STUDENT_MODULES, even if a row is tampered with. (Students still read the
                // announcements sent to them: api/announcements.php serves that without this permission.)
                $levels[$module] = in_array($module, self::STUDENT_MODULES, true) ? (self::$saved[$role][$module] ?? 'full') : 'none';
            } else {
                $level = self::$saved[$role][$module] ?? ($defaults[$role] ?? 'none');
                $levels[$module] = in_array($level, self::LEVELS, true) ? $level : 'none';
            }
        }
        return $levels;
    }

    public static function accessLevel(string $module, string $role): string
    {
        if (!isset(self::DEFAULTS[$module])) {
            throw new InvalidArgumentException("Unknown RBAC module: $module");
        }
        return self::levelsFor($role)[$module];
    }

    /** Forget the levels read in this request (after the administrator saves the table). */
    public static function forgetSaved(): void
    {
        self::$saved = [];
    }

    /** True when $level is at least $minLevel. */
    public static function atLeast(string $level, string $minLevel): bool
    {
        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        return ($rank[$level] ?? 0) >= ($rank[$minLevel] ?? 1);
    }

    /** Whether the staff page $page is open to $role (ADMIN_PAGES: the administrator only; pages not in PAGES are open). */
    public static function pageOpen(string $page, string $role): bool
    {
        if (in_array($page, self::ADMIN_PAGES, true)) {
            return $role === 'admin';
        }
        if (!isset(self::PAGES[$page])) {
            return true;
        }
        $levels = self::levelsFor($role);
        foreach (self::PAGES[$page] as $module) {
            if (self::atLeast($levels[$module], 'limited')) {
                return true;
            }
        }
        return false;
    }

    /** The pages closed to $role, for the sidebar. */
    public static function closedPages(string $role): array
    {
        return array_values(array_filter(array_keys(self::PAGES), fn($page) => !self::pageOpen($page, $role)));
    }

    /** Where a staff member lands: the first open sidebar page, else My Access. */
    public static function firstOpenPage(string $role): string
    {
        foreach (self::SIDEBAR as $page) {
            if (self::pageOpen($page, $role)) {
                return $page;
            }
        }
        return self::FALLBACK_PAGE;
    }

    /**
     * Requires the logged-in user's role to have at least $minLevel access
     * to $module. Sends 401/403 and exits otherwise. Returns the current
     * user array on success, for convenience.
     */
    public static function requireAccess(string $module, string $minLevel = 'limited'): array
    {
        $user = Auth::requireLogin();
        if (!self::atLeast(self::accessLevel($module, self::roleKey($user)), $minLevel)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions for this action.']);
            exit;
        }
        return $user;
    }

    /**
     * Staff Accounts (approving staff sign-ups, sending access codes, assigning sections, deactivating staff). The
     * administrator always has it; for everyone else the table decides.
     */
    public static function requireStaffAccounts(string $minLevel = 'full'): array
    {
        return self::requireAccess('staffAccounts', $minLevel);
    }

    /**
     * Requires the logged-in user's role to be one of $roles — for
     * endpoints gated by a simple "must be this role" rule rather than the
     * module access-level matrix above. Sends 403 and exits otherwise.
     * Returns the current user array on success.
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
