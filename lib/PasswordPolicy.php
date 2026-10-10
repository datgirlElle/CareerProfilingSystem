<?php

/**
 * Checks a new password against the admin-configured password policy
 * (Security Configuration: minimum length and required character types).
 */
class PasswordPolicy
{
    /**
     * The rules in force (Security Configuration), so a page can show the same checklist the server will enforce.
     *
     * @return array{minLength:int,requireUpper:bool,requireLower:bool,requireNumber:bool,requireSymbol:bool}
     */
    public static function rules(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'password.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'minLength' => (int) ($rows['password.minLength'] ?? 8),
            'requireUpper' => ($rows['password.requireUpper'] ?? 'true') === 'true',
            'requireLower' => ($rows['password.requireLower'] ?? 'true') === 'true',
            'requireNumber' => ($rows['password.requireNumber'] ?? 'true') === 'true',
            'requireSymbol' => ($rows['password.requireSymbol'] ?? 'true') === 'true',
        ];
    }

    /** @return array<int,string> human-readable problems; empty when the password is acceptable */
    public static function errors(PDO $pdo, string $password): array
    {
        $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'password.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $minLength = (int) ($rows['password.minLength'] ?? 8);
        $requireUpper = ($rows['password.requireUpper'] ?? 'true') === 'true';
        $requireLower = ($rows['password.requireLower'] ?? 'true') === 'true';
        $requireNumber = ($rows['password.requireNumber'] ?? 'true') === 'true';
        $requireSymbol = ($rows['password.requireSymbol'] ?? 'true') === 'true';

        $errors = [];
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least $minLength characters.";
        }
        if ($requireUpper && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain an uppercase letter.';
        }
        if ($requireLower && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain a lowercase letter.';
        }
        if ($requireNumber && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain a number.';
        }
        if ($requireSymbol && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain a symbol.';
        }
        return $errors;
    }
}
