<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/AuditChain.php';

class AuditLogger
{
    public static function log(
        ?int $actorUserId,
        ?string $actorRole,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $detail = null
    ): void {
        $pdo = Database::get();
        $values = [
            $actorUserId,
            $actorRole,
            $action,
            $targetType,
            $targetId,
            $detail !== null ? Crypto::enc($detail) : null,
            self::clientIpFrom($_SERVER),
        ];
        // Sealed into the tamper-evident chain once its columns exist (lib/AuditChain.php).
        if (AuditChain::enabled($pdo)) {
            AuditChain::append($pdo, $values);
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, actor_role, action, target_type, target_id, detail_enc, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($values);
    }

    /**
     * The visitor's address. On Render the app sits behind Cloudflare and Render's proxy, so PHP sees the
     * proxy's private address and the visitor's arrives in headers the proxies add. Those headers are
     * only trusted where APP_BEHIND_PROXY=true says the app really is behind that proxy (anywhere else,
     * such as a school network, a visitor could send them), and only for a connection from a private or
     * local address: CF-Connecting-IP first (Cloudflare sets it and replaces any value a visitor sends),
     * then the last public address in X-Forwarded-For (proxies add to the end; a visitor can only add to
     * the front). Anything unusable falls back to the connection's own address.
     *
     * @param array<string,mixed> $server $_SERVER
     * @param bool|null $behindProxy null reads APP_BEHIND_PROXY (tests pass it)
     */
    public static function clientIpFrom(array $server, ?bool $behindProxy = null): ?string
    {
        $remote = self::validIp($server['REMOTE_ADDR'] ?? null);
        $behindProxy ??= envValue('APP_BEHIND_PROXY') === 'true';
        if ($remote === null || !$behindProxy || self::isPublic($remote)) {
            return $remote;
        }
        $cloudflare = self::validIp($server['HTTP_CF_CONNECTING_IP'] ?? null);
        if ($cloudflare !== null && self::isPublic($cloudflare)) {
            return $cloudflare;
        }
        $hops = explode(',', is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? $server['HTTP_X_FORWARDED_FOR'] : '');
        foreach (array_reverse($hops) as $hop) {
            $ip = self::validIp($hop);
            if ($ip !== null && self::isPublic($ip)) {
                return $ip;
            }
        }
        return $remote;
    }

    private static function validIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        // Postgres inet type rejects IPv6 with a zone id (e.g. fe80::1%eth0) — strip it defensively.
        $ip = explode('%', trim($value))[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // One way of writing each address (the shared-account check compares them as text), and an IPv4
        // address in IPv6 form (::ffff:a.b.c.d) as plain IPv4.
        $ip = (string) inet_ntop(inet_pton($ip));
        return str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? substr($ip, 7)
            : $ip;
    }

    /** Reachable on the internet: not private, local, shared (carrier) or reserved. */
    private static function isPublic(string $ip): bool
    {
        // FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+) also rules out shared 100.64.x addresses; older PHP gets the basic check.
        $flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }
}
