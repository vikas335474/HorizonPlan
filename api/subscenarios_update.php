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

if ($session['role'] === 'client') {
    $goalRows = $scopedDb->select('base_plans', ['id' => (int) $existing['base_plan_id']]);
    if (empty($goalRows) || (int) $goalRows[0]['client_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not your sub-scenario.']);
        exit();
    }
}

// Single is_overridden flag covers all three fields (docs/02 Section 4.2 —
// documented behavior, not a bug): customizing any one of them freezes the
// whole row from cascade on all of them.
$overridableFields = ['custom_inflation', 'custom_withdrawal_rate', 'custom_drawdown_return_rate'];

$changes = [];
foreach ($overridableFields as $field) {
    if (!array_key_exists($field, $input)) {
        continue;
    }
    $newValue = $input[$field];
    $oldValue = $existing[$field];
    if ((string) $oldValue !== (string) $newValue) {
        $changes[$field] = [$oldValue, $newValue];
    }
}

if (empty($changes)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes.', 'sub_scenario_id' => $subScenarioId]);
    exit();
}

$updateData = array_combine(array_keys($changes), array_map(static fn($c) => $c[1], $changes));
$updateData['is_overridden'] = 1;
$scopedDb->update('sub_scenarios', $updateData, ['id' => $subScenarioId]);

foreach ($changes as $field => [$oldValue, $newValue]) {
    $scopedDb->logChange(
        'sub_scenario',
        $subScenarioId,
        $field,
        $oldValue !== null ? (string) $oldValue : null,
        $newValue !== null ? (string) $newValue : null,
        $userId
    );
}
// The flag flip itself is logged too — it's the thing that actually changes
// this row's future cascade behavior, so it belongs in the audit trail
// alongside the value changes that triggered it.
if (!(bool) $existing['is_overridden']) {
    $scopedDb->logChange('sub_scenario', $subScenarioId, 'is_overridden', '0', '1', $userId);
}

echo json_encode(['status' => 'success', 'sub_scenario_id' => $subScenarioId, 'changed_fields' => array_keys($changes)]);
