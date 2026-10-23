<?php

/**
 * Rules for an announcement template (a name plus the title and message a new announcement starts with).
 * Plain text: the pages escape it, and the announcement emails escape it too.
 */
class AnnouncementTemplate
{
    public const NAME_MAX = 80;
    public const TITLE_MAX = 255;
    public const BODY_MAX = 2000; // the same limit an announcement itself has
    public const MAX_TEMPLATES = 30;

    /** Tidies typed text: no control characters (except the line break), trimmed. A one-line field also collapses its spaces. */
    public static function clean(string $text, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text) ?? '';
        $text = $multiline ? (preg_replace("/\n{3,}/", "\n\n", $text) ?? '') : (preg_replace('/\s+/u', ' ', $text) ?? '');
        return trim($text);
    }

    /** @return ?string what is wrong, or null when the template is fine */
    public static function validate(string $name, string $title, string $body): ?string
    {
        if ($name === '' || $title === '' || $body === '') {
            return 'Write the template name, the title and the message.';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return 'The template name must be ' . self::NAME_MAX . ' characters or fewer.';
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            return 'The title must be ' . self::TITLE_MAX . ' characters or fewer.';
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return 'The message must be ' . self::BODY_MAX . ' characters or fewer.';
        }
        return null;
    }
}
