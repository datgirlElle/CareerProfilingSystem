<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/StaffPosition.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// The signed-in person's own account details for the My Profile / My Account pages: what they entered when they
// signed up (name, email, position) and when the account was made. Never the password: it is stored only as a one-way
// hash, so the pages show a row of dots and an Edit button instead.
$user = Auth::requireLogin();

$stmt = Database::get()->prepare('SELECT email, username, full_name, staff_position, created_at FROM users WHERE id = ?');
$stmt->execute([(int) $user['id']]);
$row = $stmt->fetch();
if (!$row) {
    jsonResponse(['success' => false, 'error' => 'Account not found.'], 404);
}

$out = [
    'success' => true,
    'email' => $row['email'] !== null && $row['email'] !== '' ? $row['email'] : null,
    'memberSince' => $row['created_at'],
];

if ($user['role'] !== 'student') {
    $out['fullName'] = trim((string) $row['full_name']) !== '' ? $row['full_name'] : null;
    $out['roleLabel'] = $user['role'] === 'admin' ? 'Administrator' : StaffPosition::label($row['staff_position'] ?: null);
    // Accounts made before staff signed up with their email (the administrator and older counselors) sign in with this
    // name, so it is shown; newer staff sign in with their email and never see a login name.
    $out['username'] = $row['staff_position'] === null ? $row['username'] : null;
}

jsonResponse($out);
