<?php
declare(strict_types=1);

/**
 * docs/10 P1-4 — the financial-foundations module against a real database,
 * inside a rolled-back transaction.
 *
 * The pure arithmetic is covered in tests/test_financial_foundations.php. What
 * needs a database is the part that assembles the inputs, and the two ways that
 * assembly could go quietly wrong:
 *
 *   1. TENANT ISOLATION — the check reads cash_flow_items,
 *      client_portfolio_items and base_plans for a client. If any of those
 *      reads leaked across tenants, one person's loans or expenses would show
 *      up in another's foundations. Two tenants hold deliberately identical
 *      client ids' worth of data here so a leak would be visible.
 *   2. THE LIQUID/LOCKED DISTINCTION — an emergency reserve is only the money
 *      that can actually be reached. Counting EPF/NPS/PPF would report someone
 *      as comfortably covered when they cannot reach a rupee of it in a week.
 */

require_once __DIR__ . '/../api/db_config.php';
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
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (10, 1, 'advisor.a@example.invalid', 'h', 'advisor'),
    (11, 1, 'client.a@example.invalid', 'h', 'client'),
    (20, 2, 'advisor.b@example.invalid', 'h', 'advisor'),
    (21, 2, 'client.b@example.invalid', 'h', 'client')");

// Tenant 1's client: 50k/month expenses, 300k liquid, 900k locked, a 30% card.
$db->exec("INSERT INTO cash_flow_items (tenant_id, client_id, kind, label, amount, cadence, category, created_by_user_id) VALUES
    (1, 11, 'income',  'Salary',  100000, 'monthly', 'salary',        10),
    (1, 11, 'expense', 'Living',   50000, 'monthly', 'other_expense', 10)");
$db->exec("INSERT INTO client_portfolio_items (tenant_id, client_id, item_kind, bucket, category, description, value, interest_rate, created_by_user_id) VALUES
    (1, 11, 'asset',     'liquid', 'savings',     'Bank',        300000, NULL, 10),
    (1, 11, 'asset',     'locked', 'epf',         'EPF',         900000, NULL, 10),
    (1, 11, 'liability', NULL,     'personal_loan','Credit card', 200000, 30.0, 10)");

// Tenant 2's client: deliberately much richer and debt-free. If any read leaks,
// these figures are distinctive enough to spot in tenant 1's result.
$db->exec("INSERT INTO cash_flow_items (tenant_id, client_id, kind, label, amount, cadence, category, created_by_user_id) VALUES
    (2, 21, 'expense', 'Living', 999999, 'monthly', 'other_expense', 20)");
$db->exec("INSERT INTO client_portfolio_items (tenant_id, client_id, item_kind, bucket, category, description, value, interest_rate, created_by_user_id) VALUES
    (2, 21, 'asset', 'liquid', 'savings', 'Bank', 88888888, NULL, 20)");

/** Assemble exactly as foundations_read.php does, through TenantScopedDb. */
function foundationsFor(PDO $db, int $tenantId, int $clientId, ?float $assumedReturn): array
{
    $scoped = new TenantScopedDb($db, $tenantId);
    $cash = summarizeCashFlowItems($scoped->select('cash_flow_items', ['client_id' => $clientId]));

    $liquid = 0.0;
    $liabilities = [];
    $portfolio = $scoped->select('client_portfolio_items', ['client_id' => $clientId]);
    foreach ($portfolio as $r) {
        if ($r['item_kind'] === 'asset') {
            if ($r['bucket'] === 'liquid') {
                $liquid += (float) $r['value'];
            }
            continue;
        }
        $liabilities[] = ['label' => (string) $r['description'], 'value' => (float) $r['value'], 'interest_rate' => $r['interest_rate']];
    }

    $rows = $scoped->select('client_protection', ['client_id' => $clientId]);
    $p = $rows[0] ?? null;

    return FinancialFoundations::summary(
        (float) $cash['monthly_expense'],
        $liquid,
        (float) $cash['monthly_income'] * 12.0,
        $p && $p['dependants_count'] !== null ? (int) $p['dependants_count'] : null,
        $p && $p['term_life_cover'] !== null ? (float) $p['term_life_cover'] : null,
        $p && $p['health_cover'] !== null ? (float) $p['health_cover'] : null,
        $liabilities,
        $assumedReturn,
        $portfolio !== []
    );
}

$a = foundationsFor($db, 1, 11, 11.0);
$byId = [];
foreach ($a['checks'] as $c) {
    $byId[$c['id']] = $c;
}

// --- the liquid/locked distinction -----------------------------------------
// 300k liquid / 50k per month = 6 months. Had the 900k of EPF been counted it
// would read 24 months — "comfortably covered" on money that cannot be reached.
assertClose($byId['emergency_reserve']['months'], 6.0, 'only the LIQUID bucket counts toward the emergency reserve');
assertTrue($byId['emergency_reserve']['status'] === 'ok', '6 months of reachable savings clears the comfortable mark');
assertClose($byId['emergency_reserve']['liquid'], 300000.0, 'locked assets are excluded from the reserve numerator entirely');

// --- debt priced against the plan ------------------------------------------
assertTrue($byId['costly_debt']['status'] === 'short', 'a 30% card against an 11% assumption is flagged');
assertClose($byId['costly_debt']['costly'][0]['gap'], 19.0, 'the gap is the recorded rate minus the plan assumption');

// --- protection is not recorded yet ----------------------------------------
assertTrue($byId['life_cover']['status'] === 'not_recorded', 'with no protection row, life cover is an open question');
assertTrue($byId['health_cover']['status'] === 'not_recorded', 'and so is health cover — never a silent pass');
assertTrue($a['unmet'] === 1, 'only the debt counts as a shortfall; the two unanswered checks do not');
assertTrue($a['open'] === 2, 'and both unanswered checks are reported as open');

// --- tenant isolation -------------------------------------------------------
// Tenant 2's 88,888,888 and 999,999 must be nowhere in tenant 1's result.
assertTrue($byId['emergency_reserve']['liquid'] < 88888888.0, "tenant 2's assets do not appear in tenant 1's reserve");
assertClose($byId['emergency_reserve']['monthly_expenses'], 50000.0, "tenant 2's expenses do not appear in tenant 1's denominator");

$b = foundationsFor($db, 2, 21, 11.0);
$bById = [];
foreach ($b['checks'] as $c) {
    $bById[$c['id']] = $c;
}
assertClose($bById['emergency_reserve']['liquid'], 88888888.0, "tenant 2 reads its OWN assets");
assertTrue($bById['costly_debt']['status'] === 'ok', "tenant 2 has no debt, and does not inherit tenant 1's card");

// A client id that belongs to another tenant reads as empty, not as that
// client's data — the scoped read simply matches nothing.
$cross = foundationsFor($db, 2, 11, 11.0);
$crossById = [];
foreach ($cross['checks'] as $c) {
    $crossById[$c['id']] = $c;
}
assertTrue(
    $crossById['emergency_reserve']['status'] === 'not_recorded',
    "reading tenant 1's client id from inside tenant 2 returns nothing, not their figures"
);
assertTrue(
    $crossById['costly_debt']['status'] === 'not_recorded',
    'and the empty result reads as unrecorded rather than as a debt-free pass'
);
assertClose($crossById['emergency_reserve']['liquid'], 0.0, 'and no assets leak across the boundary');

// --- protection round-trip, including the NULL-vs-0 distinction -------------
$scoped1 = new TenantScopedDb($db, 1);
$scoped1->insert('client_protection', [
    'client_id'          => 11,
    'dependants_count'   => 0,
    'term_life_cover'    => null,
    'health_cover'       => 0,
    'created_by_user_id' => 10,
]);

$after = foundationsFor($db, 1, 11, 11.0);
$afterById = [];
foreach ($after['checks'] as $c) {
    $afterById[$c['id']] = $c;
}
// 0 dependants stored as 0 (not NULL) must survive the round trip and read as
// not_applicable — the whole reason the column is nullable.
assertTrue($afterById['life_cover']['status'] === 'not_applicable', 'a stored 0 dependants round-trips as not_applicable, not not_recorded');
assertTrue($afterById['health_cover']['status'] === 'short', 'a stored 0 health cover round-trips as a real "no cover" answer');

$db->rollBack();
echo "\nAll foundations DB tests passed.\n";
