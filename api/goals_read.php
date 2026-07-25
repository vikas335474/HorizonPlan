<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
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
        // docs/07 Session C / docs/06 Section A: accumulation-phase fields.
        'accumulation_return_rate' => $goal['accumulation_return_rate'] !== null ? (float) $goal['accumulation_return_rate'] : null,
        'current_age'              => $goal['current_age'] !== null ? (int) $goal['current_age'] : null,
        'retirement_age'           => $goal['retirement_age'] !== null ? (int) $goal['retirement_age'] : null,
        'monthly_sip_amount'       => $goal['monthly_sip_amount'] !== null ? (float) $goal['monthly_sip_amount'] : null,
        'sip_step_up_rate'         => $goal['sip_step_up_rate'] !== null ? (float) $goal['sip_step_up_rate'] : null,
        // docs/07 Bet 1: which template/customization (if any) most recently
        // set drawdown_return_rate via goals_apply_template.php. The frontend
        // resolves the name from the templates it already has loaded rather
        // than this endpoint doing a cross-tenant join for a global template.
        'applied_template_id'      => $goal['applied_template_id'] !== null ? (int) $goal['applied_template_id'] : null,
        'applied_customization_id' => $goal['applied_customization_id'] !== null ? (int) $goal['applied_customization_id'] : null,
        'projection_horizon_years' => (int) $goal['projection_horizon_years'],
        // Computed, never stored — docs/02 Section 4.2.
        'corpus_multiple'          => PlanMath::corpusMultiple($effectiveWithdrawalRate),
    ],
]);
