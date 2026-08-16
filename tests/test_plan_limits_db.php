<?php
declare(strict_types=1);

/**
 * Billing / subscription plan-tier enforcement (docs/09 Session 8, sql/044,
 * api/lib/PlanLimits.php). Verified against a real database inside a
 * rolled-back transaction:
 *
 *   1. A tenant on a finite-seat plan is blocked once it reaches that seat
 *      count — the actual gating mechanism this session exists to build.
 *   2. A tenant on the Unlimited plan (max_advisors NULL) is never blocked,
 *      no matter how many advisors it already has.
 *   3. A tenant with NO plan_id at all (personal-kind tenants never get one —
 *      sql/044's decision record) is treated as unlimited too, not as a
 *      zero-seat lockout.
 *   4. subscription_plans seeds exactly the three tiers this migration
 *      defines, so a schema drift here fails loudly instead of silently
 *      changing what a "Starter" plan means.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/PlanLimits.php';

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
    'client_dependants', 'client_context', 'client_protection', 'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

// --- 0. the catalog this migration seeds ------------------------------------
$plans = [];
foreach ($db->query('SELECT code, max_advisors FROM subscription_plans ORDER BY code')->fetchAll() as $row) {
    $plans[$row['code']] = $row['max_advisors'] !== null ? (int) $row['max_advisors'] : null;
}
assertTrue($plans === ['growth' => 10, 'starter' => 3, 'unlimited' => null],
    'subscription_plans seeds exactly Starter(3) / Growth(10) / Unlimited(NULL) — sql/044');

$starterId   = (int) $db->query("SELECT id FROM subscription_plans WHERE code = 'starter'")->fetchColumn();
$unlimitedId = (int) $db->query("SELECT id FROM subscription_plans WHERE code = 'unlimited'")->fetchColumn();

$db->exec("INSERT INTO tenants (id, company_name, kind, advisory_mode, plan_id) VALUES
    (1, 'Starter Firm', 'firm', 'distribution', $starterId),
    (2, 'Unlimited Firm', 'firm', 'distribution', $unlimitedId),
    (3, 'No-plan Firm', 'firm', 'distribution', NULL),
    (4, 'Priya (personal)', 'personal', 'self_directed', NULL)");

$mkAdvisor = static function (PDO $db, int $tenant, string $email): int {
    $stmt = $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, 'h', 'advisor')");
    $stmt->execute([':t' => $tenant, ':e' => $email]);
    return (int) $db->lastInsertId();
};

// --- 1. Starter plan (max 3) ------------------------------------------------
$plan = tenantPlan($db, 1);
assertTrue($plan !== null && $plan['code'] === 'starter' && $plan['max_advisors'] === 3,
    'tenantPlan() reads back the Starter tenant\'s plan correctly');

try {
    assertAdvisorSeatAvailable($db, 1);
    assertTrue(true, 'Starter tenant with 0 advisors has room for the 1st');
} catch (PlanLimitExceededException $e) {
    assertTrue(false, 'Starter tenant with 0 advisors has room for the 1st');
}

$mkAdvisor($db, 1, 'a1@example.invalid');
$mkAdvisor($db, 1, 'a2@example.invalid');
$mkAdvisor($db, 1, 'a3@example.invalid');

$blocked = false;
$exceptionPlanCode = null;
try {
    assertAdvisorSeatAvailable($db, 1);
} catch (PlanLimitExceededException $e) {
    $blocked = true;
    $exceptionPlanCode = $e->planCode;
}
assertTrue($blocked === true, 'Starter tenant at 3/3 advisors is blocked from a 4th');
assertTrue($exceptionPlanCode === 'starter', 'the exception names the actual plan the tenant is on');

// --- 2. Unlimited plan (max_advisors NULL) ----------------------------------
for ($i = 1; $i <= 12; $i++) {
    $mkAdvisor($db, 2, "u$i@example.invalid");
}
try {
    assertAdvisorSeatAvailable($db, 2);
    assertTrue(true, 'Unlimited tenant is never blocked, even at 12 advisors');
} catch (PlanLimitExceededException $e) {
    assertTrue(false, 'Unlimited tenant is never blocked, even at 12 advisors');
}

// --- 3. No plan_id at all (defensive — never actually reachable for a real
// firm post-sql/044 backfill, but must fail open to unlimited, not lock out
// a tenant that somehow has no plan row) -------------------------------------
assertTrue(tenantPlan($db, 3) === null, 'a tenant with plan_id NULL has no plan row');
for ($i = 1; $i <= 5; $i++) {
    $mkAdvisor($db, 3, "n$i@example.invalid");
}
try {
    assertAdvisorSeatAvailable($db, 3);
    assertTrue(true, 'a tenant with no plan_id is treated as unlimited, not zero-seat');
} catch (PlanLimitExceededException $e) {
    assertTrue(false, 'a tenant with no plan_id is treated as unlimited, not zero-seat');
}

// --- 4. personal-kind tenant — same no-plan shape, documented explicitly ----
assertTrue(tenantPlan($db, 4) === null, 'a personal-kind tenant never has a plan_id (sql/044 decision record)');

$db->rollBack();
echo "All plan-limit assertions passed.\n";
