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
check('"Ilao, Adomar L." -> alilao (the example from the meeting)', StaffEmail::expectedUsername('Ilao, Adomar L.') === 'alilao');
check('no middle initial -> first initial + last name', StaffEmail::expectedUsername('Cruz, Juan') === 'jcruz');
check('a middle initial without a dot works', StaffEmail::expectedUsername('Cruz, Juan D') === 'jdcruz');
check('spaces and accents in the surname are ignored (Dela Peña -> delapena)', StaffEmail::expectedUsername('Dela Peña, Maria C.') === 'mcdelapena');
check('two given names: the first one gives the initial, the last single letter is the middle initial', StaffEmail::expectedUsername('Reyes, Juan Carlos D.') === 'jdreyes');
check('a hyphenated surname keeps just its letters', StaffEmail::expectedUsername("De la Cruz-Santos, Ana B.") === 'abdelacruzsantos');
check('a name with no comma gives nothing', StaffEmail::expectedUsername('Juan Cruz') === null);

echo "\n=== the email check ===\n";
$ok = StaffEmail::check('alilao@mcl.edu.ph', 'Ilao, Adomar L.');
check('a matching school email is accepted and its name part is the username', $ok['ok'] && $ok['username'] === 'alilao');
check('capital letters in the email are fine', StaffEmail::check('ALilao@MCL.edu.ph', 'Ilao, Adomar L.')['username'] === 'alilao');
$gmail = StaffEmail::check('alilao@gmail.com', 'Ilao, Adomar L.');
check('a personal email is rejected', !$gmail['ok'] && strpos($gmail['error'], '@mcl.edu.ph') !== false);
$other = StaffEmail::check('someone@mcl.edu.ph', 'Ilao, Adomar L.');
check('another person\'s school email is rejected, and the right one is suggested', !$other['ok'] && strpos($other['error'], 'alilao@mcl.edu.ph') !== false);
check('a look-alike domain is rejected', !StaffEmail::check('alilao@mcl.edu.ph.evil.com', 'Ilao, Adomar L.')['ok'] && !StaffEmail::check('alilao@live.mcl.edu.ph', 'Ilao, Adomar L.')['ok']);
check('the student domain is not accepted for staff', !StaffEmail::check('alilao@live.mcl.edu.ph', 'Ilao, Adomar L.')['ok']);
check('an address with no @ is rejected', !StaffEmail::check('alilao', 'Ilao, Adomar L.')['ok']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
