<?php

/**
 * Login device tracking, to spot a student lending their account. Each successful login records
 * where it came from (IP address, and a short device description such as "Chrome on Windows") in
 * the audit log. If the same student signs in from a different IP or device while another login
 * is still recent, it is logged as a possible shared account for the administrator to review.
 * Nobody is blocked automatically.
 */
class LoginDevice
{
    /** How long a login counts as "recent" for this check. */
    public const RECENT_MINUTES = 30;

    /** "Mozilla/5.0 (Windows NT 10.0…) Chrome/…" -> "Chrome on Windows". */
    public static function describe(string $userAgent): string
    {
        $ua = $userAgent;
        $browser = 'Unknown browser';
        if (stripos($ua, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
            $browser = 'Opera';
        } elseif (stripos($ua, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Safari/') !== false) {
            $browser = 'Safari';
        }
        $os = 'unknown device';
        if (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) {
            $os = 'iOS';
        } elseif (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) {
            $os = 'Mac';
        } elseif (stripos($ua, 'CrOS') !== false) {
            $os = 'ChromeOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }
        return "$browser on $os";
    }

    /**
     * Whether a new login looks like a second person: another recent login from a different IP
     * or device. $recent is the earlier logins, each ['ip' => ?string, 'device' => string].
     *
     * @param array<int,array{ip:?string,device:string}> $recent
     * @return array{ip:?string,device:string}|null the earlier login it conflicts with
     */
    public static function conflictsWith(?string $ip, string $device, array $recent): ?array
    {
        foreach ($recent as $r) {
            if ($r['ip'] !== $ip || $r['device'] !== $device) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Compares this login with the student's logins in the last RECENT_MINUTES.
     * Call it before logging the new login.
     */
    public static function findConflict(PDO $pdo, int $userId, ?string $ip, string $device): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT ip_address, detail_enc FROM audit_log
             WHERE actor_user_id = ? AND action = 'login' AND created_at > NOW() - (? * INTERVAL '1 minute')
             ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([$userId, self::RECENT_MINUTES]);
        $recent = [];
        foreach ($stmt->fetchAll() as $r) {
            $detail = $r['detail_enc'] !== null ? (string) Crypto::dec($r['detail_enc']) : '';
            $recent[] = [
                'ip' => $r['ip_address'],
                'device' => preg_match('/—\s*(.+)$/u', $detail, $m) ? trim($m[1]) : '',
            ];
        }
        return self::conflictsWith($ip, $device, $recent);
    }
}
