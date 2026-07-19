<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();

// Idempotent by design — calling this with no active session (or an already-
// expired one) is not an error, it just confirms there's nothing to log out of.
destroySession($db);

echo json_encode(['status' => 'success', 'message' => 'Logged out.']);
