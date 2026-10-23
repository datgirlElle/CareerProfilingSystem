<?php

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../../modules/Authentication/lib/Auth.php';
require_once __DIR__ . '/../../modules/Authentication/lib/Rbac.php';
require_once __DIR__ . '/../../modules/AuditLog/lib/AuditLogger.php';
require_once __DIR__ . '/../../modules/Authentication/lib/TwoFactor.php';
require_once __DIR__ . '/../../modules/Authentication/lib/Csrf.php';
require_once __DIR__ . '/../lib/SecurityHeaders.php';

SecurityHeaders::send();
header('Content-Type: application/json');

// Any uncaught error in an API call is written to the server log (Render's Logs) and answered as JSON,
// so the page shows a message instead of breaking. The administrator sees the actual error, to diagnose
// problems on the live site; everyone else gets a generic message.
set_exception_handler(function (Throwable $e): void {
    error_log('[api] ' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $user = $_SESSION['user'] ?? null;
    $isAdmin = is_array($user) && ($user['role'] ?? '') === 'admin';
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $isAdmin
        ? 'Server error: ' . $e->getMessage()
        : 'Something went wrong on the server. Please try again.']);
});

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
