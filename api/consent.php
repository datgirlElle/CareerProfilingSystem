<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Consent.php';

// The signed-in person's own consent receipt, for Student Settings / Staff Profile. Always their own
// ($user['id']); nobody can ask for someone else's.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();

jsonResponse(['consent' => Consent::latestFor(Database::get(), (int) $user['id'])]);
