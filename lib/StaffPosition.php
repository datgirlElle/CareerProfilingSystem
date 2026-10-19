<?php

/**
 * The two guidance staff positions a person can sign up as. Both are the system
 * role ('counselor'), but access can differ: a Guidance Facilitator has its own
 * column in the permission table (Rbac::roleKey() returns 'facilitator', and
 * Rbac::FACILITATOR_DEFAULT_LIMITS caps it until that column is set). The
 * position is also the title shown next to their name.
 */
class StaffPosition
{
    public const LABELS = [
        'counselor' => 'Guidance Counselor',
        'facilitator' => 'Guidance Facilitator',
    ];

    public static function isValid(string $position): bool
    {
        return isset(self::LABELS[$position]);
    }

    /** Label for a stored position; accounts created before positions existed read as counselors. */
    public static function label(?string $position): string
    {
        return self::LABELS[$position ?? ''] ?? self::LABELS['counselor'];
    }
}
