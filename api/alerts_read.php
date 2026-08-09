<?php
declare(strict_types=1);

// docs/12 Prompt D-4 · Read the full alerts bundle for one client — every
// fact from goal_met/goal_drift/price_stale/review_due/foundations_gap that
// currently applies. GET only.
//
// Readable by an advisor (any client in their tenant, via ?client_id=) AND
// by a client themselves (their own alerts only — client_id is forced to
// their own id server-side, same rule as foundations_read.php /
// cash_flow_list.php).
//
// Recomputed fresh on every call — see AlertsEngine.php's header for why
// this ships stateless-only this session (no acknowledge/snooze).
//
// Output: {status, alerts:[{type, severity, subject, factual_message,
// deep_link}]}.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/CashFlowSummary.php';
require_once __DIR__ . '/lib/FinancialFoundations.php';
require_once __DIR__ . '/lib/FoundationsInputs.php';
require_once __DIR__ . '/lib/AlertsEngine.php';
require_once __DIR__ . '/lib/AlertsInputs.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);
$isClient = $session['role'] === 'client';

$clientId = $isClient
    ? (int) $session['user_id']
    : (int) ($_GET['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

if (!$isClient && $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$bundle = gatherClientAlertBundle($scopedDb, $db, $clientId);

echo json_encode([
    'status' => 'success',
    'alerts' => computeClientAlerts($bundle),
]);
