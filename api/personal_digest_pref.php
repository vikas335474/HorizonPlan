<?php
declare(strict_types=1);

// docs/13 I-9 · Read or set the caller's own monthly-digest preference.
//
// GET  -> {status, opt_in:bool}
// POST -> {opt_in:bool} sets it, responds {status, opt_in:bool}
//
// ALWAYS the caller's OWN preference. There is no user_id in the request and
// no way to set somebody else's — identity comes from the verified session,
// like every other write here. That matters more than usual for this one: the
// setting controls whether a real email reaches a real inbox, so "who is this
// for" must never be a caller-supplied value.
//
// Self-serve individuals only (kind='personal'). A firm's client has an
// adviser whose job the follow-up is, and the plan-review mailer (docs/10
// P0-3) is the mechanism for that relationship — this would cut across it.
// Gated on tenant kind, matching api/lib/SelfService.php.
//
// Errors: 400 (opt_in missing/not a bool), 403 (not a personal tenant),
// 405 (other methods).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

if (!tenantIsPersonal($db, (int) $session['tenant_id'])) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'The monthly summary is only available on a self-serve plan.',
    ]);
    exit();
}

$userId = (int) $session['user_id'];

if ($method === 'GET') {
    $rows = $scopedDb->select('users', ['id' => $userId]);
    $optIn = $rows !== [] && (int) ($rows[0]['plan_digest_opt_in'] ?? 0) === 1;
    echo json_encode(['status' => 'success', 'opt_in' => $optIn]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!array_key_exists('opt_in', $input) || !is_bool($input['opt_in'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'opt_in must be true or false.']);
    exit();
}

// Scoped update on the caller's own row: the helper ANDs in tenant_id, and the
// id is the session's own, so there is no path to another person's setting.
$scopedDb->update('users', ['plan_digest_opt_in' => $input['opt_in'] ? 1 : 0], ['id' => $userId]);

echo json_encode(['status' => 'success', 'opt_in' => $input['opt_in']]);
