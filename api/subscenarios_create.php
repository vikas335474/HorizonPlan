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
$session = verifyAccessAny($db, ['advisor', 'client']);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$goalId = (int) ($input['base_plan_id'] ?? 0);
if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'base_plan_id is required.']);
    exit();
}

$goalRows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($goalRows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $goalRows[0];
if ($session['role'] === 'client' && (int) $goal['client_id'] !== (int) $userId) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your goal.']);
    exit();
}

// New sub-scenario starts as a snapshot of the parent's current values,
// is_overridden = false, so it inherits future cascade updates until a
// client/advisor actually customizes something on it.
$data = [
    'base_plan_id'                 => $goalId,
    'custom_inflation'             => $goal['inflation_rate'],
    'custom_withdrawal_rate'       => $goal['withdrawal_rate'],
    'custom_drawdown_return_rate' => $goal['drawdown_return_rate'],
    'is_overridden'                => 0,
];

$subScenarioId = $scopedDb->insert('sub_scenarios', $data);
$scopedDb->logChange('sub_scenario', $subScenarioId, 'created', null, json_encode($data), $userId);

echo json_encode(['status' => 'success', 'sub_scenario_id' => $subScenarioId]);
