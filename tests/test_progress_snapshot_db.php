<?php
declare(strict_types=1);

/**
 * P1-1 · Goal-progress snapshot capture, against a real database inside a
 * rolled-back transaction. Verifies:
 *
 *   1. A capture writes one row per goal plus one client net-worth row.
 *   2. It is IDEMPOTENT PER DAY — the monthly cron and a manual "Record now"
 *      landing on the same date converge on one row, not two (the property the
 *      unique keys in migration 032 exist to guarantee).
 *   3. Tenant isolation — capturing for firm A never touches firm B's goals,
 *      and firm B cannot read firm A's snapshots.
 *   4. A projectable goal stores an expected_value; a target-based goal stores
 *      funding_covered_pct and a NULL expected_value instead of a fabricated
 *      curve.
 *   5. Net worth is assets - liabilities from the portfolio ledger.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/ProgressSnapshot.php';

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
    'goal_snapshots', 'client_net_worth_snapshots',
    'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Firm A'), (2, 'Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (10, 1, 'adv.a@example.invalid', 'h', 'advisor'),
    (11, 1, 'client.a@example.invalid', 'h', 'client'),
    (20, 2, 'client.b@example.invalid', 'h', 'client')");

// A projectable retirement goal, created two years ago so elapsed time > 0.
$twoYearsAgo = date('Y-m-d H:i:s', strtotime('-2 years'));
$stmt = $db->prepare(
    "INSERT INTO base_plans
        (id, tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate,
         withdrawal_rate, drawdown_return_rate, projection_horizon_years, created_at)
     VALUES (:id, :t, :c, 'retirement', 'Retirement', 10000000, 6, 4, 8, 30, :created)"
);
$stmt->execute([':id' => 100, ':t' => 1, ':c' => 11, ':created' => $twoYearsAgo]);

// A target-based goal — no rates at all, so no expected curve is derivable.
$stmt = $db->prepare(
    "INSERT INTO base_plans
        (id, tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate,
         target_amount, target_date, projection_horizon_years, created_at)
     VALUES (:id, :t, :c, 'education', 'College', 300000, 6, 2000000, :td, 30, :created)"
);
$stmt->execute([':id' => 101, ':t' => 1, ':c' => 11, ':td' => date('Y-m-d', strtotime('+8 years')), ':created' => $twoYearsAgo]);

// Firm B's goal — must never be touched by a firm A capture.
$stmt = $db->prepare(
    "INSERT INTO base_plans
        (id, tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate,
         withdrawal_rate, drawdown_return_rate, projection_horizon_years, created_at)
     VALUES (200, 2, 20, 'retirement', 'Other firm', 5000000, 6, 4, 8, 30, :created)"
);
$stmt->execute([':created' => $twoYearsAgo]);

// Portfolio: 800,000 assets (two rows) minus a 300,000 liability = 500,000.
$db->exec("INSERT INTO client_portfolio_items (tenant_id, client_id, item_kind, bucket, category, value, created_by_user_id) VALUES
    (1, 11, 'asset', 'liquid', 'mutual_fund', 500000, 10),
    (1, 11, 'asset', 'locked', 'epf', 300000, 10),
    (1, 11, 'liability', NULL, 'home_loan', 300000, 10)");

$scopedA = new TenantScopedDb($db, 1);
$scopedB = new TenantScopedDb($db, 2);
$asOf = date('Y-m-d');

// --- 1. a capture writes the expected rows -------------------------------
$result = captureClientProgress($scopedA, $db, 1, 11, 10, $asOf);
assertTrue($result['goals_captured'] === 2, 'a capture snapshots every goal the client has');
assertTrue($result['net_worth_captured'] === true, 'a capture also records the client net worth');
assertTrue(count($scopedA->select('goal_snapshots', ['client_id' => 11])) === 2, 'two goal_snapshots rows exist after one capture');

// --- 2. idempotent per day -------------------------------------------------
captureClientProgress($scopedA, $db, 1, 11, 10, $asOf);
captureClientProgress($scopedA, $db, 1, 11, null, $asOf); // as the cron would
assertTrue(
    count($scopedA->select('goal_snapshots', ['client_id' => 11])) === 2,
    'repeat captures on the same date update in place — one row per goal per day, never duplicates'
);
assertTrue(
    count($scopedA->select('client_net_worth_snapshots', ['client_id' => 11])) === 1,
    'the same day-idempotency holds for the net-worth series'
);

// --- 3. projectable vs target-based ---------------------------------------
$retirement = $scopedA->select('goal_snapshots', ['goal_id' => 100])[0];
assertTrue($retirement['expected_value'] !== null, 'a projectable goal records what the plan expected on that date');
assertTrue($retirement['funding_covered_pct'] === null, 'a projectable goal records no funding coverage — that is the other tracking mode');
assertTrue((float) $retirement['corpus_value'] === 10000000.0, 'the snapshot records the goal\'s actual corpus');
// Two years into a 4%-withdrawal / 8%-return plan the corpus is modelled to
// still be growing, so the expectation must exceed the starting corpus.
assertTrue((float) $retirement['expected_value'] > 10000000.0, 'the expectation reflects two years of the plan, not the starting corpus');

$education = $scopedA->select('goal_snapshots', ['goal_id' => 101])[0];
assertTrue($education['expected_value'] === null, 'a target-based goal stores NULL expected_value — no return assumption exists, so none is invented');
assertTrue($education['funding_covered_pct'] !== null, 'a target-based goal stores funding coverage instead');
assertTrue((float) $education['funding_covered_pct'] > 0.0 && (float) $education['funding_covered_pct'] < 100.0, 'coverage is a sane percentage');

// --- 4. net worth ----------------------------------------------------------
$nw = $scopedA->select('client_net_worth_snapshots', ['client_id' => 11])[0];
assertTrue((float) $nw['assets_total'] === 800000.0, 'assets are summed across liquid and locked');
assertTrue((float) $nw['liabilities_total'] === 300000.0, 'liabilities are summed separately');
assertTrue((float) $nw['net_worth'] === 500000.0, 'net worth is assets minus liabilities');

// --- 5. tenant isolation ---------------------------------------------------
assertTrue(
    $scopedA->select('goal_snapshots', ['goal_id' => 200]) === [],
    'firm A\'s capture never wrote a snapshot for firm B\'s goal'
);
assertTrue(
    $scopedB->select('goal_snapshots', ['client_id' => 11]) === [],
    'firm B cannot read firm A\'s snapshots — the series is tenant-scoped like every other table'
);
// Capturing for a client that isn't in this tenant finds nothing to snapshot.
$cross = captureClientProgress($scopedB, $db, 2, 11, null, $asOf);
assertTrue($cross['goals_captured'] === 0, 'capturing another tenant\'s client snapshots none of their goals');

// --- 6. created_by distinguishes cron from person --------------------------
$db->exec("DELETE FROM goal_snapshots");
captureClientProgress($scopedA, $db, 1, 11, null, $asOf);
$auto = $scopedA->select('goal_snapshots', ['goal_id' => 100])[0];
assertTrue($auto['created_by_user_id'] === null, 'a cron-written snapshot has no user attached, which is how the UI labels it automatic');

$db->rollBack();

echo "\nAll progress-snapshot tests passed.\n";
