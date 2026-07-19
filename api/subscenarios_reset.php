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
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$subScenarioId = (int) ($input['id'] ?? 0);
if ($subScenarioId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$rows = $scopedDb->select('sub_scenarios', ['id' => $subScenarioId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Sub-scenario not found.']);
    exit();
}
$existing = $rows[0];

$goalRows = $scopedDb->select('base_plans', ['id' => (int) $existing['base_plan_id']]);
if (empty($goalRows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Parent goal not found.']);
    exit();
}
$goal = $goalRows[0];

if ($session['role'] === 'client' && (int) $goal['client_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your sub-scenario.']);
    exit();
}

if (!(bool) $existing['is_overridden']) {
    echo json_encode(['status' => 'success', 'message' => 'Already not overridden.', 'sub_scenario_id' => $subScenarioId]);
    exit();
}

// Pull the parent's CURRENT values (not the values as of whenever this row
// was last customized) and clear the override flag in one paired
// update + change_log write, same pattern as the cascade in goals_update.php.
$resetData = [
    'custom_inflation'             => $goal['inflation_rate'],
    'custom_withdrawal_rate'       => $goal['withdrawal_rate'],
    'custom_drawdown_return_rate' => $goal['drawdown_return_rate'],
    'is_overridden'                => 0,
];

$scopedDb->update('sub_scenarios', $resetData, ['id' => $subScenarioId]);

$fieldsToLog = ['custom_inflation', 'custom_withdrawal_rate', 'custom_drawdown_return_rate'];
foreach ($fieldsToLog as $field) {
    $oldValue = $existing[$field];
    $newValue = $resetData[$field];
    if ((string) $oldValue !== (string) $newValue) {
        $scopedDb->logChange(
            'sub_scenario',
            $subScenarioId,
            $field,
            $oldValue !== null ? (string) $oldValue : null,
            $newValue !== null ? (string) $newValue : null,
            $userId
        );
    }
}
$scopedDb->logChange('sub_scenario', $subScenarioId, 'is_overridden', '1', '0', $userId);

echo json_encode(['status' => 'success', 'sub_scenario_id' => $subScenarioId, 'reset_to' => $resetData]);
