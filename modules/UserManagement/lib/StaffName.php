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

    /** One name part: letters (any language), spaces, dots, hyphens and apostrophes. */
    private static function isNamePart(string $s): bool
    {
        return (bool) preg_match("/^[\p{L}][\p{L}\p{M}'’. -]*$/u", $s);
    }

    /**
     * Builds "Last, First M." from the three separate sign-up inputs. The middle initial is
     * optional and is a single letter (a dot is added). Returns null when a part is not a valid name.
     */
    public static function compose(string $last, string $first, string $middleInitial = ''): ?string
    {
        $last = trim(preg_replace('/\s+/u', ' ', $last));
        $first = trim(preg_replace('/\s+/u', ' ', $first));
        $mi = trim($middleInitial);
        if (!self::isNamePart($last) || !self::isNamePart($first) || str_contains($last . $first, ',')) {
            return null;
        }
        $name = $last . ', ' . $first;
        if ($mi !== '') {
            if (!preg_match('/^(\p{L})\.?$/u', $mi, $m)) {
                return null;
            }
            $name .= ' ' . mb_strtoupper($m[1]) . '.';
        }
        return $name;
    }

    /** "Cruz,  Juan D." -> "Cruz, Juan D." */
    public static function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        return preg_replace('/\s*,\s*/u', ', ', $name);
    }
}
