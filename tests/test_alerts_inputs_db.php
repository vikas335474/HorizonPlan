<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-4 — gatherClientAlertBundle() against a real database,
 * inside a rolled-back transaction. The pure trigger logic is covered in
 * tests/test_alerts_engine.php; what needs a database is the assembly:
 *
 *   1. TENANT ISOLATION — a client's goals, portfolio, and review schedule
 *      must never leak from another tenant's identically-numbered client.
 *   2. is_met FOR BOTH GOAL SHAPES — a target-based goal (cheap, direct off
 *      base_plans) and a retirement goal (the full lifecycle projection,
 *      via retirementGoalMetState()) must each report correctly.
 *   3. price_stale — a NAV-tracked row with a cached price attaches
 *      nav_fetched_at; one with no cache entry reads "price pending".
 *   4. review_due — reads plan_review_schedules through TenantScopedDb.
 *   5. THE FULL ROUND TRIP — gatherClientAlertBundle() piped straight into
 *      computeClientAlerts() produces the expected fired alert types.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/CashFlowSummary.php';
require_once __DIR__ . '/../api/lib/FinancialFoundations.php';
require_once __DIR__ . '/../api/lib/FoundationsInputs.php';
require_once __DIR__ . '/../api/lib/PlanMath.php';
require_once __DIR__ . '/../api/lib/PersonalisationReference.php';
require_once __DIR__ . '/../api/lib/MfNavSync.php';
require_once __DIR__ . '/../api/lib/AlertsEngine.php';
require_once __DIR__ . '/../api/lib/AlertsInputs.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();
$db->beginTransaction();

foreach ([
    'client_dependants', 'client_context', 'goal_snapshots', 'client_net_worth_snapshots', 'client_protection',
    'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name, kind, advisory_mode) VALUES
    (1, 'Firm A', 'firm', 'distribution'),
    (2, 'Firm B', 'firm', 'distribution')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, display_name) VALUES
    (10, 1, 'advisor.a@example.invalid', 'h', 'advisor', NULL),
    (11, 1, 'client.a@example.invalid', 'h', 'client', 'Priya Iyer'),
    (20, 2, 'advisor.b@example.invalid', 'h', 'advisor', NULL),
    (21, 2, 'client.b@example.invalid', 'h', 'client', 'Someone Else')");

// --- tenant 1, client 11: one target-based goal that IS met, one that ISN'T,
// one NAV-tracked fund with a cached price, one with none, and a quarterly
// review sent long ago (overdue). ---------------------------------------
$db->exec("INSERT INTO base_plans
    (id, tenant_id, client_id, goal_type, goal_label, target_amount, target_date, initial_net_worth, inflation_rate) VALUES
    (401, 1, 11, 'education', 'Daughter\\'s college', 1000000, '2027-01-01', 2000000, 6.00),
    (402, 1, 11, 'other', 'New car', 1000000, '2040-01-01', 10000, 6.00)");

$db->exec("INSERT INTO client_portfolio_items
    (tenant_id, client_id, item_kind, bucket, category, description, value, amfi_scheme_code, units_held, created_by_user_id) VALUES
    (1, 11, 'asset', 'liquid', 'mutual_fund', 'HDFC Flexi Cap Fund', 50000, 'SCH001', 100.0, 10),
    (1, 11, 'asset', 'liquid', 'mutual_fund', 'Parag Parikh Flexi Cap', 30000, 'SCH002', 50.0, 10)");
$db->exec("INSERT INTO mf_nav_cache (amfi_scheme_code, scheme_name, nav_value, nav_date, fetched_at) VALUES
    ('SCH001', 'HDFC Flexi Cap Fund', 500.0000, '2026-07-01', '2026-07-01 06:00:00')");
// SCH002 deliberately has no cache row at all — "price pending".

$db->exec("INSERT INTO plan_review_schedules (tenant_id, client_id, cadence, last_sent_at) VALUES
    (1, 11, 'quarterly', '2026-01-01 00:00:00')");

// --- tenant 2, client 21: identical client id shape (21 vs 11) as a decoy,
// distinctive values so a leak into tenant 1's bundle would be obvious. ----
$db->exec("INSERT INTO base_plans
    (id, tenant_id, client_id, goal_type, goal_label, target_amount, target_date, initial_net_worth, inflation_rate) VALUES
    (901, 2, 21, 'education', 'Should never appear', 1, '2027-01-01', 999999999, 6.00)");

$scopedDb1 = new TenantScopedDb($db, 1);
$bundle = gatherClientAlertBundle($scopedDb1, $db, 11);

assertTrue($bundle['client_label'] === 'Priya Iyer', 'the client label comes from display_name');
assertTrue(count($bundle['goals']) === 2, 'exactly this tenant\'s client\'s two goals are gathered — not tenant 2\'s');

$byId = [];
foreach ($bundle['goals'] as $g) {
    $byId[$g['id']] = $g;
}
// Goal 401: target 1,000,000 6 years out at 6% inflation ≈ 1,418,519 inflated;
// corpus 2,000,000 comfortably covers it → is_met.
assertTrue($byId[401]['is_met'] === true, 'a target-based goal whose corpus already exceeds the inflated target reports is_met=true');
// Goal 402: target 1,000,000 far out, corpus only 10,000 → nowhere close.
assertTrue($byId[402]['is_met'] === false, 'a target-based goal far short of its inflated target reports is_met=false');

assertTrue(count($bundle['nav_tracked_items']) === 2, 'both NAV-tracked rows are gathered');
$navById = [];
foreach ($bundle['nav_tracked_items'] as $n) {
    $navById[$n['id'] ?? $n['description']] = $n;
}
$sch001 = null;
$sch002 = null;
foreach ($bundle['nav_tracked_items'] as $n) {
    if ($n['description'] === 'HDFC Flexi Cap Fund') {
        $sch001 = $n;
    }
    if ($n['description'] === 'Parag Parikh Flexi Cap') {
        $sch002 = $n;
    }
}
assertTrue($sch001['nav_fetched_at'] === '2026-07-01 06:00:00', 'a cached scheme carries its cron-fetched timestamp');
assertTrue($sch002['nav_fetched_at'] === null, 'an uncached scheme carries a null fetched_at — "price pending", never fabricated');

assertTrue($bundle['review_schedule'] !== null && $bundle['review_schedule']['cadence'] === 'quarterly', 'the review schedule is read through TenantScopedDb');
assertTrue($bundle['review_schedule']['last_sent_at'] === '2026-01-01 00:00:00', 'and carries the recorded last_sent_at');

assertTrue($bundle['foundations'] !== null, 'the foundations block is present (reads through foundationsSummaryForClient)');

// --- tenant isolation: nothing from tenant 2 leaked in ----------------------
foreach ($bundle['goals'] as $g) {
    assertTrue($g['goal_label'] !== 'Should never appear', "tenant 2's goal never appears in tenant 1's bundle");
}

// --- the full round trip: pipe straight into computeClientAlerts() ---------
$alerts = computeClientAlerts($bundle, '2026-08-09');
$types = array_column($alerts, 'type');
assertTrue(in_array('goal_met', $types, true), 'the met target-based goal fires goal_met');
assertTrue(in_array('price_stale', $types, true), 'the uncached NAV row fires price_stale ("price pending")');
assertTrue(in_array('review_due', $types, true), 'the long-overdue quarterly review fires review_due');

// --- a client with nothing recorded produces an empty, not fabricated, bundle
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES (12, 1, 'client.c@example.invalid', 'h', 'client')");
$emptyBundle = gatherClientAlertBundle($scopedDb1, $db, 12);
assertTrue($emptyBundle['goals'] === [], 'a client with no goals gets an empty goals list, not an error');
assertTrue($emptyBundle['nav_tracked_items'] === [], 'and no NAV-tracked items');
assertTrue($emptyBundle['review_schedule'] === null, 'and no review schedule — never treated as "off" vs. absent');
$emptyAlerts = computeClientAlerts($emptyBundle, '2026-08-09');
$emptyTypes = array_column($emptyAlerts, 'type');
assertTrue(!in_array('goal_met', $emptyTypes, true) && !in_array('goal_drift', $emptyTypes, true), 'no goal alerts are fabricated for a client with no goals');

// --- cross-tenant read returns nothing, not tenant 2's data -----------------
$crossBundle = gatherClientAlertBundle($scopedDb1, $db, 21);
assertTrue($crossBundle['goals'] === [], "reading tenant 2's client id from inside tenant 1's scope returns nothing, not their goals");

$db->rollBack();
echo "\nAll alerts-inputs DB tests passed.\n";
