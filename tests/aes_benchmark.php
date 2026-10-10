<?php

/**
 * Modified AES-256 vs standard AES-256: a benchmark, not a pass/fail test (it is deliberately not named
 * *_test.php, so the normal test run skips it).
 *
 *   php tests/aes_benchmark.php            full run (about a minute)
 *   php tests/aes_benchmark.php --quick    smaller run (about 15 seconds)
 *   php tests/aes_benchmark.php --out=results.md    also write the report as a Markdown file
 *   php tests/aes_benchmark.php --seed=7   different (but repeatable) test data
 *
 * "Standard" here means the same pure-PHP AES-256 code with the ordinary fixed S-box, so the comparison
 * with the key-dependent S-box ("Modified") is like for like. PHP's built-in OpenSSL AES-256 is listed
 * only as a reference point: it is native C code and is not comparable in speed to either.
 *
 * What is measured, and what it can and cannot show:
 *  1. Correctness      both ciphers decrypt what they encrypt; standard matches the NIST test vector.
 *  2. Speed            encryption/decryption time over repeated runs, with a Welch t-test for whether the
 *                      difference between Standard and Modified is real or just run-to-run noise.
 *  3. Randomness       avalanche effect (flip one input or key bit; ideal is about 50% of output bits
 *                      changing) and the entropy / chi-square uniformity of ciphertext made from a very
 *                      repetitive plaintext.
 *  4. S-box quality    nonlinearity and differential uniformity, the standard measures of resistance to
 *                      linear and differential attacks, for the AES S-box and for key-dependent ones.
 *  5. Brute force      a real exhaustive key search, run on a key space reduced to a few bits, giving
 *                      guesses per second; the full 256-bit search is then only extrapolated, never run.
 * None of these proves a cipher is secure, and a small difference between two numbers is not evidence
 * that one cipher is better: the report says which differences pass the statistical test.
 */

require_once __DIR__ . '/../lib/ModifiedAES256.php';

date_default_timezone_set('Asia/Manila'); // the school's time, so the report's date matches the wall clock

$quick = in_array('--quick', $argv, true);
$seed = 20261008;
$outFile = null;
foreach ($argv as $a) {
    if (preg_match('/^--seed=(\d+)$/', $a, $m)) { $seed = (int) $m[1]; }
    if (preg_match('/^--out=(.+)$/', $a, $m)) { $outFile = $m[1]; }
}
mt_srand($seed);

$P = [
    'runs' => $quick ? 8 : 15,            // timing runs per cipher and direction
    'kib' => $quick ? 8 : 16,             // data per timing run
    'avalanche' => $quick ? 150 : 400,    // trials per avalanche test
    'sboxKeys' => $quick ? 15 : 40,       // keys for the S-box quality sample
    'entropyKeys' => $quick ? 3 : 5,
    'bruteBits' => $quick ? 10 : 12,      // unknown key bits in the brute-force search
    'bruteTargets' => $quick ? 3 : 5,
];

// ------------------------------------------------------------------ helpers

function rbytes(int $n): string
{
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= chr(mt_rand(0, 255)); }
    return $s;
}
function mean(array $x): float { return array_sum($x) / count($x); }
function sd(array $x): float
{
    $n = count($x);
    if ($n < 2) { return 0.0; }
    $m = mean($x);
    return sqrt(array_sum(array_map(fn($v) => ($v - $m) ** 2, $x)) / ($n - 1));
}
function lgam(float $x): float // ln Gamma(x), Lanczos approximation
{
    static $c = [76.18009172947146, -86.50532032941677, 24.01409824083091, -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
    $y = $x; $tmp = $x + 5.5; $tmp -= ($x + 0.5) * log($tmp); $ser = 1.000000000190015;
    foreach ($c as $cj) { $ser += $cj / ++$y; }
    return -$tmp + log(2.5066282746310005 * $ser / $x);
}
function betacf(float $a, float $b, float $x): float
{
    $qab = $a + $b; $qap = $a + 1; $qam = $a - 1; $c = 1.0; $d = 1 - $qab * $x / $qap;
    if (abs($d) < 1e-30) { $d = 1e-30; }
    $d = 1 / $d; $h = $d;
    for ($m = 1; $m <= 300; $m++) {
        $m2 = 2 * $m; $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
        $d = 1 + $aa * $d; if (abs($d) < 1e-30) { $d = 1e-30; } $c = 1 + $aa / $c; if (abs($c) < 1e-30) { $c = 1e-30; } $d = 1 / $d; $h *= $d * $c;
        $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
        $d = 1 + $aa * $d; if (abs($d) < 1e-30) { $d = 1e-30; } $c = 1 + $aa / $c; if (abs($c) < 1e-30) { $c = 1e-30; } $d = 1 / $d; $del = $d * $c; $h *= $del;
        if (abs($del - 1) < 3e-12) { break; }
    }
    return $h;
}
function betaInc(float $a, float $b, float $x): float // regularized incomplete beta I_x(a,b)
{
    if ($x <= 0) { return 0.0; }
    if ($x >= 1) { return 1.0; }
    $bt = exp(lgam($a + $b) - lgam($a) - lgam($b) + $a * log($x) + $b * log(1 - $x));
    return $x < ($a + 1) / ($a + $b + 2) ? $bt * betacf($a, $b, $x) / $a : 1 - $bt * betacf($b, $a, 1 - $x) / $b;
}
/** Two-sided p-value of a Welch t-test between two samples. */
function welch(array $a, array $b): array
{
    $na = count($a); $nb = count($b); $va = sd($a) ** 2; $vb = sd($b) ** 2;
    $se2 = $va / $na + $vb / $nb;
    if ($se2 <= 0) { return ['t' => 0.0, 'df' => $na + $nb - 2, 'p' => 1.0]; }
    $t = (mean($a) - mean($b)) / sqrt($se2);
    $df = $se2 ** 2 / (($va / $na) ** 2 / ($na - 1) + ($vb / $nb) ** 2 / ($nb - 1));
    return ['t' => $t, 'df' => $df, 'p' => betaInc($df / 2, 0.5, $df / ($df + $t * $t))];
}
function gammaQ(float $a, float $x): float // regularized upper incomplete gamma Q(a, x)
{
    if ($x <= 0) { return 1.0; }
    if ($x < $a + 1) {
        $ap = $a; $sum = 1 / $a; $del = $sum;
        for ($n = 0; $n < 500; $n++) { $ap++; $del *= $x / $ap; $sum += $del; if (abs($del) < abs($sum) * 1e-13) { break; } }
        return 1 - $sum * exp(-$x + $a * log($x) - lgam($a));
    }
    $b = $x + 1 - $a; $c = 1e30; $d = 1 / $b; $h = $d;
    for ($i = 1; $i < 500; $i++) {
        $an = -$i * ($i - $a); $b += 2; $d = $an * $d + $b; if (abs($d) < 1e-30) { $d = 1e-30; } $c = $b + $an / $c; if (abs($c) < 1e-30) { $c = 1e-30; } $d = 1 / $d; $del = $d * $c; $h *= $del;
        if (abs($del - 1) < 1e-13) { break; }
    }
    return exp(-$x + $a * log($x) - lgam($a)) * $h;
}
function fmt(float $x, int $d = 2): string { return number_format($x, $d, '.', ''); }
function pfmt(float $p): string { return $p < 0.0001 ? '< 0.0001' : fmt($p, 4); }
function popcount8(int $v): int { $c = 0; while ($v) { $c += $v & 1; $v >>= 1; } return $c; }
function bitsDiff(string $a, string $b): int { $n = 0; for ($i = 0, $l = strlen($a); $i < $l; $i++) { $n += popcount8(ord($a[$i]) ^ ord($b[$i])); } return $n; }

/** CBC with zero IV over whole blocks, the way lib/Crypto.php chains them (no padding: the data is block-aligned). */
function cbcEnc(ModifiedAES256 $c, string $data, string $iv): string
{
    $out = ''; $prev = $iv;
    for ($i = 0, $l = strlen($data); $i < $l; $i += 16) { $prev = $c->encryptBlock(substr($data, $i, 16) ^ $prev); $out .= $prev; }
    return $out;
}
function cbcDec(ModifiedAES256 $c, string $data, string $iv): string
{
    $out = ''; $prev = $iv;
    for ($i = 0, $l = strlen($data); $i < $l; $i += 16) { $blk = substr($data, $i, 16); $out .= $c->decryptBlock($blk) ^ $prev; $prev = $blk; }
    return $out;
}

$report = [];
$say = function (string $line = '') use (&$report) { echo $line, "\n"; $report[] = $line; };

// ------------------------------------------------------------------ header

$say('# Modified AES-256 vs standard AES-256: benchmark report');
$say();
$say('- Run: ' . date('Y-m-d H:i') . ($quick ? ' (quick run)' : ' (full run)') . ', seed ' . $seed);
$say('- Device: ' . php_uname('s') . ' ' . php_uname('r') . ', ' . php_uname('m') . '; PHP ' . PHP_VERSION . (function_exists('opcache_get_status') && ini_get('opcache.jit') ? ' (JIT setting: ' . ini_get('opcache.jit') . ')' : ''));
$cpu = getenv('PROCESSOR_IDENTIFIER') ?: (is_readable('/proc/cpuinfo') ? trim((string) preg_replace('/^.*model name\s*:\s*([^\n]+).*$/s', '$1', (string) file_get_contents('/proc/cpuinfo'))) : 'unknown');
$say('- CPU: ' . $cpu . '; memory limit ' . ini_get('memory_limit'));
$say('- "Standard" = the same pure-PHP AES-256 with the ordinary fixed S-box. "Modified" = key-dependent S-box (what the app uses). "OpenSSL" = PHP\'s native AES-256, a reference only.');
$say();

// ------------------------------------------------------------------ 1. correctness

$say('## 1. Correctness');
$key = rbytes(32); $iv = str_repeat("\0", 16); $data = rbytes(16 * 64);
$std = new ModifiedAES256($key, true); $mod = new ModifiedAES256($key);
$okStd = cbcDec($std, cbcEnc($std, $data, $iv), $iv) === $data;
$okMod = cbcDec($mod, cbcEnc($mod, $data, $iv), $iv) === $data;
// NIST SP 800-38A F.2.5, first block (standard S-box must reproduce it)
$nk = hex2bin('603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4');
$nistOk = (new ModifiedAES256($nk, true))->encryptBlock(hex2bin('6bc1bee22e409f96e93d7e117393172a') ^ hex2bin('000102030405060708090a0b0c0d0e0f')) === hex2bin('f58c4c04d6e5f1ba779eabfb5f7bfbd6');
$opensslOk = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv) === cbcEnc($std, $data, $iv);
$say('| Check | Result |');
$say('|---|---|');
$say('| Standard decrypts what it encrypts (1 KiB, CBC) | ' . ($okStd ? 'pass' : 'FAIL') . ' |');
$say('| Modified decrypts what it encrypts (1 KiB, CBC) | ' . ($okMod ? 'pass' : 'FAIL') . ' |');
$say('| Standard matches the NIST SP 800-38A AES-256 test vector | ' . ($nistOk ? 'pass' : 'FAIL') . ' |');
$say('| Standard matches OpenSSL AES-256-CBC on random data | ' . ($opensslOk ? 'pass' : 'FAIL') . ' |');
$say('| Modified output differs from standard (it is a different cipher) | ' . (cbcEnc($mod, $data, $iv) !== cbcEnc($std, $data, $iv) ? 'yes' : 'NO') . ' |');
$say();
if (!($okStd && $okMod && $nistOk && $opensslOk)) { $say('**A correctness check failed: the numbers below are not meaningful until it is fixed.**'); $say(); }

// ------------------------------------------------------------------ 2. speed

$say('## 2. Speed');
$bytes = $P['kib'] * 1024; $blocks = $bytes / 16;
$buf = rbytes($bytes);
$tEnc = ['std' => [], 'mod' => [], 'ssl' => []]; $tDec = ['std' => [], 'mod' => [], 'ssl' => []];
$key = rbytes(32); $std = new ModifiedAES256($key, true); $mod = new ModifiedAES256($key);
$sslCipher = openssl_encrypt($buf, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
cbcEnc($std, substr($buf, 0, 1024), $iv); cbcEnc($mod, substr($buf, 0, 1024), $iv); // warm-up
for ($r = 0; $r < $P['runs']; $r++) {
    // alternate the order so slow drift (heat, other programs) hits both the same way
    $order = $r % 2 === 0 ? ['std', 'mod'] : ['mod', 'std'];
    foreach ($order as $which) {
        $c = $which === 'std' ? $std : $mod;
        $t = hrtime(true); $ct = cbcEnc($c, $buf, $iv); $tEnc[$which][] = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true); cbcDec($c, $ct, $iv); $tDec[$which][] = (hrtime(true) - $t) / 1e6;
    }
    $t = hrtime(true); openssl_encrypt($buf, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv); $tEnc['ssl'][] = (hrtime(true) - $t) / 1e6;
    $t = hrtime(true); openssl_decrypt($sslCipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv); $tDec['ssl'][] = (hrtime(true) - $t) / 1e6;
}
$say("Each run encrypts then decrypts {$P['kib']} KiB ({$blocks} blocks) in CBC mode; {$P['runs']} runs per cipher. Times are milliseconds per run.");
$say();
$say('| Cipher | Encrypt mean ± SD (ms) | Decrypt mean ± SD (ms) | Encrypt throughput (KiB/s) | Per block (µs, enc / dec) |');
$say('|---|---|---|---|---|');
foreach (['std' => 'Standard (PHP)', 'mod' => 'Modified (PHP)', 'ssl' => 'OpenSSL (native, reference)'] as $k => $label) {
    $say(sprintf('| %s | %s ± %s | %s ± %s | %s | %s / %s |', $label, fmt(mean($tEnc[$k]), 2), fmt(sd($tEnc[$k]), 2), fmt(mean($tDec[$k]), 2), fmt(sd($tDec[$k]), 2),
        fmt($P['kib'] / (mean($tEnc[$k]) / 1000), 0), fmt(mean($tEnc[$k]) * 1000 / $blocks, 2), fmt(mean($tDec[$k]) * 1000 / $blocks, 2)));
}
$say();
$wEnc = welch($tEnc['mod'], $tEnc['std']); $wDec = welch($tDec['mod'], $tDec['std']);
$diffEnc = (mean($tEnc['mod']) / mean($tEnc['std']) - 1) * 100; $diffDec = (mean($tDec['mod']) / mean($tDec['std']) - 1) * 100;
$say('Modified vs Standard, Welch two-sample t-test (two-sided, 5% level):');
$say();
$say('| | Difference in mean time | t | df | p-value | Verdict |');
$say('|---|---|---|---|---|---|');
foreach ([['Encrypt', $diffEnc, $wEnc], ['Decrypt', $diffDec, $wDec]] as [$name, $diff, $w]) {
    $say(sprintf('| %s | %s%% | %s | %s | %s | %s |', $name, ($diff >= 0 ? '+' : '') . fmt($diff, 1), fmt($w['t'], 2), fmt($w['df'], 1), pfmt($w['p']), $w['p'] < 0.05 ? 'a real difference' : 'no real difference (within noise)'));
}
$say();
// key setup
$setupKeys = $quick ? 60 : 200; $keys = []; for ($i = 0; $i < $setupKeys; $i++) { $keys[] = rbytes(32); }
$t = hrtime(true); foreach ($keys as $k) { new ModifiedAES256($k, true); } $setStd = (hrtime(true) - $t) / 1e3 / $setupKeys;
$t = hrtime(true); foreach ($keys as $k) { new ModifiedAES256($k); } $setMod = (hrtime(true) - $t) / 1e3 / $setupKeys;
$say('Key setup (done once per key; the app builds its cipher once and reuses it): standard ' . fmt($setStd, 1) . ' µs, modified ' . fmt($setMod, 1) . ' µs per key (about ' . fmt($setMod / max($setStd, 0.001), 1) . 'x, the cost of deriving the S-box).');
$say('Peak memory: ' . fmt(memory_get_peak_usage(true) / 1048576, 1) . ' MiB for the whole run.');
$say();

// ------------------------------------------------------------------ 3. randomness

$say('## 3. Randomness');
$say();
$say("### Avalanche effect ({$P['avalanche']} trials each; ideal is 50% of the 128 output bits changing)");
$say();
$avalanche = function (bool $standard, string $what) use ($P) {
    $pcts = [];
    for ($i = 0; $i < $P['avalanche']; $i++) {
        $k = rbytes(32); $p = rbytes(16);
        $c1 = new ModifiedAES256($k, $standard);
        $ct1 = $c1->encryptBlock($p);
        if ($what === 'plaintext') {
            $p2 = $p; $bit = mt_rand(0, 127); $p2[intdiv($bit, 8)] = chr(ord($p2[intdiv($bit, 8)]) ^ (1 << ($bit % 8)));
            $ct2 = $c1->encryptBlock($p2);
        } else {
            $k2 = $k; $bit = mt_rand(0, 255); $k2[intdiv($bit, 8)] = chr(ord($k2[intdiv($bit, 8)]) ^ (1 << ($bit % 8)));
            $ct2 = (new ModifiedAES256($k2, $standard))->encryptBlock($p);
        }
        $pcts[] = bitsDiff($ct1, $ct2) / 128 * 100;
    }
    return $pcts;
};
$say('| Change | Cipher | Mean % bits changed | SD | 95% interval of the mean |');
$say('|---|---|---|---|---|');
foreach (['plaintext' => 'Flip 1 plaintext bit', 'key' => 'Flip 1 key bit'] as $what => $label) {
    foreach ([true => 'Standard', false => 'Modified'] as $isStd => $name) {
        $pcts = $avalanche($isStd, $what);
        $half = 1.96 * sd($pcts) / sqrt(count($pcts));
        $say(sprintf('| %s | %s | %s | %s | %s to %s |', $label, $name, fmt(mean($pcts), 2), fmt(sd($pcts), 2), fmt(mean($pcts) - $half, 2), fmt(mean($pcts) + $half, 2)));
    }
}
$say();
$say('### Ciphertext randomness (64 KiB of a very repetitive plaintext, CBC)');
$say();
$say('A good cipher turns even all-zero or repeated-text input into noise. Shannon entropy is bits per byte (8.0 is perfectly random); the chi-square test asks whether the 256 byte values are equally common (a p-value above 0.05 means nothing unusual was found).');
$say();
$say('| Plaintext | Cipher | Entropy (bits/byte, mean over keys) | Chi-square p-value (mean) |');
$say('|---|---|---|---|');
$entropyOf = function (string $s): array {
    $n = strlen($s); $cnt = array_fill(0, 256, 0);
    for ($i = 0; $i < $n; $i++) { $cnt[ord($s[$i])]++; }
    $h = 0.0; $chi = 0.0; $exp = $n / 256;
    foreach ($cnt as $c) { if ($c > 0) { $p = $c / $n; $h -= $p * log($p, 2); } $chi += ($c - $exp) ** 2 / $exp; }
    return [$h, gammaQ(255 / 2, $chi / 2)];
};
$plains = ['All zeros' => str_repeat("\0", 65536), 'Repeated English text' => substr(str_repeat('Career guidance for Mapua Malayan Colleges Laguna students. ', 1200), 0, 65536)];
foreach ($plains as $pname => $plain) {
    [$hp] = $entropyOf($plain);
    foreach ([true => 'Standard', false => 'Modified'] as $isStd => $name) {
        $hs = []; $ps = [];
        for ($i = 0; $i < $P['entropyKeys']; $i++) { $c = new ModifiedAES256(rbytes(32), $isStd); [$h, $p] = $entropyOf(cbcEnc($c, $plain, rbytes(16))); $hs[] = $h; $ps[] = $p; }
        $say(sprintf('| %s (plaintext entropy %s) | %s | %s | %s |', $pname, fmt($hp, 3), $name, fmt(mean($hs), 4), fmt(mean($ps), 3)));
    }
}
$say();

// ------------------------------------------------------------------ 4. S-box quality

$say('## 4. S-box quality (resistance to linear and differential attacks)');
$say();
$say('These are the textbook measures for a substitution box. Nonlinearity: higher is better (the AES S-box reaches 112, the highest known for an 8-bit permutation). Differential uniformity: lower is better (the AES S-box is 4, the lowest known for an 8-bit permutation). Fixed points (S(x) = x): fewer is better (the AES S-box has none).');
$say();
$nonlinearity = function (array $S): int {
    $best = 0;
    for ($b = 1; $b < 256; $b++) {
        $w = []; for ($x = 0; $x < 256; $x++) { $w[$x] = (popcount8($b & $S[$x]) & 1) ? -1 : 1; }
        for ($len = 1; $len < 256; $len <<= 1) {
            for ($i = 0; $i < 256; $i += $len << 1) { for ($j = $i; $j < $i + $len; $j++) { $u = $w[$j]; $v = $w[$j + $len]; $w[$j] = $u + $v; $w[$j + $len] = $u - $v; } }
        }
        foreach ($w as $v) { $best = max($best, abs($v)); }
    }
    return 128 - intdiv($best, 2);
};
$diffUniformity = function (array $S): int {
    $max = 0;
    for ($a = 1; $a < 256; $a++) { $cnt = array_fill(0, 256, 0); for ($x = 0; $x < 256; $x++) { $cnt[$S[$x] ^ $S[$x ^ $a]]++; } $max = max($max, max($cnt)); }
    return $max;
};
$fixedPoints = function (array $S): int { $n = 0; for ($x = 0; $x < 256; $x++) { if ($S[$x] === $x) { $n++; } } return $n; };
$aes = ModifiedAES256::standardSbox();
$nlA = $nonlinearity($aes); $duA = $diffUniformity($aes); $fpA = $fixedPoints($aes);
$nl = $du = $fp = [];
for ($i = 0; $i < $P['sboxKeys']; $i++) { $S = ModifiedAES256::deriveKeyDependentSBox(rbytes(32)); $nl[] = $nonlinearity($S); $du[] = $diffUniformity($S); $fp[] = $fixedPoints($S); }
$say("| S-box | Nonlinearity | Differential uniformity | Fixed points |");
$say('|---|---|---|---|');
$say("| Standard AES (fixed) | {$nlA} | {$duA} | {$fpA} |");
$say(sprintf('| Key-dependent, %d random keys: mean (range) | %s (%d to %d) | %s (%d to %d) | %s (%d to %d) |', $P['sboxKeys'],
    fmt(mean($nl), 1), min($nl), max($nl), fmt(mean($du), 1), min($du), max($du), fmt(mean($fp), 2), min($fp), max($fp)));
$say();
$weaker = mean($nl) < $nlA && mean($du) > $duA;
$say($weaker
    ? 'Reading: the key-dependent S-boxes are random permutations, and a random permutation is on average worse than the AES S-box on both measures (lower nonlinearity, higher differential uniformity). The AES S-box was designed for these properties; the key-dependent one gets its strength from being secret, not from these numbers. So this modification should not be described as making the cipher stronger against linear or differential attacks.'
    : 'Reading: on this sample the key-dependent S-boxes were not worse than the AES S-box on these measures.');
$say();

// ------------------------------------------------------------------ 5. brute force

$say('## 5. Brute-force (exhaustive key search)');
$say();
$bits = $P['bruteBits']; $space = 1 << $bits;
$say("A real search on a key space cut down to {$bits} unknown bits ({$space} possible keys; the other " . (256 - $bits) . " bits are known). The attacker knows one plaintext block and its ciphertext and tries every key. For the Modified cipher each guess must also derive that key's S-box, which is part of the cost.");
$say();
$attack = function (bool $standard) use ($P, $bits, $space) {
    $rates = []; $totalGuesses = 0; $totalNs = 0;
    for ($t = 0; $t < $P['bruteTargets']; $t++) {
        $base = rbytes(32); $secret = mt_rand(0, $space - 1);
        $mk = function (int $n) use ($base) { $k = $base; $k[30] = chr($n & 0xFF); $k[31] = chr(($n >> 8) & 0xFF); return $k; };
        $pt = rbytes(16); $ct = (new ModifiedAES256($mk($secret), $standard))->encryptBlock($pt);
        $start = hrtime(true); $guesses = 0; $found = false;
        for ($n = 0; $n < $space; $n++) {
            $guesses++;
            if ((new ModifiedAES256($mk($n), $standard))->encryptBlock($pt) === $ct) { $found = ($n === $secret); break; }
        }
        $ns = hrtime(true) - $start; $totalGuesses += $guesses; $totalNs += $ns;
        if (!$found) { return null; }
    }
    return ['perSec' => $totalGuesses / ($totalNs / 1e9), 'usPerGuess' => $totalNs / 1e3 / $totalGuesses];
};
$bfStd = $attack(true); $bfMod = $attack(false);
$say('| Cipher | Key found in every search | Guesses per second | Time per guess (µs) | Extrapolated time to try half of all 2^256 keys, on this one core |');
$say('|---|---|---|---|---|');
foreach ([['Standard (PHP)', $bfStd], ['Modified (PHP)', $bfMod]] as [$name, $bf]) {
    if ($bf === null) { $say("| {$name} | NO | n/a | n/a | n/a |"); continue; }
    $log10Years = 255 * log10(2) + log10($bf['usPerGuess'] / 1e6) - log10(365.25 * 86400);
    $say(sprintf('| %s | yes (%d of %d) | %s | %s | about 10^%s years |', $name, $P['bruteTargets'], $P['bruteTargets'], fmt($bf['perSec'], 0), fmt($bf['usPerGuess'], 1), fmt($log10Years, 0)));
}
if ($bfStd && $bfMod) {
    $say();
    $say('Each Modified guess costs about ' . fmt($bfMod['usPerGuess'] / $bfStd['usPerGuess'], 1) . 'x a Standard guess here. That is a small constant factor: against a 256-bit key both searches are astronomically out of reach (the extrapolation is only an order-of-magnitude illustration, from pure-PHP speed on one core). Brute force is not how either cipher would realistically be attacked, and a constant factor is a minor difference next to the 2^256 key space.');
}
$say();

// ------------------------------------------------------------------ summary

$say('## Summary for the write-up');
$say();
$say('- Both ciphers are correct (round trip), and the standard version matches the NIST test vector and OpenSSL.');
$say('- Speed: Modified encryption was ' . ($wEnc['p'] < 0.05 ? 'measurably different from' : 'not measurably different from') . ' Standard (' . ($diffEnc >= 0 ? '+' : '') . fmt($diffEnc, 1) . '%, p ' . (pfmt($wEnc['p']) === '< 0.0001' ? '< 0.0001' : '= ' . pfmt($wEnc['p'])) . '); decryption ' . ($wDec['p'] < 0.05 ? 'was measurably different' : 'was not measurably different') . ' (' . ($diffDec >= 0 ? '+' : '') . fmt($diffDec, 1) . '%, p ' . (pfmt($wDec['p']) === '< 0.0001' ? '< 0.0001' : '= ' . pfmt($wDec['p'])) . '). The one extra cost is key setup, paid once.');
$say('- Randomness: avalanche and ciphertext entropy are close to ideal for both; compare the tables above rather than expecting a difference.');
$say('- S-box quality: see section 4. The measured numbers do not show the modification improving resistance to linear or differential attacks.');
$say('- Brute force: only a constant factor per guess; the 256-bit key space is what protects both.');
$say('- Not shown by any of this: that either cipher is secure against cryptanalysis. AES-256 has had decades of public scrutiny; the key-dependent S-box has had none, so "Modified is better" is not supported by these results.');

if ($outFile !== null) {
    file_put_contents($outFile, implode("\n", $report) . "\n");
    echo "\n(report written to $outFile)\n";
}
