<?php
declare(strict_types=1);

// docs/11 Prompt E-2 · List a client's dependant children — current age, cost
// driver, and (optionally) which education-type goal each is linked to.
//
// GET only. Same read shape as foundations_read.php / dependants aggregate
// like the household expense sum there: dependants are HOUSEHOLD-shared
// (docs/11 §4 decide-then-build note) — a child belongs to the household, not
// to whichever partner happened to enter the row — so this reads across every
// member of the caller's household, same aggregation foundations_read.php
// already does for cash_flow_items.
//
// Each row carries its sourced suggested cost range for the recorded driver
// (PersonalisationReference::educationRangeForDriver($driver, $db) — cache-
// first against reference_costs/docs/11 Prompt E-3 since the reconciliation,
// falling back to PersonalisationReference's own constants if that cache
// isn't populated yet) and, when linked to a goal, that goal's current
// target_amount so the UI can show "suggested ₹X–Y vs. your goal's current
// ₹Z" side by side — never applied automatically.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PersonalisationReference.php';

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

$clientRows = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
$householdId = ($clientRows !== [] && $clientRows[0]['household_id'] !== null)
    ? (int) $clientRows[0]['household_id']
    : 0;

$sharedClientIds = [$clientId];
if ($householdId > 0) {
    $members = $scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']);
    $ids = array_map(static fn(array $m): int => (int) $m['id'], $members);
    if (count($ids) > 1) {
        $sharedClientIds = $ids;
    }
}

$rows = [];
foreach ($sharedClientIds as $sharedId) {
    foreach ($scopedDb->select('client_dependants', ['client_id' => $sharedId]) as $row) {
        $rows[] = $row;
    }
}

$dependants = array_map(static function (array $row) use ($scopedDb, $db): array {
    $goal = null;
    if ($row['goal_id'] !== null) {
        $goalRows = $scopedDb->select('base_plans', ['id' => (int) $row['goal_id']]);
        if ($goalRows !== []) {
            $goal = [
                'id'            => (int) $goalRows[0]['id'],
                'goal_label'    => $goalRows[0]['goal_label'],
                'target_amount' => $goalRows[0]['target_amount'] !== null ? (float) $goalRows[0]['target_amount'] : null,
            ];
        }
    }

    return [
        'id'             => (int) $row['id'],
        'client_id'      => (int) $row['client_id'],
        'label'          => $row['label'],
        'current_age'    => $row['current_age'] !== null ? (int) $row['current_age'] : null,
        'cost_driver'    => $row['cost_driver'],
        'suggested_range' => PersonalisationReference::educationRangeForDriver($row['cost_driver'], $db),
        'goal'           => $goal,
    ];
}, $rows);

echo json_encode([
    'status'         => 'success',
    'dependants'      => $dependants,
    'household_shared' => count($sharedClientIds) > 1,
    'driver_labels'   => PersonalisationReference::EDUCATION_DRIVER_LABELS,
]);
