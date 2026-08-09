<?php
declare(strict_types=1);

// docs/10 P1-4 · Read the financial-foundations check for one client: emergency
// reserve, income-replacement cover, medical cover, and debt costing more than
// the plan assumes to earn.
//
// GET only. Readable by an advisor (any client in their tenant) AND by a client
// (their own row only) — a client session's client_id is forced to their own id
// server-side and a query-string client_id is ignored, exactly like
// cash_flow_list.php / client_portfolio_list.php.
//
// Almost nothing here is stored by this module. Expenses and income come from
// cash_flow_items (sql/030), liquid assets and liabilities from
// client_portfolio_items (sql/018); only the two cover amounts and the
// dependants count live in client_protection (sql/034). That is deliberate —
// see the migration header — so a figure can never drift between two copies.
//
// docs/12 Prompt D-4: the actual gathering (household scope, cash-flow/
// portfolio assembly, the FinancialFoundations::summary() call) moved to
// foundationsSummaryForClient() (api/lib/FoundationsInputs.php) so the alerts
// engine reads through the SAME function this endpoint does, rather than a
// second copy that could drift. This file is now just auth + the call.
//
// Output: {status, protection:{...}, foundations:{checks[], unmet, open, ok}}.
// Every check carries its own status, including 'not_recorded'; the caller must
// render that as an open question, never as a passed check.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/CashFlowSummary.php';
require_once __DIR__ . '/lib/FinancialFoundations.php';
require_once __DIR__ . '/lib/FoundationsInputs.php';

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

echo json_encode(['status' => 'success'] + foundationsSummaryForClient($scopedDb, $clientId));
