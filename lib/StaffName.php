<?php

/**
 * Staff (guidance counselor / facilitator) names are entered surname-first,
 * "Cruz, Juan D." (Last Name, First Name, M.I.), the same order student names
 * are shown in, so names look the same in every table, profile and report.
 */
class StaffName
{
    public const EXAMPLE = 'Cruz, Juan D.';
    public const FORMAT_MESSAGE = 'Enter your name as Last Name, First Name, M.I. — for example Cruz, Juan D.';

    /** Letters (any language), spaces, dots, hyphens and apostrophes either side of a single comma. */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match("/^[\p{L}][\p{L}\p{M}'’. -]*,\s*[\p{L}][\p{L}\p{M}'’. -]*$/u", trim($name));
    }

    /** "Cruz,  Juan D." -> "Cruz, Juan D." */
    public static function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        return preg_replace('/\s*,\s*/u', ', ', $name);
    }
}
