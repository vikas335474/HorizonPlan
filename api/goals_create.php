<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // super_admin also passes, per verifyAccess()
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$clientId = (int) ($input['client_id'] ?? 0);
$goalType = (string) ($input['goal_type'] ?? '');
$goalLabel = trim((string) ($input['goal_label'] ?? ''));
$initialNetWorth = $input['initial_net_worth'] ?? null;
$inflationRate = $input['inflation_rate'] ?? null;

$allowedGoalTypes = ['retirement', 'education', 'home_purchase', 'other'];

if ($clientId <= 0 || $goalLabel === '' || !in_array($goalType, $allowedGoalTypes, true)
    || $initialNetWorth === null || $inflationRate === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id, goal_type, goal_label, initial_net_worth, and inflation_rate are required.']);
    exit();
}

// Confirm the client actually belongs to this advisor's tenant before creating
// a goal under them — client_id is caller-supplied, so this is the one place
// that boundary has to be checked explicitly (base_plans itself has no FK
// enforcing tenant_id/client_id/users.tenant_id agreement at the DB level).
$clientCheck = $db->prepare(
    "SELECT id FROM users WHERE id = :id AND tenant_id = :tenant_id AND role = 'client' LIMIT 1"
);
$clientCheck->execute([':id' => $clientId, ':tenant_id' => $tenantId]);
if (!$clientCheck->fetch()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

// goal_type gates whether withdrawal_rate / drawdown_return_rate are meaningful
// (docs/02 Section 4.2, 4.3) — silently ignore them for non-retirement goals
// rather than storing values that don't apply.
$isRetirement = $goalType === 'retirement';

$data = [
    'client_id'                => $clientId,
    'goal_type'                => $goalType,
    'goal_label'                => $goalLabel,
    'target_amount'            => $input['target_amount'] ?? null,
    'target_date'              => $input['target_date'] ?? null,
    'initial_net_worth'        => $initialNetWorth,
    'inflation_rate'           => $inflationRate,
    'withdrawal_rate'          => $isRetirement ? ($input['withdrawal_rate'] ?? 3.5) : null,
    'drawdown_return_rate'     => $isRetirement ? ($input['drawdown_return_rate'] ?? null) : null,
    'projection_horizon_years' => (int) ($input['projection_horizon_years'] ?? 30),
];

$goalId = $scopedDb->insert('base_plans', $data);

$scopedDb->logChange('base_plan', $goalId, 'created', null, json_encode($data), (int) $session['user_id']);

echo json_encode(['status' => 'success', 'goal_id' => $goalId]);
