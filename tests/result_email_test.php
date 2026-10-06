<?php
/**
 * Result email wording (adviser/researcher decision):
 *   match    -> lists the matched degree programs
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

$courses = ['Bachelor of Science in Information Technology', 'Bachelor of Science in Computer Science'];

$m = ResultEmail::compose('Ana', 'match', $courses);
check('match email lists every matched course (HTML)', str_contains($m['bodyHtml'], $courses[0]) && str_contains($m['bodyHtml'], $courses[1]));
check('match email lists every matched course (text)', str_contains($m['bodyText'], "- {$courses[0]}") && str_contains($m['bodyText'], "- {$courses[1]}"));
check('match email links to the results page', $m['path'] === '/results');
check('match email shows no percentage', !str_contains($m['bodyHtml'] . $m['bodyText'], '%'));

$x = ResultEmail::compose('Ana', 'mismatch', $courses);
check('mismatch email names no course', !str_contains($x['bodyHtml'] . $x['bodyText'], 'Bachelor'));
check('mismatch email refers to the Guidance Office', str_contains($x['heading'], 'Guidance Office') && str_contains($x['bodyText'], 'Guidance Counselor'));
check('mismatch email links to Guidance Office hours, not results', $x['path'] === '/help-center');

$e = ResultEmail::compose('Ana', 'match', []);
check('match with no course falls back to the Guidance Office email', $e['path'] === '/help-center');

$h = ResultEmail::compose('<b>Ana</b>', 'match', ['A & B']);
check('names and titles are HTML-escaped', str_contains($h['bodyHtml'], '&lt;b&gt;Ana') && str_contains($h['bodyHtml'], 'A &amp; B'));

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
