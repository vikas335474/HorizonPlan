<?php
declare(strict_types=1);

// Authentication: end the current session. Accepts any method and is idempotent
// by design — calling it with no active (or an already-expired) session is not
// an error, it just confirms there's nothing to log out of. Deletes the
// active_sessions row and clears the session + CSRF cookies (destroySession()).
// Output: {status, message}.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();

// Idempotent by design — calling this with no active session (or an already-
// expired one) is not an error, it just confirms there's nothing to log out of.
destroySession($db);

echo json_encode(['status' => 'success', 'message' => 'Logged out.']);
