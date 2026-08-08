<?php
declare(strict_types=1);

/**
 * Cash-flow module (docs/10). Verifies against a real database inside a
 * rolled-back transaction:
 *
 *   - monthly/annual normalisation (annual ÷ 12) into a like-for-like figure,
 *   - surplus == income − expense, exactly (to the paise),
 *   - inactive line items are excluded from the totals,
 *   - the SIP comparison (surplus vs. total goal monthly SIPs) and its gap,
 *   - tenant isolation — another tenant's TenantScopedDb cannot read a client's
 *     cash flow, cannot total their SIPs, and cannot resolve their household,
 *   - the household roll-up == the sum of members' own statements.
 *
 * Pure-arithmetic paths (summarizeCashFlowItems, cashFlowItemMonthly,
 * cashFlowSipComparison) are also exercised directly, no DB needed for those.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/CashFlowSummary.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// ---- Pure paths first (no DB) ------------------------------------------------

assertTrue(cashFlowItemMonthly(['amount' => 1200.0, 'cadence' => 'annual']) === 100.0,
    'an annual line normalises to amount ÷ 12');
assertTrue(cashFlowItemMonthly(['amount' => 5000.0, 'cadence' => 'monthly']) === 5000.0,
    'a monthly line is taken as-is');
assertTrue(cashFlowItemMonthly(['amount' => 5000.0]) === 5000.0,
    'a line with no cadence defaults to monthly');

$pureItems = [
    ['kind' => 'income',  'amount' => 100000.0, 'cadence' => 'monthly', 'is_active' => 1], // 100000
    ['kind' => 'income',  'amount' => 240000.0, 'cadence' => 'annual',  'is_active' => 1], // 20000
    ['kind' => 'expense', 'amount' => 40000.0,  'cadence' => 'monthly', 'is_active' => 1], // 40000
    ['kind' => 'expense', 'amount' => 60000.0,  'cadence' => 'annual',  'is_active' => 1], // 5000
    ['kind' => 'expense', 'amount' => 99999.0,  'cadence' => 'monthly', 'is_active' => 0], // excluded
];
$pure = summarizeCashFlowItems($pureItems);
assertTrue($pure['monthly_income'] === 120000.0, 'pure: monthly income = 100000 + (240000/12)=20000');
assertTrue($pure['monthly_expense'] === 45000.0, 'pure: monthly expense = 40000 + (60000/12)=5000, inactive line excluded');
assertTrue($pure['monthly_surplus'] === 75000.0, 'pure: surplus = income − expense, exactly');

$cmp = cashFlowSipComparison(75000.0, 50000.0);
assertTrue($cmp['total_monthly_sip'] === 50000.0 && $cmp['gap'] === 25000.0 && $cmp['funded'] === true,
    'pure: SIP comparison — surplus 75000 covers 50000 of SIPs, gap 25000, funded');
$cmp2 = cashFlowSipComparison(30000.0, 50000.0);
assertTrue($cmp2['gap'] === -20000.0 && $cmp2['funded'] === false,
    'pure: SIP comparison — surplus 30000 under 50000 of SIPs, gap −20000, not funded');

assertTrue(summarizeCashFlowItems([]) === ['monthly_income' => 0.0, 'monthly_expense' => 0.0, 'monthly_surplus' => 0.0],
    'pure: empty statement is all zeros');

// ---- DB paths ----------------------------------------------------------------

$db = getPdo();
$db->beginTransaction();

// FK-ordered teardown (copied from test_household_projection_db.php + cash_flow_items).
foreach ([
    'client_protection', 'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache',
    'risk_profiles', 'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios',
    'base_plans', 'template_customizations', 'template_strategies', 'active_sessions',
    'login_attempts', 'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Firm A'), (2, 'Firm B')");
$db->exec("INSERT INTO households (id, tenant_id, name) VALUES (100, 1, 'Sharma family')");

$mkClient = static function (PDO $db, int $tenant, string $email, ?int $household): int {
    $stmt = $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role, household_id) VALUES (:t, :e, 'hash', 'client', :h)");
    $stmt->execute([':t' => $tenant, ':e' => $email, ':h' => $household]);
    return (int) $db->lastInsertId();
};
$clientA = $mkClient($db, 1, 'a@example.invalid', 100); // household member
$clientB = $mkClient($db, 1, 'b@example.invalid', 100); // household member
$clientZ = $mkClient($db, 2, 'z@example.invalid', null); // other tenant

$scopedA = new TenantScopedDb($db, 1);
$scopedB = new TenantScopedDb($db, 2);

// Client A cash flow: salary 100000/mo income, 240000/yr bonus income (20000/mo),
// 40000/mo expenses, 60000/yr insurance (5000/mo), one parked (inactive) line.
$mkItem = static function (TenantScopedDb $s, int $client, string $kind, float $amt, string $cadence, int $active) {
    $s->insert('cash_flow_items', [
        'client_id' => $client, 'kind' => $kind, 'label' => ucfirst($kind),
        'amount' => $amt, 'cadence' => $cadence, 'category' => 'test', 'is_active' => $active,
        'created_by_user_id' => $client,
    ]);
};
$mkItem($scopedA, $clientA, 'income',  100000.0, 'monthly', 1);
$mkItem($scopedA, $clientA, 'income',  240000.0, 'annual',  1);
$mkItem($scopedA, $clientA, 'expense', 40000.0,  'monthly', 1);
$mkItem($scopedA, $clientA, 'expense', 60000.0,  'annual',  1);
$mkItem($scopedA, $clientA, 'expense', 99999.0,  'monthly', 0); // parked, excluded

$rowsA = $scopedA->select('cash_flow_items', ['client_id' => $clientA]);
$sumA = summarizeCashFlowItems($rowsA);
assertTrue(count($rowsA) === 5, 'all five of client A\'s rows are stored (inactive kept, just not summed)');
assertTrue($sumA['monthly_income'] === 120000.0, 'DB: client A monthly income = 120000 (salary + bonus/12)');
assertTrue($sumA['monthly_expense'] === 45000.0, 'DB: client A monthly expense = 45000 (rent + insurance/12), parked line excluded');
assertTrue($sumA['monthly_surplus'] === 75000.0, 'DB: client A surplus = 75000, exactly');

// Two goals for client A carrying monthly SIPs 30000 + 20000 = 50000.
$mkGoal = static function (PDO $db, int $tenant, int $client, ?float $sip): void {
    $stmt = $db->prepare(
        "INSERT INTO base_plans (tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate, monthly_sip_amount)
         VALUES (:t, :c, 'retirement', 'Retirement', 1000000, 6.0, :sip)"
    );
    $stmt->execute([':t' => $tenant, ':c' => $client, ':sip' => $sip]);
};
$mkGoal($db, 1, $clientA, 30000.0);
$mkGoal($db, 1, $clientA, 20000.0);
$mkGoal($db, 1, $clientA, null); // a goal with no SIP contributes 0

assertTrue(totalMonthlySipForClient($scopedA, $clientA) === 50000.0,
    'DB: total monthly SIP across A\'s goals = 50000 (null-SIP goal adds 0)');

$advA = advisorCashFlowForClient($scopedA, $clientA, $rowsA);
assertTrue($advA['sip_comparison']['gap'] === 25000.0 && $advA['sip_comparison']['funded'] === true,
    'DB: advisor summary — surplus 75000 funds 50000 of SIPs with 25000 to spare');

// Client B: income 60000/mo, expense 70000/mo → deficit −10000; SIP 15000.
$mkItem($scopedA, $clientB, 'income',  60000.0, 'monthly', 1);
$mkItem($scopedA, $clientB, 'expense', 70000.0, 'monthly', 1);
$mkGoal($db, 1, $clientB, 15000.0);

// ---- Tenant isolation --------------------------------------------------------

assertTrue($scopedB->select('cash_flow_items', ['client_id' => $clientA]) === [],
    "tenant isolation: firm B cannot read firm A's client cash-flow rows");
assertTrue(totalMonthlySipForClient($scopedB, $clientA) === 0.0,
    "tenant isolation: firm B totals 0 SIP for firm A's client (rows invisible)");
assertTrue(computeHouseholdCashFlow($scopedB, 100) === null,
    "tenant isolation: firm B cannot resolve firm A's household → null");

// ---- Household roll-up -------------------------------------------------------

$hh = computeHouseholdCashFlow($scopedA, 100);
assertTrue($hh !== null && count($hh['members']) === 2, 'household resolves with its two members');
// Combined: income 120000 + 60000 = 180000; expense 45000 + 70000 = 115000;
// surplus 65000; SIP 50000 + 15000 = 65000; gap 0 (exactly funded).
assertTrue($hh['totals']['monthly_income'] === 180000.0, 'household income = sum of members (180000)');
assertTrue($hh['totals']['monthly_expense'] === 115000.0, 'household expense = sum of members (115000)');
assertTrue($hh['totals']['monthly_surplus'] === 65000.0, 'household surplus = income − expense (65000)');
assertTrue($hh['totals']['total_monthly_sip'] === 65000.0, 'household SIP total = sum of members (65000)');
assertTrue($hh['totals']['gap'] === 0.0 && $hh['totals']['funded'] === true,
    'household exactly funded — surplus 65000 == SIPs 65000, gap 0');

// The aggregate is provably the element-wise sum of members' own summaries.
$expectIncome = $sumA['monthly_income'] + summarizeCashFlowItems($scopedA->select('cash_flow_items', ['client_id' => $clientB]))['monthly_income'];
assertTrue($hh['totals']['monthly_income'] === round($expectIncome, 2),
    'household total == sum of each member\'s own summary, exactly (no shared pool)');

$db->rollBack();
echo "All cash-flow summary tests passed.\n";
