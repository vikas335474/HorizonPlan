<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$goalId = (int) ($_GET['base_plan_id'] ?? 0);
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
if ($session['role'] === 'client' && (int) $goalRows[0]['client_id'] !== (int) $session['user_id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your goal.']);
    exit();
}

$subScenarios = $scopedDb->select('sub_scenarios', ['base_plan_id' => $goalId]);

$result = array_map(static function (array $s): array {
    return [
        'id'                           => (int) $s['id'],
        'base_plan_id'                => (int) $s['base_plan_id'],
        'custom_inflation'             => $s['custom_inflation'] !== null ? (float) $s['custom_inflation'] : null,
        'custom_withdrawal_rate'       => $s['custom_withdrawal_rate'] !== null ? (float) $s['custom_withdrawal_rate'] : null,
        'custom_drawdown_return_rate' => $s['custom_drawdown_return_rate'] !== null ? (float) $s['custom_drawdown_return_rate'] : null,
        // docs/07 Session C / docs/06 Section A.
        'custom_accumulation_return_rate' => $s['custom_accumulation_return_rate'] !== null ? (float) $s['custom_accumulation_return_rate'] : null,
        'custom_monthly_sip_amount'       => $s['custom_monthly_sip_amount'] !== null ? (float) $s['custom_monthly_sip_amount'] : null,
        'custom_sip_step_up_rate'         => $s['custom_sip_step_up_rate'] !== null ? (float) $s['custom_sip_step_up_rate'] : null,
        // docs/05 item 3 / docs/06 corpus composition.
        'custom_locked_return_rate'       => $s['custom_locked_return_rate'] !== null ? (float) $s['custom_locked_return_rate'] : null,
        'is_overridden'                => (bool) $s['is_overridden'],
        'updated_at'                   => $s['updated_at'],
    ];
}, $subScenarios);

echo json_encode(['status' => 'success', 'sub_scenarios' => $result]);
