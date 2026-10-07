<?php

require_once __DIR__ . '/../lib/StaffEmail.php';
require_once __DIR__ . '/../lib/StaffName.php';

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

echo "=== the school email check ===\n";
check('a school email is accepted', StaffEmail::check('jdcruz@mcl.edu.ph')['ok']);
check('capital letters are fine', StaffEmail::check('JDCruz@MCL.edu.ph')['ok']);
check('any name part works now (the username is the person\'s own choice)', StaffEmail::check('guidance.office@mcl.edu.ph')['ok']);
$gmail = StaffEmail::check('jdcruz@gmail.com');
check('a personal email is rejected', !$gmail['ok'] && strpos($gmail['error'], '@mcl.edu.ph') !== false);
check('a look-alike domain is rejected', !StaffEmail::check('jdcruz@mcl.edu.ph.evil.com')['ok'] && !StaffEmail::check('jdcruz@live.mcl.edu.ph')['ok']);
check('an address with no @ is rejected', !StaffEmail::check('jdcruz')['ok']);
check('an empty name part is rejected', !StaffEmail::check('@mcl.edu.ph')['ok']);

echo "\n=== the name from separate inputs ===\n";
check('last, first and middle initial combine as "Last, First M."', StaffName::compose('Cruz', 'Juan', 'D') === 'Cruz, Juan D.');
check('a typed dot and a lower-case letter are tidied', StaffName::compose('Cruz', 'Juan', 'd.') === 'Cruz, Juan D.');
check('the middle initial is optional', StaffName::compose('Cruz', 'Juan', '') === 'Cruz, Juan');
check('two-word and hyphenated names are fine', StaffName::compose('Dela Peña', 'Maria Cristina', 'B') === 'Dela Peña, Maria Cristina B.' && StaffName::compose("De la Cruz-Santos", 'Ana', '') === 'De la Cruz-Santos, Ana');
check('extra spaces are collapsed', StaffName::compose('  Cruz ', ' Juan   Carlos ', '') === 'Cruz, Juan Carlos');
check('a middle name instead of an initial is rejected', StaffName::compose('Cruz', 'Juan', 'Dela') === null);
check('digits, symbols and commas in a name are rejected', StaffName::compose('Cruz1', 'Juan', '') === null && StaffName::compose('Cruz', 'Ju@n', '') === null && StaffName::compose('Cruz, Jr', 'Juan', '') === null);
check('an empty last or first name is rejected', StaffName::compose('', 'Juan', '') === null && StaffName::compose('Cruz', '', '') === null);
check('what compose makes passes the stored-name check', StaffName::isValid((string) StaffName::compose('Cruz', 'Juan', 'D')));

echo "\n=== the username ===\n";
check('letters and numbers are fine', StaffEmail::isValidUsername('jdcruz') && StaffEmail::isValidUsername('Guidance2026'));
check('3 and 20 characters are the limits', StaffEmail::isValidUsername('abc') && StaffEmail::isValidUsername(str_repeat('a', 20)) && !StaffEmail::isValidUsername('ab') && !StaffEmail::isValidUsername(str_repeat('a', 21)));
check('digits only is rejected (it could look like a Student Number)', !StaffEmail::isValidUsername('123456789012'));
check('spaces and symbols are rejected', !StaffEmail::isValidUsername('juan cruz') && !StaffEmail::isValidUsername('juan_cruz') && !StaffEmail::isValidUsername('juan.cruz') && !StaffEmail::isValidUsername('juan@cruz'));
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
