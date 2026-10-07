<?php

/**
 * Student Number rules, kept in one place so registration, login, the class
 * roster and the client-side hints stay in agreement.
 *
 * It is the number students already had (10 to 12 digits). Internally the value
 * is stored in the `school_id` / `username` columns; only what people see is
 * called "Student Number".
 */
class StudentNumber
{
    public const MIN_DIGITS = 10;
    public const MAX_DIGITS = 12;
    /** Students register and receive announcements at their Mapúa MCL student email. */
    public const EMAIL_DOMAIN = 'live.mcl.edu.ph';
    public const INVALID_MESSAGE = 'Student Number must be 10 to 12 digits (numbers only, no spaces or symbols).';

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '}$/', $value);
    }
}
