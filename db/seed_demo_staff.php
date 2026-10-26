<?php
/**
 * Demo sign-ins for presentations: one Guidance Counselor and one Guidance Facilitator.
 * Each run gives both accounts a new random password and prints it, so no password is
 * stored in this file. Running it again resets the passwords (and unlocks the accounts).
 *
 *   php db/seed_demo_staff.php
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/PasswordPolicy.php';

$pdo = Database::get();

$accounts = [
    ['username' => 'democounselor', 'name' => 'Demo Counselor', 'position' => 'counselor', 'email' => 'democounselor@profilepath.local'],
    ['username' => 'demofacilitator', 'name' => 'Demo Facilitator', 'position' => 'facilitator', 'email' => 'demofacilitator@profilepath.local'],
];

function demoPassword(PDO $pdo): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%*?';
    do {
        $chars = [];
        foreach ([[$upper, 3], [$lower, 5], [$digits, 3], [$symbols, 1]] as [$set, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $chars[] = $set[random_int(0, strlen($set) - 1)];
            }
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $password = implode('', $chars);
    } while (PasswordPolicy::errors($pdo, $password) !== []);
    return $password;
}

$find = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(username) = LOWER(?)');
$insert = $pdo->prepare(
    "INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, staff_position, is_active, approval_status)
     VALUES ('counselor', ?, ?, ?, NOW(), ?, ?, TRUE, 'approved')"
);
$update = $pdo->prepare(
    "UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()), staff_position = ?,
            is_active = TRUE, approval_status = 'approved', failed_login_attempts = 0, locked_until = NULL, updated_at = NOW()
     WHERE id = ?"
);

$lines = [];
foreach ($accounts as $a) {
    $find->execute([$a['username']]);
    $existing = $find->fetch();
    if ($existing && $existing['role'] !== 'counselor') {
        echo "Skipped {$a['username']}: that username belongs to a non-staff account.\n";
        continue;
    }
    $password = demoPassword($pdo);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($existing) {
        $update->execute([$hash, $a['position'], $existing['id']]);
        $status = 'password reset';
    } else {
        $insert->execute([$a['username'], $hash, $a['email'], $a['name'], $a['position']]);
        $status = 'created';
    }
    $label = $a['position'] === 'facilitator' ? 'Guidance Facilitator' : 'Guidance Counselor';
    $lines[] = sprintf("%-21s %-16s %-14s (%s)", $label, $a['username'], $password, $status);
}

echo "\nSign in at /staff-login\n\n";
echo sprintf("%-21s %-16s %s\n", 'Position', 'Username', 'Password');
echo implode("\n", $lines) . "\n\n";
