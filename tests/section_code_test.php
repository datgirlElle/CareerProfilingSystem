<?php

require_once __DIR__ . '/../lib/Sections.php';
require_once __DIR__ . '/../lib/StudentNumber.php';

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

echo "=== section code format ===\n";
check('the real sections all pass', Sections::formatError('STEM', 'S1114') === null && Sections::formatError('STEM', 'S1109') === null && Sections::formatError('ABM', 'A1101') === null && Sections::formatError('HUMSS', 'H1102') === null && Sections::formatError('ICT', 'I1102') === null);
check('Grade 12 sections pass too', Sections::formatError('STEM', 'S1201') === null);
check('the letter must match the strand', Sections::formatError('STEM', 'A1114') !== null && Sections::formatError('ICT', 'H1101') !== null);
check('the wrong-strand message names the letter to use', strpos((string) Sections::formatError('ABM', 'S1101'), 'start with A') !== false);
check('too few, too many or no digits are rejected', Sections::formatError('STEM', 'S114') !== null && Sections::formatError('STEM', 'S11145') !== null && Sections::formatError('STEM', 'S') !== null);
check('grade must be 11 or 12', Sections::formatError('STEM', 'S1014') !== null && Sections::formatError('STEM', 'S1314') !== null);
check('lower case and spaces are tidied before checking', Sections::normalizeCode(' s 1114 ') === 'S1114' && Sections::formatError('STEM', Sections::normalizeCode('s1114')) === null);
check('an unknown strand is invalid', Sections::formatError('PE', 'P1101') === 'Invalid strand.');

echo "\n=== grade level from the section ===\n";
check('S1114 is Grade 11 and H1202 is Grade 12', Sections::gradeLevel('S1114') === '11' && Sections::gradeLevel('H1202') === '12');
check('a malformed code has no grade level', Sections::gradeLevel('ZZXLSX') === null && Sections::gradeLevel('S1314') === null);

echo "\n=== Student Number ===\n";
check('10 to 12 digits are valid', StudentNumber::isValid('1234567890') && StudentNumber::isValid('123456789012'));
check('anything else is not', !StudentNumber::isValid('123456789') && !StudentNumber::isValid('1234567890123') && !StudentNumber::isValid('12345 67890') && !StudentNumber::isValid('12345abcde'));
check('the message says Student Number, not LRN', strpos(StudentNumber::INVALID_MESSAGE, 'Student Number') === 0 && strpos(StudentNumber::INVALID_MESSAGE, 'LRN') === false);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
