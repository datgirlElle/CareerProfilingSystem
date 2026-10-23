<?php
/**
 * The visitor address the audit log records (AuditLogger::clientIpFrom): the connection's own address,
 * or, behind a proxy, the visitor's address from the proxy headers. No database needed.
 *
 *   php tests/audit_ip_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. The private addresses are made up; the public ones are well-known public
 * DNS servers (8.8.8.8, 1.1.1.1, 9.9.9.9), used only as examples of internet addresses.
 */
require_once __DIR__ . '/../modules/AuditLog/lib/AuditLogger.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

// As on Render (APP_BEHIND_PROXY=true); the "not behind a proxy" section passes false.
$ip = fn(array $server, bool $behindProxy = true) => AuditLogger::clientIpFrom($server, $behindProxy);

echo "=== no proxy ===\n";
check('local computer: 127.0.0.1 is kept', $ip(['REMOTE_ADDR' => '127.0.0.1']) === '127.0.0.1');
check('a connection straight from the internet is kept', $ip(['REMOTE_ADDR' => '8.8.8.8']) === '8.8.8.8');
check('...and its headers are ignored (a visitor could fake them)',
    $ip(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9']) === '8.8.8.8');
check('no address at all gives null', $ip([]) === null);

echo "\n=== not set as behind a proxy (local computer, school network) ===\n";
check('headers from a private address are ignored (anyone on the network could send them)',
    $ip(['REMOTE_ADDR' => '192.168.1.20', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8'], false) === '192.168.1.20');
check('a local computer keeps 127.0.0.1 even with a header', $ip(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'], false) === '127.0.0.1');

echo "\n=== behind a proxy (private connection address) ===\n";
check('Cloudflare header is used', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9']) === '9.9.9.9');
check('Cloudflare header wins over X-Forwarded-For',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']) === '9.9.9.9');
check('without it, X-Forwarded-For is used', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9']) === '9.9.9.9');
check('a fake address added in front by the visitor is skipped (last public one wins)',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 9.9.9.9']) === '9.9.9.9');
check('private proxy hops at the end are skipped', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.1.2.3']) === '9.9.9.9');
check('a shared (carrier) address 100.64.x counts as a proxy', $ip(['REMOTE_ADDR' => '100.64.0.1', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9']) === '9.9.9.9');
check('local computer with a proxy header (as on a dev machine behind a tunnel)',
    $ip(['REMOTE_ADDR' => '::1', 'HTTP_CF_CONNECTING_IP' => '2001:4860:4860::8888']) === '2001:4860:4860::8888');

echo "\n=== unusable headers fall back to the connection address ===\n";
check('text that is not an address', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip, <script>']) === '10.0.0.5');
check('a private Cloudflare value is not trusted', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '192.168.1.9']) === '10.0.0.5');
check('a header sent as a list is ignored', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => ['9.9.9.9']]) === '10.0.0.5');
check('an IPv6 zone id is removed (the database rejects it)', $ip(['REMOTE_ADDR' => 'fe80::1%eth0']) === 'fe80::1');

echo "\n=== one way of writing each address ===\n";
check('a long or uppercase IPv6 address is written short and lowercase',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '2001:4860:4860:0000:0000:0000:0000:8888']) === '2001:4860:4860::8888'
    && $ip(['REMOTE_ADDR' => 'FD00::ABCD'], false) === 'fd00::abcd');
check('an IPv4 address in IPv6 form (::ffff:8.8.8.8) is read as 8.8.8.8 (public, headers ignored)',
    $ip(['REMOTE_ADDR' => '::ffff:8.8.8.8', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9']) === '8.8.8.8');

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
