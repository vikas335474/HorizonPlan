<?php
declare(strict_types=1);

// Cash-flow module (docs/10): read a client's income/expense line items and the
// summed monthly surplus. Readable by an advisor (any client in their tenant)
// AND by a client (their own rows only). A client session's client_id is forced
// to their own id server-side — a query-string client_id is ignored — exactly
// like client_portfolio_list.php / risk_profile_read.php.
//
// The advisor-only SIP comparison (surplus vs. total goal SIPs — a planning
// judgement) is attached ONLY for an advisor session, never leaked to a client.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/CashFlowSummary.php';

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

// For an advisor, confirm the client is actually in this tenant (404 otherwise),
// same posture as the write endpoints. A client session's id is already its own
// verified session identity, so it needs no separate lookup.
if (!$isClient && $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$rows = $scopedDb->select('cash_flow_items', ['client_id' => $clientId]);

$items = array_map(static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'kind'       => $r['kind'],
        'label'      => $r['label'],
        'amount'     => (float) $r['amount'],
        'cadence'    => $r['cadence'],
        'category'   => $r['category'],
        'is_active'  => (bool) ((int) $r['is_active']),
        'updated_at' => $r['updated_at'],
    ];
}, $rows);

$response = [
    'status'  => 'success',
    'items'   => $items,
    'summary' => summarizeCashFlowItems($rows),
];

// Advisor-only planning framing: does the surplus cover the plan's SIPs?
if (!$isClient) {
    $response['sip_comparison'] = cashFlowSipComparison(
        $response['summary']['monthly_surplus'],
        totalMonthlySipForClient($scopedDb, $clientId)
    );
}

echo json_encode($response);
