<?php

require_once __DIR__ . '/modules/Authentication/lib/Auth.php';
require_once __DIR__ . '/modules/Authentication/lib/Rbac.php';
require_once __DIR__ . '/shared/lib/SecurityHeaders.php';

SecurityHeaders::send();

$user = Auth::currentUser();

if ($user === null) {
    header('Location: student-login');
} elseif ($user['role'] === 'student') {
    header('Location: assessment');
} else {
    // The first sidebar page open to this staff member (the permission table can close Student Information).
    header('Location: ' . Rbac::firstOpenPage(Rbac::roleKey($user)));
}
exit;
