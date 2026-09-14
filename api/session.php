<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::currentUser();
$response = ['user' => $user, 'csrfToken' => Csrf::token()];
if ($user !== null) {
    $response['sessionTimeout'] = Auth::sessionTimeoutPolicy();
}
jsonResponse($response);