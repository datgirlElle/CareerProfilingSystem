<?php
// Robustness checks for Crypto (CBC + PKCS#7 over ModifiedAES256). A pass here is NOT a proof of cryptographic strength.
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
$pass = 0; $fail = 0; $info = [];
function check(string $l, bool $c): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n"; }
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } }

foreach ([0, 1, 15, 16, 17, 31, 32, 1000] as $n) { $p = str_repeat('a', $n); check("round trip length $n", Crypto::dec(Crypto::enc($p)) === $p); }
foreach (['Ñandú 学生 😀', "line\nbreak\t\"quote'<script>", "\0nul\0"] as $u) { check('round trip special: ' . bin2hex(substr($u, 0, 6)), Crypto::dec(Crypto::enc($u)) === $u); }
check('fresh IV: same plaintext -> different ciphertext', Crypto::enc('same') !== Crypto::enc('same'));
check('ciphertext does not contain plaintext', stripos(base64_decode(Crypto::enc('SensitiveNote123')), 'SensitiveNote') === false);
check('malformed base64 rejected', throws(fn() => Crypto::dec('***not base64***')));
check('too short rejected', throws(fn() => Crypto::dec(base64_encode("\x01abc"))));
check('wrong version rejected', throws(fn() => Crypto::dec(base64_encode("\x09" . str_repeat('a', 32)))));
check('non-block-multiple rejected', throws(fn() => Crypto::dec(base64_encode("\x01" . str_repeat('a', 16 + 5)))));

// Tampering: with no MAC, many flips are silently accepted. Count outcomes over every ciphertext byte.
$plain = 'Counseling note: student is anxious about exams.';
$raw = base64_decode(Crypto::enc($plain));
$silent = 0; $rejected = 0; $total = strlen($raw) - 1;
for ($i = 1; $i < strlen($raw); $i++) {
    $t = $raw; $t[$i] = chr(ord($t[$i]) ^ 1);
    try { $out = Crypto::dec(base64_encode($t)); if ($out !== $plain) { $silent++; } } catch (Throwable $e) { $rejected++; }
}
echo "  INFO: flipping 1 bit in each of $total bytes: $silent decrypted to altered plaintext without any error, $rejected rejected\n";
check('tampered ciphertext is always detected (authenticated encryption)', $silent === 0);

// Wrong key
$ct = Crypto::enc('secret');
$tmp = sys_get_temp_dir() . '/pp_wrongkey_' . getmypid() . '.php';
$lib = str_replace(chr(92), '/', realpath(__DIR__ . '/../modules/Encryption/lib/Crypto.php'));
$k = base64_encode(random_bytes(32));
file_put_contents($tmp, "<?php \$_ENV['APP_AES_KEY'] = '$k'; require '$lib'; try { echo Crypto::dec('$ct') === 'secret' ? 'DECRYPTED' : 'GARBAGE'; } catch (Throwable \$e) { echo 'ERROR'; }");
$out = shell_exec('php ' . escapeshellarg($tmp)); unlink($tmp);
check('wrong key does not recover plaintext', trim((string) $out) !== 'DECRYPTED');

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
