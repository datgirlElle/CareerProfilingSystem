<?php

/**
 * The two guidance staff positions a person can sign up as. They are the same
 * system role ('counselor') with identical access — the position is only the
 * title shown next to their name, so nothing about permissions branches on it.
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
