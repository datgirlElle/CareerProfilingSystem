<?php

/**
 * Rules for a staff member's school email and the username they pick at sign-up.
 * The email must be on the school domain (no Gmail and the like); the username is
 * their own choice, letters and numbers only.
 */
class StaffEmail
{
    public const DOMAIN = 'mcl.edu.ph';

    public const USERNAME_MESSAGE = 'Choose a username of 3 to 20 letters and numbers, with at least one letter (no spaces or symbols).';

    /** @return array{ok:bool,error:?string} */
    public static function check(string $email): array
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || strtolower(substr($email, $at + 1)) !== self::DOMAIN || $at === 0) {
            return ['ok' => false, 'error' => 'Use your school email address ending in @' . self::DOMAIN . '. Personal emails such as Gmail are not accepted.'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * 3 to 20 letters and digits with at least one letter, so a username can never be
     * a bare number that could be mistaken for a student's Student Number.
     */
    public static function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9]{3,20}$/', $username);
    }
}
