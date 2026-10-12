<?php

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Rbac.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/TwoFactor.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/SecurityHeaders.php';

SecurityHeaders::send();
header('Content-Type: application/json');
Auth::start();

// CSRF check applies to every state-changing request. GET/HEAD/OPTIONS are
// read-only and exempt. The token itself is handed out via api/session.php.
$csrfExemptMethods = ['GET', 'HEAD', 'OPTIONS'];
if (!in_array($_SERVER['REQUEST_METHOD'], $csrfExemptMethods, true)) {
    Csrf::requireValid();
}

/** @return array<string,mixed> */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Always ends the request (exit). Declared `void` rather than `never` so the
// app also runs on PHP 8.0 (e.g. XAMPP's bundled PHP); `never` needs 8.1+.
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}
