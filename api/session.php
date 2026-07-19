<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();
$session = getCurrentSession($db);

if ($session === null) {
    // Not an error condition — "not logged in" is an expected, normal response
    // on first page load. Still 401 so the frontend can branch on status code
    // without inspecting the body.
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No active session.']);
    exit();
}

// Re-issue the CSRF token on every session check so it stays fresh after
// page reloads. The JS layer reads it from the cookie and attaches it to
// all subsequent mutation requests as X-CSRF-Token.
issueCsrfToken();

echo json_encode([
    'status' => 'success',
    'user'   => [
        'user_id'   => (int) $session['user_id'],
        'tenant_id' => (int) $session['tenant_id'],
        'role'      => $session['role'],
    ],
]);
