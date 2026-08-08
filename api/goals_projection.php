<?php
declare(strict_types=1);

// Planning engine surface (docs/07 Bets 2/3, docs/06): compute a goal's
// projection series and Retirement Readiness Score. GET, advisor OR client
// (client only their own goal, 403 otherwise). Retirement goals only (400).
// Tenant-scoped read; all math is pure PlanMath (no DB writes).
//
// Applies sub-scenario overrides when ?sub_scenario_id= is an active override,
// else the parent's own values. Always returns steady + adverse decumulation
// series and the 0-100 readiness_score; adds a two-bucket corpus_composition
// when the goal has a liquid/locked split, accumulation + lifecycle series when
// it has ages + an accumulation rate, and a historical replay series when
// ?replay_start_year= is given (flagged verified/unverified per market_history).
// Errors: 400 (non-retirement / missing rates / unknown replay year),
// 403 (not your goal), 404 (goal / sub-scenario), 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PlanMath.php';
// For the retirement target's expense input — the person's own recorded spend.
require_once __DIR__ . '/lib/CashFlowSummary.php';

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
// docs/07 Session C / docs/06 Section A — same effective-value pattern.
$accumulationReturnRate = $goal['accumulation_return_rate'] !== null ? (float) $goal['accumulation_return_rate'] : null;
$monthlySipAmount = $goal['monthly_sip_amount'] !== null ? (float) $goal['monthly_sip_amount'] : null;
$sipStepUpRate = $goal['sip_step_up_rate'] !== null ? (float) $goal['sip_step_up_rate'] : null;
// docs/05 item 3 / docs/06 corpus composition — liquid_corpus_amount/
// locked_corpus_amount are NOT overridable per sub-scenario (same as
// initial_net_worth), only the locked bucket's return rate is.
$liquidCorpusAmount = $goal['liquid_corpus_amount'] !== null ? (float) $goal['liquid_corpus_amount'] : null;
$lockedCorpusAmount = $goal['locked_corpus_amount'] !== null ? (float) $goal['locked_corpus_amount'] : null;
$lockedReturnRate = $goal['locked_return_rate'] !== null ? (float) $goal['locked_return_rate'] : null;

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
        if ($sub['custom_accumulation_return_rate'] !== null) {
            $accumulationReturnRate = (float) $sub['custom_accumulation_return_rate'];
        }
        if ($sub['custom_monthly_sip_amount'] !== null) {
            $monthlySipAmount = (float) $sub['custom_monthly_sip_amount'];
        }
        if ($sub['custom_sip_step_up_rate'] !== null) {
            $sipStepUpRate = (float) $sub['custom_sip_step_up_rate'];
        }
        if ($sub['custom_locked_return_rate'] !== null) {
            $lockedReturnRate = (float) $sub['custom_locked_return_rate'];
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

// docs/05 item 3 / docs/06 corpus composition: use the two-bucket
// (liquid-first, then locked) decumulation methods when a goal has actually
// decomposed its corpus; otherwise fall back to the original single-bucket
// methods, byte-for-byte the same call every existing goal has always made.
// goals_create.php/goals_update.php enforce liquid+locked summing to
// initial_net_worth and both-or-neither, so only locked_return_rate needs
// checking here.
$hasCorpusComposition = $liquidCorpusAmount !== null && $lockedCorpusAmount !== null;
if ($hasCorpusComposition && $lockedReturnRate === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This goal has a liquid/locked corpus split but no locked_return_rate — required to project a decomposed corpus.']);
    exit();
}

[$steady, $adverse] = PlanMath::decumulationSeriesForGoal(
    $initialNetWorth, $withdrawalRate, $inflationRate, $drawdownReturnRate, $horizonYears,
    $liquidCorpusAmount, $lockedCorpusAmount, $lockedReturnRate
);

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

if ($hasCorpusComposition) {
    $response['corpus_composition'] = [
        'liquid_corpus_amount' => $liquidCorpusAmount,
        'locked_corpus_amount' => $lockedCorpusAmount,
        'locked_return_rate'   => $lockedReturnRate,
    ];
}

// docs/07 Session C / docs/06 Section A: accumulation + combined lifecycle
// series — only computed when the goal actually has ages + an accumulation
// return set, so a goal that's still decumulation-only (every Phase 1 goal,
// and any Phase 2 goal that never fills these in) gets exactly the response
// shape it got before this session, unchanged.
$currentAge = $goal['current_age'] !== null ? (int) $goal['current_age'] : null;
$retirementAge = $goal['retirement_age'] !== null ? (int) $goal['retirement_age'] : null;
if ($currentAge !== null && $retirementAge !== null && $accumulationReturnRate !== null) {
    $yearsToRetirement = $retirementAge - $currentAge;
    $sipAmount = $monthlySipAmount ?? 0.0;
    $sipStepUp = $sipStepUpRate ?? 0.0;

    $accumulationSeries = PlanMath::accumulationSeries(
        $initialNetWorth,
        $accumulationReturnRate,
        $sipAmount,
        $sipStepUp,
        $yearsToRetirement
    );
    $lifecycleSeries = PlanMath::lifecycleSeries(
        $initialNetWorth,
        $accumulationReturnRate,
        $sipAmount,
        $sipStepUp,
        $yearsToRetirement,
        $withdrawalRate,
        $inflationRate,
        $drawdownReturnRate,
        $horizonYears
    );

    $response['accumulation_series'] = $accumulationSeries;
    $response['lifecycle_series'] = $lifecycleSeries;

    // "How much will I need, and am I on track for it?" — see
    // PlanMath::retirementTarget(). The advisor product never needed this
    // (an advisor arrives with a target and models a corpus toward it), but
    // someone planning alone only knows what they spend today.
    //
    // Expenses come from cash_flow_items, the figure the person already
    // recorded — nothing here invents a cost of living, and the target is
    // simply absent when no expenses are on file.
    $monthlyExpenses = (float) summarizeCashFlowItems(
        $scopedDb->select('cash_flow_items', ['client_id' => (int) $goal['client_id']])
    )['monthly_expense'];

    // The projected corpus is read off the lifecycle series at the retirement
    // year — the same curve this response already returns, so the target and
    // the chart can never disagree.
    $projectedAtRetirement = $lifecycleSeries[$yearsToRetirement] ?? end($lifecycleSeries);

    $response['retirement_target'] = PlanMath::retirementTarget(
        $monthlyExpenses,
        $inflationRate,
        $withdrawalRate,
        $yearsToRetirement,
        (float) $projectedAtRetirement
    );

    // docs/11 Prompt E-1: "never pair a gap with silence" — name the smallest
    // lever that closes it whenever the target shows a real shortfall.
    // Absent (null) when there's no target, or the target is already met.
    $response['gap_closing_levers'] = $response['retirement_target'] !== null
        ? PlanMath::gapClosingLevers(
            $response['retirement_target'],
            $initialNetWorth,
            $accumulationReturnRate,
            $sipAmount,
            $sipStepUp,
            $yearsToRetirement,
            $monthlyExpenses,
            $inflationRate,
            $withdrawalRate
        )
        : null;
    $response['accumulation_assumptions'] = [
        'accumulation_return_rate' => $accumulationReturnRate,
        'current_age'              => $currentAge,
        'retirement_age'           => $retirementAge,
        'years_to_retirement'      => $yearsToRetirement,
        'monthly_sip_amount'       => $sipAmount,
        'sip_step_up_rate'         => $sipStepUp,
    ];
}

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
