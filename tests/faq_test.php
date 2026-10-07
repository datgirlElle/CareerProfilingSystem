<?php

require_once __DIR__ . '/../lib/Faq.php';

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

echo "=== tidying what is typed ===\n";
check('a question is one line: spaces and line breaks collapse', Faq::clean("  How do   I\n reset  it?  ", false) === 'How do I reset it?');
check('an answer keeps its line breaks', Faq::clean("Step one.\nStep two.", true) === "Step one.\nStep two.");
check('three or more blank lines shrink to one blank line', Faq::clean("A\n\n\n\nB", true) === "A\n\nB");
check('Windows line breaks become plain ones', Faq::clean("A\r\nB", true) === "A\nB");
check('hidden control characters are dropped', Faq::clean("Hi\x00 th\x07ere", false) === 'Hi there');
check('accents and Ñ survive', Faq::clean('¿Cómo cambio mi contraseña, Peña?', false) === '¿Cómo cambio mi contraseña, Peña?');
check('HTML is left as typed text (the pages escape it)', Faq::clean('<b>x</b> & "y"', false) === '<b>x</b> & "y"');

echo "\n=== what is accepted ===\n";
check('a question with an answer is fine', Faq::validate('How do I log in?', 'Use your Student Number.') === null);
check('an empty question or answer is refused', Faq::validate('', 'a') !== null && Faq::validate('q', '') !== null);
check('300 characters of question is the limit', Faq::validate(str_repeat('q', 300), 'a') === null && Faq::validate(str_repeat('q', 301), 'a') !== null);
check('2000 characters of answer is the limit', Faq::validate('q', str_repeat('a', 2000)) === null && Faq::validate('q', str_repeat('a', 2001)) !== null);
check('the limits count characters, not bytes (Ñ is one)', Faq::validate(str_repeat('Ñ', 300), 'a') === null);
check('the two audiences are student and staff', Faq::AUDIENCES === ['student', 'staff']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
