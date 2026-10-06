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

echo "=== the username a name calls for ===\n";
check('"Cruz, Juan D." -> jdcruz (the pattern: initials + last name)', StaffEmail::expectedUsername('Cruz, Juan D.') === 'jdcruz');
check('no middle initial -> first initial + last name', StaffEmail::expectedUsername('Cruz, Juan') === 'jcruz');
check('a middle initial without a dot works', StaffEmail::expectedUsername('Cruz, Juan D') === 'jdcruz');
check('spaces and accents in the surname are ignored (Dela Peña -> delapena)', StaffEmail::expectedUsername('Dela Peña, Maria C.') === 'mcdelapena');
check('two given names: the first one gives the initial, the last single letter is the middle initial', StaffEmail::expectedUsername('Reyes, Juan Carlos D.') === 'jdreyes');
check('a hyphenated surname keeps just its letters', StaffEmail::expectedUsername("De la Cruz-Santos, Ana B.") === 'abdelacruzsantos');
check('a name with no comma gives nothing', StaffEmail::expectedUsername('Juan Cruz') === null);

echo "\n=== the email check ===\n";
$ok = StaffEmail::check('jdcruz@mcl.edu.ph', 'Cruz, Juan D.');
check('a matching school email is accepted and its name part is the username', $ok['ok'] && $ok['username'] === 'jdcruz');
check('capital letters in the email are fine', StaffEmail::check('JDCruz@MCL.edu.ph', 'Cruz, Juan D.')['username'] === 'jdcruz');
$gmail = StaffEmail::check('jdcruz@gmail.com', 'Cruz, Juan D.');
check('a personal email is rejected', !$gmail['ok'] && strpos($gmail['error'], '@mcl.edu.ph') !== false);
$other = StaffEmail::check('someone@mcl.edu.ph', 'Cruz, Juan D.');
check('another person\'s school email is rejected, and the right one is suggested', !$other['ok'] && strpos($other['error'], 'jdcruz@mcl.edu.ph') !== false);
check('a look-alike domain is rejected', !StaffEmail::check('jdcruz@mcl.edu.ph.evil.com', 'Cruz, Juan D.')['ok'] && !StaffEmail::check('jdcruz@live.mcl.edu.ph', 'Cruz, Juan D.')['ok']);
check('the student domain is not accepted for staff', !StaffEmail::check('jdcruz@live.mcl.edu.ph', 'Cruz, Juan D.')['ok']);
check('an address with no @ is rejected', !StaffEmail::check('jdcruz', 'Cruz, Juan D.')['ok']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
