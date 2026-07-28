<?php
declare(strict_types=1);

/**
 * P0-1 · Household aggregate planning (docs/10). Verifies the core invariant
 * against a real database inside a rolled-back transaction:
 *
 *   the household aggregate series == the element-wise sum of each member
 *   goal's own projection, exactly (no new math, no shared-corpus modelling),
 *
 * plus: a non-member client's goal is excluded, tenant isolation holds (a
 * household is invisible to another tenant), and totals/readiness are sane.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/HouseholdProjection.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();
$db->beginTransaction();

// FK-ordered teardown (users before households before tenants).
foreach ([
    'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Firm A'), (2, 'Firm B')");
$db->exec("INSERT INTO households (id, tenant_id, name) VALUES (100, 1, 'Sharma family'), (200, 2, 'Other-firm family')");

// Two members of household 100, one non-member client, all in tenant 1; plus a
// tenant-2 client to prove isolation.
$mkClient = static function (PDO $db, int $tenant, string $email, ?int $household): int {
    $stmt = $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role, household_id) VALUES (:t, :e, 'hash', 'client', :h)");
    $stmt->execute([':t' => $tenant, ':e' => $email, ':h' => $household]);
    return (int) $db->lastInsertId();
};
$clientA = $mkClient($db, 1, 'a@example.invalid', 100);
$clientB = $mkClient($db, 1, 'b@example.invalid', 100);
$clientC = $mkClient($db, 1, 'c@example.invalid', null);   // NOT in the household
$clientD = $mkClient($db, 2, 'd@example.invalid', 200);    // other tenant

$mkGoal = static function (PDO $db, int $tenant, int $client, float $corpus, float $wd, float $dd, float $infl): int {
    $stmt = $db->prepare(
        "INSERT INTO base_plans (tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate, withdrawal_rate, drawdown_return_rate)
         VALUES (:t, :c, 'retirement', 'Retirement', :corpus, :infl, :wd, :dd)"
    );
    $stmt->execute([':t' => $tenant, ':c' => $client, ':corpus' => $corpus, ':infl' => $infl, ':wd' => $wd, ':dd' => $dd]);
    return (int) $db->lastInsertId();
};
// Distinct assumptions so the sum is non-trivial.
$mkGoal($db, 1, $clientA, 5000000.0, 3.5, 7.0, 6.0);
$mkGoal($db, 1, $clientB, 8000000.0, 4.0, 6.5, 5.0);
$mkGoal($db, 1, $clientC, 9000000.0, 5.0, 6.0, 6.0); // non-member — must be excluded

$scopedA = new TenantScopedDb($db, 1);
$scopedB = new TenantScopedDb($db, 2);

// Independently compute each member goal's series, exactly as the aggregate
// should sum them.
$goalA = $scopedA->select('base_plans', ['client_id' => $clientA])[0];
$goalB = $scopedA->select('base_plans', ['client_id' => $clientB])[0];
[$steadyA, $adverseA] = goalBaseDecumulationSeries($goalA);
[$steadyB, $adverseB] = goalBaseDecumulationSeries($goalB);
$expectedSteady = sumSeriesElementwise([$steadyA, $steadyB]);
$expectedAdverse = sumSeriesElementwise([$adverseA, $adverseB]);

$agg = computeHouseholdAggregate($scopedA, 100);

assertTrue($agg !== null, 'household 100 resolves within its own tenant');
assertTrue(count($agg['members']) === 2, 'exactly the two assigned members are in the household (non-member client C excluded)');
assertTrue($agg['household_steady_series'] === $expectedSteady, 'household steady series == element-wise sum of member goals\' steady series, exactly');
assertTrue($agg['household_adverse_series'] === $expectedAdverse, 'household adverse series == element-wise sum of member goals\' adverse series, exactly');
assertTrue(abs($agg['total_starting_corpus'] - 13000000.0) < 0.001, 'total starting corpus = 5,000,000 + 8,000,000 (C\'s 9,000,000 not counted)');
assertTrue(is_int($agg['household_readiness_score']) && $agg['household_readiness_score'] >= 0 && $agg['household_readiness_score'] <= 100, 'household readiness is an int in 0..100');

// The first year of the summed series is the two starting corpuses combined.
assertTrue(abs($agg['household_steady_series'][0] - 13000000.0) < 0.001, 'year-0 of the household steady series is the combined starting corpus');

// Tenant isolation: firm B cannot see firm A's household at all.
assertTrue(computeHouseholdAggregate($scopedB, 100) === null, "another tenant's TenantScopedDb cannot resolve household 100 — returns null");

// sumSeriesElementwise: uneven lengths → longest wins, short series contributes 0 to the tail.
$uneven = sumSeriesElementwise([[10.0, 20.0, 30.0], [1.0, 2.0]]);
assertTrue($uneven === [11.0, 22.0, 30.0], 'uneven-length series sum element-wise; a shorter (ended) series adds 0 to later years');
assertTrue(sumSeriesElementwise([]) === [], 'no series → empty aggregate');

$db->rollBack();
echo "All household projection tests passed.\n";
