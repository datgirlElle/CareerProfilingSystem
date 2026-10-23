<?php

/**
 * Pure helpers for the forgot-password flow (api/password-reset.php): the 6-digit emailed code, how it is
 * stored, and how the email address is masked on the "verify your identity" screen.
 *
 * Flow: the person asks for a code at their sign-in portal (student or staff) -> a 6-digit code is emailed ->
 * entering it correctly returns a short-lived, single-use reset token -> the token plus a new password resets
 * the account. The code is never stored in plain text, and neither is the token.
 */
class PasswordReset
{
    public const CODE_MINUTES = 10;       // how long an emailed code can be used
    public const RESEND_SECONDS = 60;     // wait before another code can be asked for
    public const MAX_SENDS_PER_HOUR = 3;  // codes per email address per hour
    public const MAX_WRONG_TRIES = 5;     // wrong entries before a code is cancelled
    public const TOKEN_MINUTES = 15;      // how long the new-password step stays open after a correct code
    public const PORTALS = ['student', 'staff'];

    /** Six random digits, leading zeros allowed: "004821". */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function isCode(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{6}$/', $value);
    }

    /** Tied to the account, so a code for one person can't be used for another. */
    public static function hashCode(int $userId, string $code): string
    {
        return hash('sha256', 'password-reset-code:' . $userId . ':' . $code);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', 'password-reset-token:' . $token);
    }

    /**
     * "chris.solis@gmail.com" -> "c•••••@gmail.com". Always masks the address that was TYPED, never one read
     * from the database, so the screen looks the same whether or not an account exists.
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '••••••';
        }
        $local = substr($email, 0, $at);
        $dots = str_repeat('•', max(3, min(6, strlen($local) - 1)));
        return mb_substr($local, 0, 1) . $dots . substr($email, $at);
    }
}
