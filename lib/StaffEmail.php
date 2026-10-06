<?php

/**
 * Staff sign in with a username that comes from their school email, so nobody
 * can borrow an address that isn't theirs: the email must be on the school
 * domain and its part before the @ must match the person's name — first
 * initial, middle initial, last name. "Ilao, Adomar L." must use
 * alilao@mcl.edu.ph, and that "alilao" is their username.
 */
class StaffEmail
{
    public const DOMAIN = 'mcl.edu.ph';

    /** Letters only, lower case, accents folded: "Dela Peña" -> "delapena". */
    private static function letters(string $s): string
    {
        // A fixed table, not iconv's TRANSLIT: what that produces depends on the server's locale.
        $folded = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ç' => 'C', 'Ñ' => 'N',
        ]);
        return strtolower(preg_replace('/[^A-Za-z]/', '', $folded));
    }

    /**
     * What the email's name part must be for a name written "Last, First M.I."
     * (first initial + middle initial + last name; first initial + last name
     * when there is no middle initial). Null when the name can't be read.
     */
    public static function expectedUsername(string $fullName): ?string
    {
        $parts = explode(',', $fullName, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $last = self::letters($parts[0]);
        $given = preg_split('/\s+/', trim($parts[1]), -1, PREG_SPLIT_NO_EMPTY);
        if ($last === '' || !$given) {
            return null;
        }
        $middleInitial = '';
        // A trailing single letter, with or without a dot, is the middle initial.
        if (count($given) > 1 && preg_match('/^\p{L}\.?$/u', end($given))) {
            $middleInitial = self::letters(array_pop($given));
        }
        $firstInitial = substr(self::letters($given[0]), 0, 1);
        if ($firstInitial === '') {
            return null;
        }
        return $firstInitial . $middleInitial . $last;
    }

    /**
     * @return array{ok:bool,error:?string,username:?string}
     */
    public static function check(string $email, string $fullName): array
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || strtolower(substr($email, $at + 1)) !== self::DOMAIN) {
            return ['ok' => false, 'error' => 'Use your school email address ending in @' . self::DOMAIN . '. Personal emails such as Gmail are not accepted.', 'username' => null];
        }
        $local = strtolower(substr($email, 0, $at));
        $expected = self::expectedUsername($fullName);
        if ($expected === null || $local !== $expected) {
            $hint = $expected !== null ? " For \"$fullName\" it should be $expected@" . self::DOMAIN . '.' : '';
            return ['ok' => false, 'error' => "The part of your email before the @ must match your name: first initial, middle initial, last name.$hint", 'username' => null];
        }
        return ['ok' => true, 'error' => null, 'username' => $local];
    }
}
