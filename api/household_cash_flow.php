<?php
declare(strict_types=1);

// Cash-flow module (docs/10) — household roll-up: the sum of every member
// client's own cash-flow statement (income/expense/surplus) plus their combined
// monthly SIP, for one household. Advisor-only, tenant-scoped:
// computeHouseholdCashFlow() reads members and their rows through TenantScopedDb,
// so a household can never pull in another firm's client, and returns null
// (→ 404) for a household outside this tenant. Same shape/contract as
// household_projection.php, including its access rule: advisor, or (since
// sql/035) a client in a PERSONAL tenant reading their OWN household.
//
// The combined-SIP figure is deliberately withheld from a client by
// cash_flow_list.php, because there it is the ADVISOR's planning judgement
// about someone else's plan. It is not withheld here, and the difference is not
// an inconsistency: a firm client cannot reach this endpoint at all, and a
// self-directed couple authored both plans themselves — the SIP total is their
// own figure about their own goals.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/CashFlowSummary.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$access = verifyHouseholdAccess($db);
$session = $access['session'];
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// A personal client is pinned to their own household; a supplied id is ignored.
$householdId = resolveHouseholdId($access, (int) ($_GET['household_id'] ?? 0));
if ($householdId <= 0) {
    http_response_code($access['is_client'] ? 404 : 400);
    echo json_encode([
        'status' => 'error',
        'message' => $access['is_client']
            ? 'You are not part of a household yet.'
            : 'household_id is required.',
    ]);
    exit();
}

$aggregate = computeHouseholdCashFlow($scopedDb, $householdId);
if ($aggregate === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Household not found.']);
    exit();
}

echo json_encode(['status' => 'success'] + $aggregate);
