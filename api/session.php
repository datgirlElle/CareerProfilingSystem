<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/StaffPosition.php';

$user = Auth::currentUser();

// A student's section can be corrected by staff after they've logged in
// (api/students.php, type=updateSection), but the session copy is only built
// at login. Re-read it here so the change shows up without a fresh sign-in.
if ($user !== null && $user['role'] === 'student') {
    $stmt = Database::get()->prepare('SELECT section FROM students WHERE user_id = ?');
    $stmt->execute([(int) $user['id']]);
    $section = $stmt->fetchColumn();
    if ($section !== false && $section !== ($user['section'] ?? null)) {
        $user['section'] = $section;
        $_SESSION['user']['section'] = $section;
    }
}

// Guidance counselors and facilitators are the same role with the same access;
// the title beside their name is looked up here so it is right even for a
// session that began before positions existed.
if ($user !== null && $user['role'] === 'counselor') {
    $stmt = Database::get()->prepare('SELECT staff_position FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $user['positionLabel'] = StaffPosition::label($stmt->fetchColumn() ?: null);
}

$response = ['user' => $user, 'csrfToken' => Csrf::token()];
if ($user !== null) {
    $response['sessionTimeout'] = Auth::sessionTimeoutPolicy();
}
jsonResponse($response);
