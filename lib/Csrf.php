<?php

/**
 * CSRF protection. Auth::start() must run first so a session exists.
 *
 * One token per session, generated once and reused for its lifetime.
 * The frontend fetches it via api/session.php and attaches it as the
 * X-CSRF-Token header on every state-changing request (see js/csrf.js).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $submittedToken): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$sessionToken || !$submittedToken) {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }

    /** Throws a 403 JSON response and exits if validation fails. */
    public static function requireValid(): void
    {
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::validate($submitted)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token. Please refresh the page and try again.']);
            exit;
        }
    }
}
