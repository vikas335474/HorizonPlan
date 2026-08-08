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
// Output: {status, protection:{...}, foundations:{checks[], unmet, open, ok}}.
// Every check carries its own status, including 'not_recorded'; the caller must
// render that as an open question, never as a passed check.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/CashFlowSummary.php';
require_once __DIR__ . '/lib/FinancialFoundations.php';

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

// --- scope: whose expenses and debts count? ---------------------------------
// Reserve and debt are household facts when this client is in a household
// (sql/029 for an advisor's family, sql/035 for a self-serve couple): shared
// expenses get recorded by whichever member entered them, so a per-person
// reserve would report one partner as badly under-reserved and the other as
// fine, with neither figure true. Cover stays per-person throughout — see
// FinancialFoundations::summary().
$clientRows = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
$householdId = ($clientRows !== [] && $clientRows[0]['household_id'] !== null)
    ? (int) $clientRows[0]['household_id']
    : 0;

$sharedClientIds = [$clientId];
$sharedScope = 'person';
if ($householdId > 0) {
    $members = $scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']);
    $ids = array_map(static fn(array $m): int => (int) $m['id'], $members);
    // A household of one is not a household for labelling purposes: reporting
    // "across your household" to someone whose partner has not joined yet
    // would be true but confusing.
    if (count($ids) > 1) {
        $sharedClientIds = $ids;
        $sharedScope = 'household';
    }
}

// --- the figures this module does not own ----------------------------------
// Income stays the individual's own (it sizes their cover); expenses follow the
// shared scope.
$ownCashFlow = summarizeCashFlowItems($scopedDb->select('cash_flow_items', ['client_id' => $clientId]));

$sharedExpenses = 0.0;
$portfolio = [];
foreach ($sharedClientIds as $sharedId) {
    $sharedExpenses += (float) summarizeCashFlowItems(
        $scopedDb->select('cash_flow_items', ['client_id' => $sharedId])
    )['monthly_expense'];
    foreach ($scopedDb->select('client_portfolio_items', ['client_id' => $sharedId]) as $row) {
        $portfolio[] = $row;
    }
}

$liquidAssets = 0.0;
$liabilities = [];
foreach ($portfolio as $row) {
    if ($row['item_kind'] === 'asset') {
        // Only the liquid bucket counts as an emergency reserve. EPF/NPS/PPF
        // are real wealth but cannot be reached in the week somebody loses a
        // job, which is the entire question this check asks.
        if ($row['bucket'] === 'liquid') {
            $liquidAssets += (float) $row['value'];
        }
        continue;
    }
    $liabilities[] = [
        'label'         => (string) ($row['description'] ?? '') !== '' ? (string) $row['description'] : (string) $row['category'],
        'value'         => (float) $row['value'],
        'interest_rate' => $row['interest_rate'],
    ];
}

// The return assumption to price debt against: the HIGHEST accumulation rate
// across this client's goals. Highest is the conservative choice — it flags
// the FEWEST loans, so a loan called costly here exceeds even the most
// optimistic thing the plan assumes anywhere. NULL when no goal carries a rate,
// in which case the debt comparison reports as unavailable rather than passed.
// Read across the same scope as the debts it is compared against, so a
// household's loans are priced against the household's own assumptions.
$assumedReturn = null;
foreach ($sharedClientIds as $sharedId) {
    foreach ($scopedDb->select('base_plans', ['client_id' => $sharedId]) as $plan) {
        $rate = $plan['accumulation_return_rate'] ?? null;
        if ($rate !== null && is_numeric($rate)) {
            $assumedReturn = $assumedReturn === null ? (float) $rate : max($assumedReturn, (float) $rate);
        }
    }
}

// --- the little this module does own ---------------------------------------
$protectionRows = $scopedDb->select('client_protection', ['client_id' => $clientId]);
$protection = $protectionRows[0] ?? null;

$dependants = ($protection && $protection['dependants_count'] !== null)
    ? (int) $protection['dependants_count']
    : null;
$termCover = ($protection && $protection['term_life_cover'] !== null)
    ? (float) $protection['term_life_cover']
    : null;
$healthCover = ($protection && $protection['health_cover'] !== null)
    ? (float) $protection['health_cover']
    : null;

$foundations = FinancialFoundations::summary(
    // Expenses follow the shared scope; income does NOT — it sizes this
    // person's own cover, and averaging two earners' incomes would
    // under-insure one and over-insure the other.
    $sharedExpenses,
    $liquidAssets,
    (float) $ownCashFlow['monthly_income'] * 12.0,
    $dependants,
    $termCover,
    $healthCover,
    $liabilities,
    $assumedReturn,
    // An untouched portfolio ledger is an unanswered question, not "no debt".
    $portfolio !== [],
    $sharedScope
);

echo json_encode([
    'status'     => 'success',
    'protection' => [
        'dependants_count' => $dependants,
        'term_life_cover'  => $termCover,
        'health_cover'     => $healthCover,
        'recorded'         => $protection !== null,
    ],
    'scope'        => $sharedScope,
    'member_count' => count($sharedClientIds),
    'foundations'  => $foundations,
]);
