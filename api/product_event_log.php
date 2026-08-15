<?php
declare(strict_types=1);

// docs/13 I-5 · Record one product-analytics event for the acting session.
// POST only. Any authenticated session (advisor OR client) — the events
// themselves are about using the product, not about a role.
//
// The event is ALWAYS attributed to the caller's own verified session: there
// is no user_id or tenant_id in the request body, and no way to log an event
// as somebody else. Same posture as every other write here — identity comes
// from the session, never from input.
//
// Input: {event_name, context?}. `context` is reduced to a whitelisted,
// non-financial subset before storage (ProductEvents.php); anything else is
// dropped rather than stored, so a client bug cannot put a rupee figure in
// the analytics table.
//
// Output: {status:'success', recorded:bool}. `recorded` is false for a
// well-formed request naming an unknown event — the caller is told, but this
// is deliberately NOT an error status: analytics must never break, or appear
// to break, the user-facing action that triggered it.
// Errors: 400 (missing event_name), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/ProductEvents.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$eventName = is_string($input['event_name'] ?? null) ? trim($input['event_name']) : '';
if ($eventName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'event_name is required.']);
    exit();
}

if (!isKnownProductEvent($eventName)) {
    // Not an error: see the header. The caller learns nothing happened, and
    // the user-facing action it accompanied is unaffected.
    echo json_encode(['status' => 'success', 'recorded' => false]);
    exit();
}

$context = is_array($input['context'] ?? null) ? $input['context'] : [];

recordProductEvent($scopedDb, (int) $session['user_id'], $eventName, $context);

echo json_encode(['status' => 'success', 'recorded' => true]);
