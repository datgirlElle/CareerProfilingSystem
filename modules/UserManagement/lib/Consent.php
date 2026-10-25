<?php

/**
 * Consent receipts (RA 10173): when a person agrees to the Terms of Use and the Data Privacy Policy,
 * one row in consent_records keeps who agreed, to which policy version, how, and when. No IP address
 * or device is stored (only what is needed to show the consent was given).
 *
 * VERSION follows the "Last updated" date on privacy.html and terms.html. When those pages change,
 * change VERSION and LABEL here too, so new receipts name the policy the person actually saw.
 */
class Consent
{
    public const VERSION = '2026-10';
    public const LABEL = 'October 2026';
    public const METHODS = ['registration', 'staff_signup'];

    /** Readable name of a stored version ("2026-08" -> "August 2026"); unknown values are shown as they are. */
    public static function label(string $version): string
    {
        $date = DateTime::createFromFormat('!Y-m', $version);
        return $date && $date->format('Y-m') === $version ? $date->format('F Y') : $version;
    }

    /**
     * Saves a receipt for the current policy version. Call it inside the same transaction that creates
     * the account, so there is never an account from a sign-up form without its receipt.
     * Returns false (and saves nothing) until db/migrate_add_consent_records.php has been run.
     */
    public static function record(PDO $pdo, int $userId, string $method): bool
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown consent method: $method");
        }
        // Checked first instead of catching the error: a failed INSERT would abort the caller's transaction.
        if ($pdo->query("SELECT to_regclass('consent_records')")->fetchColumn() === null) {
            error_log('[consent] consent_records table missing; run db/migrate_add_consent_records.php');
            return false;
        }
        $pdo->prepare('INSERT INTO consent_records (user_id, policy_version, method) VALUES (?, ?, ?)')
            ->execute([$userId, self::VERSION, $method]);
        return true;
    }

    /** @return array{version:string,label:string,method:string,consentedAt:string}|null the newest receipt, or null if none */
    public static function latestFor(PDO $pdo, int $userId): ?array
    {
        if ($pdo->query("SELECT to_regclass('consent_records')")->fetchColumn() === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT policy_version, method, consented_at FROM consent_records WHERE user_id = ? ORDER BY consented_at DESC, id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'version' => $row['policy_version'],
            'label' => self::label($row['policy_version']),
            'method' => $row['method'],
            'consentedAt' => $row['consented_at'],
        ];
    }
}
