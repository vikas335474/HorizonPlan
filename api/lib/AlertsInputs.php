<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-4 · The DB-touching half of the alerts engine: assembles
 * one client's alert bundle (AlertsEngine.php's computeClientAlerts() input
 * shape) from base_plans, client_portfolio_items, plan_review_schedules and
 * FoundationsInputs.php. AlertsEngine.php itself stays pure — same split as
 * FoundationsInputs.php/FinancialFoundations.php and ProgressSnapshot.php's
 * own capture-vs-compute layering.
 *
 * Scoped to ONE client (a handful of goals), which is what makes computing a
 * retirement goal's `is_met` here affordable — clients_list.php's own
 * $goalsMetCount comment (docs/12 Prompt D-3) explains why the SAME
 * computation is deliberately skipped for every retirement goal across an
 * entire tenant on a book-wide read: that's a real cost multiplied by every
 * client in the firm. One client's own goals is a handful of calls, not a
 * tenant scan — the asymmetry that comment describes is exactly why
 * alerts_read.php (per-client) can afford what clients_list.php (tenant-wide)
 * cannot, and why the tenant-wide rollup (docs/12 principle 6's "cheap
 * signals" set) leaves goal_drift/foundations_gap out — see clients_list.php.
 */

require_once __DIR__ . '/PlanMath.php';
require_once __DIR__ . '/CashFlowSummary.php';
require_once __DIR__ . '/PersonalisationReference.php';
require_once __DIR__ . '/MfNavSync.php'; // attachNavFreshness()
require_once __DIR__ . '/FoundationsInputs.php';

/**
 * A retirement goal's is_met + progress_status, reusing the exact same
 * assumptions/inputs goals_projection.php uses for that goal's own detail
 * page (accumulation series → lifecycle series → retirementTarget(), with
 * the same city-tier/medical-cost personalisation lookup) — so an alert can
 * never disagree with what a click-through to the goal shows. Returns
 * is_met=null when the goal doesn't carry enough to project (missing rates
 * or ages) — same "nothing to say" convention as the pure engine.
 */
function retirementGoalMetState(TenantScopedDb $scopedDb, array $goal): ?bool
{
    $withdrawalRate = $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null;
    $drawdownReturnRate = $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null;
    $accumulationReturnRate = $goal['accumulation_return_rate'] !== null ? (float) $goal['accumulation_return_rate'] : null;
    $currentAge = $goal['current_age'] !== null ? (int) $goal['current_age'] : null;
    $retirementAge = $goal['retirement_age'] !== null ? (int) $goal['retirement_age'] : null;

    if ($withdrawalRate === null || $drawdownReturnRate === null || $accumulationReturnRate === null
        || $currentAge === null || $retirementAge === null) {
        return null; // this goal doesn't project a retirement target at all
    }

    $inflationRate = (float) $goal['inflation_rate'];
    $initialNetWorth = (float) $goal['initial_net_worth'];
    $horizonYears = (int) $goal['projection_horizon_years'];
    $sipAmount = $goal['monthly_sip_amount'] !== null ? (float) $goal['monthly_sip_amount'] : 0.0;
    $sipStepUp = $goal['sip_step_up_rate'] !== null ? (float) $goal['sip_step_up_rate'] : 0.0;
    $yearsToRetirement = $retirementAge - $currentAge;

    $lifecycleSeries = PlanMath::lifecycleSeries(
        $initialNetWorth, $accumulationReturnRate, $sipAmount, $sipStepUp, $yearsToRetirement,
        $withdrawalRate, $inflationRate, $drawdownReturnRate, $horizonYears
    );
    $projectedAtRetirement = (float) ($lifecycleSeries[$yearsToRetirement] ?? end($lifecycleSeries));

    $clientId = (int) $goal['client_id'];
    $monthlyExpenses = (float) summarizeCashFlowItems(
        $scopedDb->select('cash_flow_items', ['client_id' => $clientId])
    )['monthly_expense'];

    // Same household-shared city-tier / per-person medical-cost lookup as
    // goals_projection.php — see that file's own comment for the reasoning.
    $ownContextRows = $scopedDb->select('client_context', ['client_id' => $clientId]);
    $ownContext = $ownContextRows[0] ?? null;
    $cityTier = $ownContext['city_tier'] ?? null;
    $expenseMultiplierOverride = ($ownContext !== null && $ownContext['expense_multiplier'] !== null)
        ? (float) $ownContext['expense_multiplier']
        : null;
    if ($cityTier === null && $expenseMultiplierOverride === null) {
        $clientRow = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
        $householdId = ($clientRow !== [] && $clientRow[0]['household_id'] !== null)
            ? (int) $clientRow[0]['household_id']
            : 0;
        if ($householdId > 0) {
            foreach ($scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']) as $member) {
                if ((int) $member['id'] === $clientId) {
                    continue;
                }
                $partnerContext = $scopedDb->select('client_context', ['client_id' => (int) $member['id']]);
                if ($partnerContext !== [] && ($partnerContext[0]['city_tier'] !== null || $partnerContext[0]['expense_multiplier'] !== null)) {
                    $cityTier = $partnerContext[0]['city_tier'];
                    $expenseMultiplierOverride = $partnerContext[0]['expense_multiplier'] !== null
                        ? (float) $partnerContext[0]['expense_multiplier']
                        : null;
                    break;
                }
            }
        }
    }
    $cityMultiplierInfo = PersonalisationReference::cityTierMultiplier($cityTier, $expenseMultiplierOverride);
    $monthlyMedicalCost = ($ownContext !== null && $ownContext['monthly_medical_cost'] !== null)
        ? (float) $ownContext['monthly_medical_cost']
        : 0.0;

    $target = PlanMath::retirementTarget(
        $monthlyExpenses, $inflationRate, $withdrawalRate, $yearsToRetirement,
        $projectedAtRetirement, $monthlyMedicalCost, (float) $cityMultiplierInfo['multiplier']
    );

    return $target['is_met'] ?? null;
}

/**
 * One goal's {id, goal_label, is_met, progress_status} for the alerts
 * bundle. Target-based goals (education/home_purchase/other) use
 * PlanMath::targetGoalFunding() directly off base_plans fields — cheap, the
 * same call clients_list.php's book-wide $goalsMetCount already makes.
 * Retirement goals go through retirementGoalMetState() above.
 *
 * progress_status (drift vs. the plan's own expected curve) comes from the
 * latest goal_snapshots row, same read goal_progress_read.php does for a
 * single goal's own page — null when there's no snapshot yet to compare.
 */
function goalAlertSummary(TenantScopedDb $scopedDb, array $goal): array
{
    $goalId = (int) $goal['id'];
    $isTargetBased = $goal['target_amount'] !== null && $goal['target_date'] !== null;

    if ($isTargetBased) {
        $funding = PlanMath::targetGoalFunding(
            (float) $goal['target_amount'],
            $goal['target_date'],
            (float) $goal['initial_net_worth'],
            (float) $goal['inflation_rate']
        );
        $isMet = $funding['is_met'] ?? null;
    } elseif ($goal['goal_type'] === 'retirement') {
        $isMet = retirementGoalMetState($scopedDb, $goal);
    } else {
        $isMet = null;
    }

    $snapshots = $scopedDb->select('goal_snapshots', ['goal_id' => $goalId]);
    usort($snapshots, static fn(array $a, array $b) => strcmp((string) $a['as_of_date'], (string) $b['as_of_date']));
    $progressStatus = null;
    if ($snapshots !== []) {
        $last = $snapshots[count($snapshots) - 1];
        $progressStatus = PlanMath::progressStatus(
            (float) $last['corpus_value'],
            $last['expected_value'] !== null ? (float) $last['expected_value'] : null
        );
    }

    return [
        'id'              => $goalId,
        'goal_label'      => (string) $goal['goal_label'],
        'is_met'          => $isMet,
        'progress_status' => $progressStatus,
    ];
}

/**
 * Assembles computeClientAlerts()'s full input bundle for one client.
 * Tenant-scoped throughout via $scopedDb; $db is passed only for
 * attachNavFreshness(), whose mf_nav_cache lookup is deliberately NOT
 * tenant-scoped — see that function's own header (NAV prices aren't
 * tenant-owned data, same reasoning as market_history).
 */
function gatherClientAlertBundle(TenantScopedDb $scopedDb, PDO $db, int $clientId): array
{
    $clientRows = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
    $clientLabel = $clientRows !== [] ? (string) ($clientRows[0]['display_name'] ?? $clientRows[0]['email']) : 'This client';

    $goals = array_map(
        static fn(array $goal) => goalAlertSummary($scopedDb, $goal),
        $scopedDb->select('base_plans', ['client_id' => $clientId])
    );

    $portfolioRows = $scopedDb->select('client_portfolio_items', ['client_id' => $clientId]);
    $navTracked = array_values(array_filter(
        $portfolioRows,
        static fn(array $r) => $r['amfi_scheme_code'] !== null && $r['units_held'] !== null
    ));
    $enrichedNav = attachNavFreshness($db, $navTracked)['rows'];
    $navBundle = array_map(static fn(array $r) => [
        'id'             => (int) $r['id'],
        'description'    => (string) $r['description'],
        'nav_fetched_at' => $r['nav_fetched_at'] ?? null,
    ], $enrichedNav);

    $scheduleRows = $scopedDb->select('plan_review_schedules', ['client_id' => $clientId]);
    $reviewSchedule = $scheduleRows !== []
        ? ['cadence' => (string) $scheduleRows[0]['cadence'], 'last_sent_at' => $scheduleRows[0]['last_sent_at']]
        : null;

    return [
        'client_id'         => $clientId,
        'client_label'      => $clientLabel,
        'goals'             => $goals,
        'nav_tracked_items' => $navBundle,
        'review_schedule'   => $reviewSchedule,
        'foundations'       => foundationsSummaryForClient($scopedDb, $clientId)['foundations'],
    ];
}
