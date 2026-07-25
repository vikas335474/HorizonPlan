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

if ($session['role'] === 'client' && (int) $goal['client_id'] !== (int) $session['user_id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your goal.']);
    exit();
}

if ($goal['goal_type'] !== 'retirement') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Projection only applies to retirement-type goals.']);
    exit();
}

// Effective values: sub-scenario override if requested and actually active,
// otherwise the parent goal's own values. Same effective-value pattern as
// goals_read.php's corpus_multiple.
$withdrawalRate = $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null;
$inflationRate = (float) $goal['inflation_rate'];
$drawdownReturnRate = $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null;

$subScenarioId = (int) ($_GET['sub_scenario_id'] ?? 0);
if ($subScenarioId > 0) {
    $subRows = $scopedDb->select('sub_scenarios', ['id' => $subScenarioId, 'base_plan_id' => $goalId]);
    if (empty($subRows)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sub-scenario not found for this goal.']);
        exit();
    }
    $sub = $subRows[0];
    if ((bool) $sub['is_overridden']) {
        if ($sub['custom_inflation'] !== null) {
            $inflationRate = (float) $sub['custom_inflation'];
        }
        if ($sub['custom_withdrawal_rate'] !== null) {
            $withdrawalRate = (float) $sub['custom_withdrawal_rate'];
        }
        if ($sub['custom_drawdown_return_rate'] !== null) {
            $drawdownReturnRate = (float) $sub['custom_drawdown_return_rate'];
        }
    }
}

if ($withdrawalRate === null || $drawdownReturnRate === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This goal is missing withdrawal_rate or drawdown_return_rate — both are required to project.']);
    exit();
}

$initialNetWorth = (float) $goal['initial_net_worth'];
$horizonYears = (int) $goal['projection_horizon_years'];

$steady = PlanMath::steadyReturnSeries($initialNetWorth, $withdrawalRate, $inflationRate, $drawdownReturnRate, $horizonYears);
$adverse = PlanMath::adverseSequenceSeries($initialNetWorth, $withdrawalRate, $inflationRate, $drawdownReturnRate, $horizonYears);

$response = [
    'status'     => 'success',
    'goal_id'    => $goalId,
    'assumptions' => [
        'initial_net_worth'    => $initialNetWorth,
        'withdrawal_rate'      => $withdrawalRate,
        'inflation_rate'       => $inflationRate,
        'drawdown_return_rate' => $drawdownReturnRate,
        'horizon_years'        => $horizonYears,
        'corpus_multiple'      => PlanMath::corpusMultiple($withdrawalRate),
    ],
    'steady_return_series'   => $steady,
    'adverse_sequence_series' => $adverse,
    // docs/07 Bet 3: one deterministic 0-100 number, computed from the two
    // series above plus the corpus multiple — no new inputs.
    'readiness_score'        => PlanMath::readinessScore($withdrawalRate, $steady, $adverse),
];

// docs/07 Bet 2: optional third series — "what if you retired in [year]?"
// Only computed when the caller asks for it, so the default projection
// response shape is unchanged for existing callers.
$replayStartYear = (int) ($_GET['replay_start_year'] ?? 0);
if ($replayStartYear > 0) {
    $historyStmt = $db->query('SELECT year, equity_return_pct, cpi_inflation_pct, is_verified FROM market_history ORDER BY year ASC');
    $historyRows = $historyStmt->fetchAll();

    $matchingYear = null;
    foreach ($historyRows as $row) {
        if ((int) $row['year'] === $replayStartYear) {
            $matchingYear = $row;
            break;
        }
    }

    if ($matchingYear === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "No historical data for year $replayStartYear. See market_history_years.php for available years."]);
        exit();
    }

    $historical = PlanMath::historicalSequenceSeries(
        $initialNetWorth,
        $withdrawalRate,
        array_map(static fn(array $r) => [
            'year'               => (int) $r['year'],
            'equity_return_pct'  => (float) $r['equity_return_pct'],
            'cpi_inflation_pct'  => (float) $r['cpi_inflation_pct'],
        ], $historyRows),
        $replayStartYear,
        $horizonYears
    );

    // Whether the years actually used (accounting for wrap-around once real
    // history runs out) are ALL verified — false if even one used year is
    // still an unverified seed row (docs/07 Bet 2, sql/015).
    $count = count($historyRows);
    $startIndex = array_search($replayStartYear, array_column($historyRows, 'year'), true);
    $allVerified = true;
    for ($n = 0; $n < min($horizonYears, $count); $n++) {
        if (!(bool) $historyRows[($startIndex + $n) % $count]['is_verified']) {
            $allVerified = false;
            break;
        }
    }

    $response['historical_sequence_series'] = $historical;
    $response['historical_replay_meta'] = [
        'start_year'  => $replayStartYear,
        'wrapped'     => $horizonYears > ($count - $startIndex),
        'is_verified' => $allVerified,
    ];
}

echo json_encode($response);
