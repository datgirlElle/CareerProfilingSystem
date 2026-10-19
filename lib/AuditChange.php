<?php

/**
 * "What changed" for an audit entry: compares the values before and after an edit and keeps only
 * the ones that changed, in a structured form the audit log page draws as red (old) and green (new)
 * lines. The result is a JSON string meant for the `detail` argument of AuditLogger::log(), which
 * encrypts it.
 *
 *   AuditChange::detail('Account lockout', $before, $after, ['maxAttempts' => 'Failed attempts before lockout'])
 *   -> {"format":"change-1","summary":"Account lockout","changes":[{"field":"Failed attempts before lockout","before":"5","after":"10"}]}
 */
class AuditChange
{
    /** Longest value kept (characters); longer text is cut and ends with "...". */
    public const MAX_LENGTH = 300;

    /**
     * Marks a detail written by detail(). parse() requires it, so text a person typed elsewhere
     * (a program title, a template name) can never pass for a change record.
     */
    private const FORMAT = 'change-1';

    /**
     * @param string $summary what was edited, in plain words ("Account lockout", "FAQ for students")
     * @param array<string,mixed> $before field key => value before the edit
     * @param array<string,mixed> $after field key => value after the edit
     * @param array<string,string> $labels field key => name shown; also sets which fields count and their order
     * @param array<int,string> $yesNo keys whose true/false read Yes/No (other true/false read On/Off)
     */
    public static function detail(string $summary, array $before, array $after, array $labels, array $yesNo = []): string
    {
        return json_encode(
            ['format' => self::FORMAT, 'summary' => $summary, 'changes' => self::changes($before, $after, $labels, $yesNo)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Compares the full values (so an edit past the first MAX_LENGTH characters still counts) and
     * keeps the cut versions.
     *
     * @return array<int,array{field:string,before:string,after:string}> only the fields that changed
     */
    public static function changes(array $before, array $after, array $labels, array $yesNo = []): array
    {
        $changes = [];
        foreach ($labels as $key => $label) {
            $asYesNo = in_array($key, $yesNo, true);
            $oldFull = self::text($before[$key] ?? null, $asYesNo);
            $newFull = self::text($after[$key] ?? null, $asYesNo);
            if ($oldFull === $newFull) {
                continue;
            }
            $old = self::cut($oldFull);
            $new = self::cut($newFull);
            if ($old === $new) {
                $new .= ' (changed after the first ' . self::MAX_LENGTH . ' characters)';
            }
            $changes[] = ['field' => $label, 'before' => $old, 'after' => $new];
        }
        return $changes;
    }

    /** A value as people read it: On/Off or Yes/No, "(empty)" for nothing, long text cut. */
    public static function show(mixed $value, bool $yesNo = false): string
    {
        return self::cut(self::text($value, $yesNo));
    }

    /**
     * Reads a detail written by detail(). Returns null for any other text (older entries, or text a
     * person typed), so the caller can show that text as it is.
     *
     * @return array{summary:string,changes:array<int,array{field:string,before:string,after:string}>}|null
     */
    public static function parse(string $detail): ?array
    {
        $data = json_decode($detail, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !isset($data['changes']) || !is_array($data['changes'])) {
            return null;
        }
        $changes = [];
        foreach ($data['changes'] as $c) {
            if (is_array($c)) {
                $changes[] = [
                    'field' => (string) ($c['field'] ?? ''),
                    'before' => (string) ($c['before'] ?? ''),
                    'after' => (string) ($c['after'] ?? ''),
                ];
            }
        }
        return ['summary' => (string) ($data['summary'] ?? ''), 'changes' => $changes];
    }

    /** The full value as text, before any cutting. */
    private static function text(mixed $value, bool $yesNo): string
    {
        if (is_bool($value)) {
            return $yesNo ? ($value ? 'Yes' : 'No') : ($value ? 'On' : 'Off');
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '(empty)';
        }
        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text) > self::MAX_LENGTH ? rtrim(mb_substr($text, 0, self::MAX_LENGTH)) . '...' : $text;
    }
}
