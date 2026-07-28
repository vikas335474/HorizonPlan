<?php
declare(strict_types=1);

// P0-3 · Scheduled plan reviews — an advisor sets a client's review cadence
// (off / quarterly / annually). Advisor-only, tenant-scoped: an advisor can
// only touch a client in their own firm. Opt-in by design — 'off' is the
// default and clears any schedule, so this is the only path that ever enrolls
// a client into outbound review email.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
$cadence  = (string) ($input['cadence'] ?? '');

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}
if (!in_array($cadence, ['off', 'quarterly', 'annually'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "cadence must be 'off', 'quarterly' or 'annually'."]);
    exit();
}

// The client must belong to this advisor's tenant.
if ($scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Client not found.']);
    exit();
}

// Upsert: one schedule row per client. Changing the cadence deliberately does
// NOT reset last_sent_at — a client switched quarterly→annually keeps their
// existing sent history, so they're not re-emailed immediately.
$existing = $scopedDb->select('plan_review_schedules', ['client_id' => $clientId]);
if ($existing === []) {
    $scopedDb->insert('plan_review_schedules', [
        'client_id' => $clientId,
        'cadence'   => $cadence,
    ]);
} else {
    $scopedDb->update('plan_review_schedules', ['cadence' => $cadence], ['client_id' => $clientId]);
}

echo json_encode(['status' => 'success', 'client_id' => $clientId, 'cadence' => $cadence]);
