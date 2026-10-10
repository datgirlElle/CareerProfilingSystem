<?php

require_once __DIR__ . '/../lib/PasswordReset.php';

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

echo "=== the emailed code ===\n";
$allSixDigits = true;
$seen = [];
for ($i = 0; $i < 300; $i++) {
    $c = PasswordReset::generateCode();
    $seen[$c] = true;
    if (!preg_match('/^[0-9]{6}$/', $c)) { $allSixDigits = false; }
}
check('a code is always exactly 6 digits (leading zeros kept)', $allSixDigits);
check('300 codes are almost all different (they are random)', count($seen) > 290);
check('isCode accepts 6 digits only', PasswordReset::isCode('004821') && !PasswordReset::isCode('12345') && !PasswordReset::isCode('1234567') && !PasswordReset::isCode('12 456') && !PasswordReset::isCode('12345a') && !PasswordReset::isCode(''));

echo "\n=== how it is stored ===\n";
check('the hash is not the code', strpos(PasswordReset::hashCode(5, '123456'), '123456') === false);
check('the same code for the same account always hashes the same', PasswordReset::hashCode(5, '123456') === PasswordReset::hashCode(5, '123456'));
check('a code for one account does not match another account', PasswordReset::hashCode(5, '123456') !== PasswordReset::hashCode(6, '123456'));
check('different codes hash differently', PasswordReset::hashCode(5, '123456') !== PasswordReset::hashCode(5, '123457'));
$t1 = PasswordReset::newToken(); $t2 = PasswordReset::newToken();
check('a reset token is 64 hex characters and never repeats', (bool) preg_match('/^[0-9a-f]{64}$/', $t1) && $t1 !== $t2);
check('a token and a code never hash the same way', PasswordReset::hashToken('123456') !== PasswordReset::hashCode(0, '123456'));

echo "\n=== masking the address that was typed ===\n";
check('first letter, dots, then the domain', PasswordReset::maskEmail('christeen@gmail.com') === 'c' . str_repeat('•', 6) . '@gmail.com');
check('a short name still gets at least 3 dots', PasswordReset::maskEmail('a@mcl.edu.ph') === 'a•••@mcl.edu.ph' && PasswordReset::maskEmail('jd@mcl.edu.ph') === 'j•••@mcl.edu.ph');
check('a long name is not given away by its length: at most 6 dots', substr_count(PasswordReset::maskEmail('averyveryverylongname@mcl.edu.ph'), '•') === 6);
check('the whole address is never shown', strpos(PasswordReset::maskEmail('juan.dela.cruz@live.mcl.edu.ph'), 'juan') === false);
check('something that is not an address shows nothing useful', PasswordReset::maskEmail('nope') === '••••••');

echo "\n=== the rules the flow uses ===\n";
check('a code lasts 10 minutes', PasswordReset::CODE_MINUTES === 10);
check('another code can be asked for after 60 seconds, 3 times an hour', PasswordReset::RESEND_SECONDS === 60 && PasswordReset::MAX_SENDS_PER_HOUR === 3);
check('5 wrong tries cancel a code', PasswordReset::MAX_WRONG_TRIES === 5);
check('there are exactly two portals', PasswordReset::PORTALS === ['student', 'staff']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
