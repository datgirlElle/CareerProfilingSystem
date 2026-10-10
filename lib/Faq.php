<?php

/**
 * Rules for a Help Center FAQ, shared by api/faqs.php and its tests. FAQs are plain text:
 * the pages escape them, so nothing typed here can run as a script on a student's screen.
 */
class Faq
{
    public const AUDIENCES = ['student', 'staff'];
    public const QUESTION_MAX = 300;
    public const ANSWER_MAX = 2000;
    public const PER_AUDIENCE_MAX = 50;

    /** Tidies a question or answer: no stray control characters, trimmed; line breaks in an answer are kept. */
    public static function clean(string $text, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text) ?? ''; // drop control characters except the newline
        if (!$multiline) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        } else {
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        }
        return trim($text);
    }

    /** @return ?string what is wrong, or null when the question and answer are fine */
    public static function validate(string $question, string $answer): ?string
    {
        if ($question === '' || $answer === '') {
            return 'Write both the question and its answer.';
        }
        if (mb_strlen($question) > self::QUESTION_MAX) {
            return 'The question must be ' . self::QUESTION_MAX . ' characters or fewer.';
        }
        if (mb_strlen($answer) > self::ANSWER_MAX) {
            return 'The answer must be ' . self::ANSWER_MAX . ' characters or fewer.';
        }
        return null;
    }
}
