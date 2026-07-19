<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
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

// A client can only ever list their own goals — never trust a client_id from
// the query string for a 'client' session, ignore it entirely and use the
// session's own user_id. An advisor must supply which client's goals to list.
if ($session['role'] === 'client') {
    $clientId = (int) $session['user_id'];
} else {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
        exit();
    }
}

$goals = $scopedDb->select('base_plans', ['client_id' => $clientId]);

$result = array_map(static function (array $goal): array {
    return [
        'id'                       => (int) $goal['id'],
        'client_id'                => (int) $goal['client_id'],
        'goal_type'                => $goal['goal_type'],
        'goal_label'               => $goal['goal_label'],
        'target_amount'            => $goal['target_amount'] !== null ? (float) $goal['target_amount'] : null,
        'target_date'              => $goal['target_date'],
        'initial_net_worth'        => (float) $goal['initial_net_worth'],
        'inflation_rate'           => (float) $goal['inflation_rate'],
        'withdrawal_rate'          => $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null,
        'drawdown_return_rate'     => $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null,
        'projection_horizon_years' => (int) $goal['projection_horizon_years'],
    ];
}, $goals);

echo json_encode(['status' => 'success', 'goals' => $result]);
