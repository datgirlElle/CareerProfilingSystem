<?php
/**
 * Result email wording (adviser/researcher decision):
 *   match    -> the Best Match and the Alternative Courses
 *   mismatch -> only refers the student to the Guidance Office; no course is named
 *
 *   php tests/result_email_test.php
 */
require_once __DIR__ . '/../lib/ResultEmail.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}

$best = ['Bachelor of Science in Information Technology'];
$alts = ['Bachelor of Science in Computer Science', 'Bachelor of Science in Medical Technology'];

$m = ResultEmail::compose('Ana', 'match', $best, $alts);
check('match email names the Best RIASEC Match (HTML and text)', str_contains($m['bodyHtml'], 'Best RIASEC Match') && str_contains($m['bodyText'], 'most similar to your RIASEC profile): ' . $best[0]));
check('match email lists every alternative course', str_contains($m['bodyText'], "- {$alts[0]}") && str_contains($m['bodyText'], "- {$alts[1]}") && str_contains($m['bodyHtml'], $alts[1]));
check('match email without alternatives has no alternatives heading', !str_contains(ResultEmail::compose('Ana', 'match', $best)['bodyText'], 'Alternative'));
check('match email links to the results page', $m['path'] === '/results');
check('match email shows no percentage', !str_contains($m['bodyHtml'] . $m['bodyText'], '%'));

$x = ResultEmail::compose('Ana', 'mismatch', $best, $alts);
check('mismatch email names no course', !str_contains($x['bodyHtml'] . $x['bodyText'], 'Bachelor'));
check('mismatch email refers to the Guidance Office', str_contains($x['heading'], 'Guidance Office') && str_contains($x['bodyText'], 'Guidance Counselor'));
check('mismatch email links to Guidance Office hours, not results', $x['path'] === '/help-center');

$e = ResultEmail::compose('Ana', 'match', []);
check('match with no Best Match falls back to the Guidance Office email', $e['path'] === '/help-center');

$h = ResultEmail::compose('<b>Ana</b>', 'match', ['A & B'], ['C < D']);
check('names and titles are HTML-escaped', str_contains($h['bodyHtml'], '&lt;b&gt;Ana') && str_contains($h['bodyHtml'], 'A &amp; B') && str_contains($h['bodyHtml'], 'C &lt; D'));

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
