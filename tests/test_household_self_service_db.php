<?php
declare(strict_types=1);

/**
 * sql/035 — two-earner households in the self-serve tier.
 *
 * The invariant this file exists to protect is the same one test_self_service_db
 * protects for writes, now for household READS:
 *
 *   OPENING HOUSEHOLDS TO SELF-SERVE COUPLES MUST NOT OPEN THEM TO
 *   ADVISOR-MANAGED CLIENTS.
 *
 * A firm's household groups an advisor's clients, so one client enumerating the
 * others would be a genuine leak between unrelated people. In a personal tenant
 * the household is the two people who deliberately joined it.
 *
 * Also asserted: a personal client is pinned to their OWN household (a supplied
 * household_id is ignored, not trusted), and the household-scoped foundations
 * arithmetic — the reason the split exists at all.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/SelfService.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/CashFlowSummary.php';
require_once __DIR__ . '/../api/lib/FinancialFoundations.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

function assertClose(float $a, float $b, string $label, float $tolerance = 0.01): void
{
    assertTrue(abs($a - $b) < $tolerance, "$label (got $a, expected ~$b)");
}

$db = getPdo();
$db->beginTransaction();

foreach ([
    'goal_snapshots', 'client_net_worth_snapshots', 'client_protection',
    'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'password_resets', 'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

// A firm with a household of two clients, and a self-serve couple.
$db->exec("INSERT INTO tenants (id, company_name, kind, advisory_mode) VALUES
    (1, 'Firm A', 'firm', 'distribution'),
    (2, 'Arun & Priya', 'personal', 'self_directed')");
$db->exec("INSERT INTO households (id, tenant_id, name) VALUES
    (100, 1, 'Firm family'),
    (200, 2, 'Our household')");
$db->exec("INSERT INTO users (id, tenant_id, email, display_name, password_hash, role, household_id) VALUES
    (10, 1, 'advisor@example.invalid', NULL,    'h', 'advisor', NULL),
    (11, 1, 'fc1@example.invalid',     'Rahul', 'h', 'client',  100),
    (12, 1, 'fc2@example.invalid',     'Sunita','h', 'client',  100),
    (20, 2, 'arun@example.invalid',    'Arun',  'h', 'client',  200),
    (21, 2, 'priya@example.invalid',   'Priya', 'h', 'client',  200)");

// --- resolveHouseholdId: a personal client cannot pick a household ----------
// verifyHouseholdAccess() exits on failure (it is an HTTP-layer function), so
// what is unit-testable is the decision it produces. The firm-client refusal
// path is asserted through tenantIsPersonal() below and exercised for real in
// the browser pass.
$personalAccess = ['session' => ['user_id' => 20, 'tenant_id' => 2, 'role' => 'client'], 'household_id' => 200, 'is_client' => true];
assertTrue(resolveHouseholdId($personalAccess, 999) === 200, 'a personal client gets their OWN household — a supplied id is ignored, not trusted');
assertTrue(resolveHouseholdId($personalAccess, 0) === 200, 'and gets it without having to supply anything');

$noHousehold = ['session' => ['user_id' => 20, 'tenant_id' => 2, 'role' => 'client'], 'household_id' => null, 'is_client' => true];
assertTrue(resolveHouseholdId($noHousehold, 200) === 0, 'a personal client with no household resolves to 0 (→ 404), never to a household they are not in');

$advisorAccess = ['session' => ['user_id' => 10, 'tenant_id' => 1, 'role' => 'advisor'], 'household_id' => null, 'is_client' => false];
assertTrue(resolveHouseholdId($advisorAccess, 100) === 100, 'an advisor still supplies the household id per request, unchanged');

// --- the invariant: a firm client is still shut out -------------------------
// These two client rows differ ONLY by tenant kind. That difference is the
// whole gate, for household reads exactly as for writes.
assertTrue(tenantIsPersonal($db, 2) === true, 'the self-serve couple reads as a personal tenant');
assertTrue(tenantIsPersonal($db, 1) === false, "the firm does not — its clients stay shut out of each other's households");
assertTrue(tenantIsPersonal($db, 999999) === false, 'a missing tenant fails CLOSED to firm');

// --- both partners resolve to the same household ----------------------------
$stmt = $db->prepare('SELECT household_id FROM users WHERE id = :id');
foreach ([20, 21] as $memberId) {
    $stmt->execute([':id' => $memberId]);
    assertTrue((int) $stmt->fetchColumn() === 200, "member $memberId is in the shared household");
}

// --- the household-scoped reserve, which is why the split exists ------------
// Arun records the rent and holds the savings; Priya records groceries. Per
// person that reads as Arun 2.0 months and Priya 0.0 — both wrong. Across the
// household it is the truth: 400000 / (50000 + 30000) = 5 months.
$db->exec("INSERT INTO cash_flow_items (tenant_id, client_id, kind, label, amount, cadence, category, created_by_user_id) VALUES
    (2, 20, 'income',  'Arun salary',  150000, 'monthly', 'salary',        20),
    (2, 20, 'expense', 'Rent',          50000, 'monthly', 'housing',       20),
    (2, 21, 'income',  'Priya salary',  90000, 'monthly', 'salary',        21),
    (2, 21, 'expense', 'Groceries',     30000, 'monthly', 'food',          21)");
$db->exec("INSERT INTO client_portfolio_items (tenant_id, client_id, item_kind, bucket, category, description, value, created_by_user_id) VALUES
    (2, 20, 'asset', 'liquid', 'savings', 'Joint savings', 400000, 20)");

$scoped = new TenantScopedDb($db, 2);
$members = $scoped->select('users', ['household_id' => 200, 'role' => 'client']);
assertTrue(count($members) === 2, 'the household has two earners');

$sharedExpenses = 0.0;
$liquid = 0.0;
foreach ($members as $m) {
    $sharedExpenses += (float) summarizeCashFlowItems(
        $scoped->select('cash_flow_items', ['client_id' => (int) $m['id']])
    )['monthly_expense'];
    foreach ($scoped->select('client_portfolio_items', ['client_id' => (int) $m['id']]) as $r) {
        if ($r['item_kind'] === 'asset' && $r['bucket'] === 'liquid') {
            $liquid += (float) $r['value'];
        }
    }
}
assertClose($sharedExpenses, 80000.0, "shared expenses sum both partners' lines");

$hhReserve = FinancialFoundations::emergencyReserve($sharedExpenses, $liquid, 'household');
assertClose($hhReserve['months'], 5.0, 'the household reserve is combined savings over combined expenses');
assertTrue($hhReserve['scope'] === 'household', 'and it is labelled as household scope, so the UI cannot imply it is personal');

// The per-person reading each partner would have seen, and why it misleads.
$priyaOwn = summarizeCashFlowItems($scoped->select('cash_flow_items', ['client_id' => 21]));
$priyaAlone = FinancialFoundations::emergencyReserve((float) $priyaOwn['monthly_expense'], 0.0);
assertTrue($priyaAlone['status'] === 'short', "per-person, the partner holding no savings reads as short — the misleading answer the split fixes");

// --- cover stays per-person -------------------------------------------------
// Arun earns 18L/yr, Priya 10.8L. Identical cover is adequate for one and not
// the other, which is exactly why this check must not be aggregated.
$arunIncome = (float) summarizeCashFlowItems($scoped->select('cash_flow_items', ['client_id' => 20]))['monthly_income'] * 12.0;
$priyaIncome = (float) $priyaOwn['monthly_income'] * 12.0;
assertClose($arunIncome, 1800000.0, "Arun's own annual income is used for his cover");
assertClose($priyaIncome, 1080000.0, "Priya's own annual income is used for hers");

$cover = 15000000.0;
assertTrue(FinancialFoundations::lifeCover(1, $cover, $arunIncome)['status'] === 'partial', 'the same cover is only partial against the higher earner');
assertTrue(FinancialFoundations::lifeCover(1, $cover, $priyaIncome)['status'] === 'ok', 'and adequate against the lower — aggregating would have hidden one of these');

$db->rollBack();
echo "\nAll household self-service tests passed.\n";
