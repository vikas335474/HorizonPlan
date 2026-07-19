<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PlanMath.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$goalId = (int) ($_GET['id'] ?? 0);
if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$rows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $rows[0];

// A client can only read their own goals, even within their own tenant.
if ($session['role'] === 'client' && (int) $goal['client_id'] !== (int) $session['user_id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your goal.']);
    exit();
}

$baseWithdrawalRate = $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null;
$effectiveWithdrawalRate = $baseWithdrawalRate;

// Optional: compute corpus_multiple for a specific sub-scenario's effective
// rate rather than the parent's, if the caller is looking at one particular
// what-if variant. Falls back to the parent's own withdrawal_rate otherwise.
$subScenarioId = (int) ($_GET['sub_scenario_id'] ?? 0);
if ($subScenarioId > 0) {
    $subRows = $scopedDb->select('sub_scenarios', ['id' => $subScenarioId, 'base_plan_id' => $goalId]);
    if (empty($subRows)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sub-scenario not found for this goal.']);
        exit();
    }
    $sub = $subRows[0];
    if ((bool) $sub['is_overridden'] && $sub['custom_withdrawal_rate'] !== null) {
        $effectiveWithdrawalRate = (float) $sub['custom_withdrawal_rate'];
    }
}

echo json_encode([
    'status' => 'success',
    'goal'   => [
        'id'                       => (int) $goal['id'],
        'client_id'                => (int) $goal['client_id'],
        'goal_type'                => $goal['goal_type'],
        'goal_label'               => $goal['goal_label'],
        'target_amount'            => $goal['target_amount'] !== null ? (float) $goal['target_amount'] : null,
        'target_date'              => $goal['target_date'],
        'initial_net_worth'        => (float) $goal['initial_net_worth'],
        'inflation_rate'           => (float) $goal['inflation_rate'],
        'withdrawal_rate'          => $baseWithdrawalRate,
        'drawdown_return_rate'     => $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null,
        'projection_horizon_years' => (int) $goal['projection_horizon_years'],
        // Computed, never stored — docs/02 Section 4.2.
        'corpus_multiple'          => PlanMath::corpusMultiple($effectiveWithdrawalRate),
    ],
]);
