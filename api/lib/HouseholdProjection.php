<?php
declare(strict_types=1);

/**
 * P0-1 · Household aggregate planning (docs/10).
 *
 * The household view is the *sum* of its members' independently-modelled
 * retirement goals — no new plan math, no shared-corpus modelling (docs/02
 * §4.1 guardrail: one member's corpus is never pooled into another's goal).
 * Each member goal's steady/adverse decumulation series is computed with the
 * exact same PlanMath call goals_projection.php's base case uses, then summed
 * element-wise by year. This makes the aggregate provably equal to summing the
 * per-goal projections — the invariant test_household_projection_db.php checks.
 *
 * Requires PlanMath.php and a TenantScopedDb (so member/goal reads stay tenant-
 * scoped and a household can never pull in another firm's client).
 */

require_once __DIR__ . '/PlanMath.php';

/**
 * Element-wise sum of a list of numeric series of possibly-different lengths.
 * The result length is the longest input; a series that has ended contributes
 * 0 to later years (that member's planning horizon is simply over). Trivially
 * reproducible by a caller summing the same per-series values — that's the
 * point (the aggregate == sum-of-members invariant).
 *
 * @param list<list<float>> $seriesList
 * @return list<float>
 */
function sumSeriesElementwise(array $seriesList): array
{
    $maxLen = 0;
    foreach ($seriesList as $series) {
        $maxLen = max($maxLen, count($series));
    }
    $out = array_fill(0, $maxLen, 0.0);
    foreach ($seriesList as $series) {
        foreach ($series as $i => $value) {
            $out[$i] += (float) $value;
        }
    }
    return $out;
}

/**
 * A single retirement goal's base steady/adverse decumulation series, or null
 * if the goal can't be projected (missing withdrawal/drawdown rate, or a
 * corpus split with no locked return). Mirrors goals_projection.php's base
 * case exactly — no sub-scenario overrides, no historical replay.
 *
 * @return array{0: list<float>, 1: list<float>}|null [steady, adverse]
 */
function goalBaseDecumulationSeries(array $goal): ?array
{
    $withdrawalRate = $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null;
    $drawdownReturnRate = $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null;
    if ($withdrawalRate === null || $drawdownReturnRate === null) {
        return null;
    }

    $liquidCorpusAmount = $goal['liquid_corpus_amount'] !== null ? (float) $goal['liquid_corpus_amount'] : null;
    $lockedCorpusAmount = $goal['locked_corpus_amount'] !== null ? (float) $goal['locked_corpus_amount'] : null;
    $lockedReturnRate = $goal['locked_return_rate'] !== null ? (float) $goal['locked_return_rate'] : null;
    if ($liquidCorpusAmount !== null && $lockedCorpusAmount !== null && $lockedReturnRate === null) {
        return null; // decomposed corpus with no locked return — can't project (same guard as goals_projection)
    }

    return PlanMath::decumulationSeriesForGoal(
        (float) $goal['initial_net_worth'],
        $withdrawalRate,
        (float) $goal['inflation_rate'],
        $drawdownReturnRate,
        (int) $goal['projection_horizon_years'],
        $liquidCorpusAmount,
        $lockedCorpusAmount,
        $lockedReturnRate
    );
}

/**
 * Build the full household aggregate for $householdId, or null if the household
 * isn't in this tenant. Sums every member client's projectable retirement goals.
 *
 * @return array{
 *   household: array{id:int, name:string},
 *   members: list<array{client_id:int, email:string, goals:list<array{goal_id:int, goal_label:string, initial_net_worth:float, readiness_score:?int}>}>,
 *   total_starting_corpus: float,
 *   household_steady_series: list<float>,
 *   household_adverse_series: list<float>,
 *   household_readiness_score: ?int
 * }|null
 */
function computeHouseholdAggregate(TenantScopedDb $scopedDb, int $householdId): ?array
{
    $households = $scopedDb->select('households', ['id' => $householdId]);
    if ($households === []) {
        return null; // not in this tenant (or doesn't exist) — caller 404s
    }
    $household = $households[0];

    $members = $scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']);

    $memberOut = [];
    $steadyList = [];
    $adverseList = [];
    $totalStartingCorpus = 0.0;

    foreach ($members as $member) {
        $goals = $scopedDb->select('base_plans', [
            'client_id' => (int) $member['id'],
            'goal_type' => 'retirement',
        ]);

        $goalOut = [];
        foreach ($goals as $goal) {
            $series = goalBaseDecumulationSeries($goal);
            $readiness = null;
            if ($series !== null) {
                [$steady, $adverse] = $series;
                $steadyList[] = $steady;
                $adverseList[] = $adverse;
                // Per-goal readiness uses the goal's own withdrawal rate (the
                // corpus-multiple term), same as goals_projection.php.
                $readiness = PlanMath::readinessScore((float) $goal['withdrawal_rate'], $steady, $adverse);
                $totalStartingCorpus += (float) $goal['initial_net_worth'];
            }
            $goalOut[] = [
                'goal_id'           => (int) $goal['id'],
                'goal_label'        => $goal['goal_label'],
                'initial_net_worth' => (float) $goal['initial_net_worth'],
                'readiness_score'   => $readiness,
            ];
        }

        $memberOut[] = [
            'client_id' => (int) $member['id'],
            'email'     => $member['email'],
            'goals'     => $goalOut,
        ];
    }

    $householdSteady = sumSeriesElementwise($steadyList);
    $householdAdverse = sumSeriesElementwise($adverseList);
    // No single household withdrawal rate exists, so the readiness score drops
    // the corpus-multiple term (pass null) and uses the pure survival blend —
    // an honest aggregate, not a fabricated blended rate.
    $householdReadiness = ($householdSteady === [] || $householdAdverse === [])
        ? null
        : PlanMath::readinessScore(null, $householdSteady, $householdAdverse);

    return [
        'household'                 => ['id' => (int) $household['id'], 'name' => $household['name']],
        'members'                   => $memberOut,
        'total_starting_corpus'     => $totalStartingCorpus,
        'household_steady_series'   => $householdSteady,
        'household_adverse_series'  => $householdAdverse,
        'household_readiness_score' => $householdReadiness,
    ];
}
