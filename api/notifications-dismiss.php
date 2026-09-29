<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
}

// Notifications are computed live, so "delete" means remembering which
// items (by the stable key api/notifications.php gives each one) this
// student no longer wants to see.
$body = readJsonBody();
$keys = array_values(array_unique(array_filter(
    array_map('strval', (array) ($body['keys'] ?? [])),
    fn($k) => preg_match('/^[a-z]+:\d+$/', $k) === 1
)));
if (!$keys) {
    jsonResponse(['success' => false, 'error' => 'Nothing to delete.'], 400);
}
$keys = array_slice($keys, 0, 100);

$stmt = Database::get()->prepare(
    'INSERT INTO notification_dismissals (user_id, item_key) VALUES (?, ?) ON CONFLICT DO NOTHING'
);
foreach ($keys as $key) {
    $stmt->execute([$user['id'], $key]);
}

jsonResponse(['success' => true, 'deleted' => count($keys)]);
