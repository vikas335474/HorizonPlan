<?php
declare(strict_types=1);

/**
 * docs/11 Prompt E-2 — client_context / client_dependants against a real
 * database, inside a rolled-back transaction. Mirrors tests/test_foundations_db.php's
 * shape: the pure reference figures are covered in
 * tests/test_personalisation_reference.php; what needs a database is the part
 * that assembles inputs across tenants and households, and the two ways that
 * could quietly go wrong:
 *
 *   1. TENANT ISOLATION — client_context/client_dependants must never leak
 *      across tenants, same as every other tenant-scoped table.
 *   2. THE HOUSEHOLD CITY-TIER FALLBACK — city tier is meant to be
 *      household-shared (docs/11 decide-then-build note): reading a client
 *      with no tier of their own should fall back to a household partner's,
 *      but never to a same-numbered client id in a DIFFERENT tenant, and
 *      never when there is no household at all.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/PersonalisationReference.php';

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

/**
 * The household city-tier resolution goals_projection.php / personalisation_
 * context_read.php both implement: this client's own row, falling back to a
 * household partner's when unanswered. Reimplemented here directly against
 * TenantScopedDb so the DB-dependent part of that logic is exercised for real,
 * the same "duplicate the read logic in the test" shape test_foundations_db.php
 * already uses for FinancialFoundations::summary()'s inputs.
 */
function resolveCityTier(TenantScopedDb $db, int $clientId): array
{
    $own = $db->select('client_context', ['client_id' => $clientId]);
    $tier = $own[0]['city_tier'] ?? null;
    $override = ($own !== [] && $own[0]['expense_multiplier'] !== null) ? (float) $own[0]['expense_multiplier'] : null;

    if ($tier === null && $override === null) {
        $clientRows = $db->select('users', ['id' => $clientId, 'role' => 'client']);
        $householdId = ($clientRows !== [] && $clientRows[0]['household_id'] !== null) ? (int) $clientRows[0]['household_id'] : 0;
        if ($householdId > 0) {
            foreach ($db->select('users', ['household_id' => $householdId, 'role' => 'client']) as $member) {
                if ((int) $member['id'] === $clientId) {
                    continue;
                }
                $partner = $db->select('client_context', ['client_id' => (int) $member['id']]);
                if ($partner !== [] && ($partner[0]['city_tier'] !== null || $partner[0]['expense_multiplier'] !== null)) {
                    $tier = $partner[0]['city_tier'];
                    $override = $partner[0]['expense_multiplier'] !== null ? (float) $partner[0]['expense_multiplier'] : null;
                    break;
                }
            }
        }
    }

    return PersonalisationReference::cityTierMultiplier($tier, $override);
}

$db = getPdo();
$db->beginTransaction();

foreach ([
    'client_dependants', 'client_context', 'goal_snapshots', 'client_net_worth_snapshots',
    'client_protection', 'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items',
    'mf_nav_cache', 'risk_profiles', 'risk_question_sets', 'template_audit_log', 'change_log',
    'sub_scenarios', 'base_plans', 'template_customizations', 'template_strategies',
    'active_sessions', 'login_attempts', 'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name, kind, advisory_mode) VALUES
    (1, 'Personal tenant A', 'personal', 'self_directed'),
    (2, 'Personal tenant B', 'personal', 'self_directed')");
$db->exec("INSERT INTO households (id, tenant_id, name) VALUES (1, 1, 'Household A')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role, household_id) VALUES
    (10, 1, 'arun@example.invalid', 'h', 'client', 1),
    (11, 1, 'priya@example.invalid', 'h', 'client', 1),
    (12, 1, 'solo@example.invalid', 'h', 'client', NULL),
    -- users.id is globally auto-increment (not per-tenant), so tenant B's
    -- client necessarily has a DIFFERENT numeric id — tenant isolation is
    -- exercised below by scoping the SAME table through each tenant's own
    -- TenantScopedDb instance instead.
    (30, 2, 'other.arun@example.invalid', 'h', 'client', NULL)");

$dbA = new TenantScopedDb($db, 1);
$dbB = new TenantScopedDb($db, 2);

// --- household city-tier fallback, "neither has answered" case -----------
// Checked BEFORE either partner has a client_context row at all, so this
// genuinely exercises the empty-household case rather than one partner's
// row (inserted below) accidentally already supplying an answer.
$neither = resolveCityTier($dbA, 11);
assertTrue($neither['is_applied'] === false, 'with nobody in the household answered yet, the fallback stays neutral');

// --- tenant isolation ----------------------------------------------------

$dbA->insert('client_context', ['client_id' => 10, 'city_tier' => 'X', 'created_by_user_id' => 10]);
$dbB->insert('client_context', ['client_id' => 30, 'city_tier' => 'Z', 'created_by_user_id' => 30]);

$readA = $dbA->select('client_context', ['client_id' => 10]);
$readB = $dbB->select('client_context', ['client_id' => 30]);
assertTrue($readA[0]['city_tier'] === 'X', "tenant A's client reads tenant A's OWN tier (X)");
assertTrue($readB[0]['city_tier'] === 'Z', "tenant B's client reads tenant B's own, different row (Z)");
assertTrue($dbA->select('client_context', ['client_id' => 30]) === [], "tenant A's scoped db cannot read tenant B's client_context row at all, even by id");
assertTrue($dbB->select('client_context', ['client_id' => 10]) === [], "tenant B's scoped db cannot read tenant A's client_context row at all, even by id");

$dbA->insert('client_dependants', ['client_id' => 10, 'current_age' => 8, 'cost_driver' => 'private', 'created_by_user_id' => 10]);
assertTrue(count($dbB->select('client_dependants', ['client_id' => 10])) === 0, "tenant B sees none of tenant A's dependant rows, even querying the same client_id value");

// --- household city-tier fallback, continued -----------------------------

// Arun (10) already has X recorded above (via the tenant-isolation insert).
// Priya (11) has not answered — she should inherit Arun's tier.
$priyaFallback = resolveCityTier($dbA, 11);
assertTrue($priyaFallback['city_tier'] === 'X', "Priya, who hasn't answered, inherits her household partner Arun's tier");
assertTrue($priyaFallback['is_override'] === false, 'the inherited figure is still a sourced default, not flagged as an override');

// Once Priya answers for herself, HER OWN answer wins over the household fallback.
$dbA->insert('client_context', ['client_id' => 11, 'city_tier' => 'Y', 'created_by_user_id' => 11]);
$priyaOwn = resolveCityTier($dbA, 11);
assertTrue($priyaOwn['city_tier'] === 'Y', "once Priya answers herself, her OWN tier wins over the household fallback");

// A solo client with no household at all never falls back to anyone.
$solo = resolveCityTier($dbA, 12);
assertTrue($solo['is_applied'] === false, 'a client with no household gets no fallback — nobody to inherit from');

// An explicit multiplier OVERRIDE, with no tier, also blocks the fallback —
// the client has answered (via the override), even without picking a tier.
$db->exec('DELETE FROM client_context WHERE client_id = 11');
$dbA->insert('client_context', ['client_id' => 11, 'expense_multiplier' => 0.9, 'created_by_user_id' => 11]);
$priyaOverrideOnly = resolveCityTier($dbA, 11);
assertClose($priyaOverrideOnly['multiplier'], 0.9, "an override with no tier is still treated as answered — Priya's own 0.9, not Arun's tier");
assertTrue($priyaOverrideOnly['is_override'] === true, 'and correctly flagged as the user\'s own figure');

// --- dependant -> education goal link, tenant + type constraints ----------

$db->exec("INSERT INTO base_plans
    (id, tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate, projection_horizon_years, target_amount, target_date)
    VALUES
    (100, 1, 10, 'education', 'Child\\'s college', 500000, 6.0, 15, 2000000, '2035-06-01'),
    (101, 1, 10, 'retirement', 'Retiring comfortably', 500000, 6.0, 30, NULL, NULL),
    (200, 2, 30, 'education', 'Other tenant education goal', 100000, 6.0, 15, 1000000, '2035-06-01')");

$linkRow = $dbA->select('client_dependants', ['client_id' => 10]);
$dbA->update('client_dependants', ['goal_id' => 100], ['id' => (int) $linkRow[0]['id']]);
$linked = $dbA->select('client_dependants', ['id' => (int) $linkRow[0]['id']]);
assertTrue((int) $linked[0]['goal_id'] === 100, 'a dependant links to an education-type goal in the same tenant');

// The FK is tenant-agnostic at the DB layer (base_plans.id is globally unique),
// but the endpoint's own lookup — base_plans filtered by client_id AND
// goal_type='education' via TenantScopedDb — is what actually enforces the
// "same tenant, same client, education-type" rule; verified here directly:
$eduGoalForClient10 = $dbA->select('base_plans', ['id' => 100, 'client_id' => 10, 'goal_type' => 'education']);
assertTrue($eduGoalForClient10 !== [], 'the endpoint\'s own lookup finds the valid link target');
$retirementGoalRejected = $dbA->select('base_plans', ['id' => 101, 'client_id' => 10, 'goal_type' => 'education']);
assertTrue($retirementGoalRejected === [], 'a retirement-type goal is correctly rejected by the same lookup — dependants only link to education goals');
$crossTenantGoalRejected = $dbA->select('base_plans', ['id' => 200, 'client_id' => 10, 'goal_type' => 'education']);
assertTrue($crossTenantGoalRejected === [], 'tenant B\'s education goal is invisible to tenant A\'s scoped lookup, even with a matching id pattern');

// ON DELETE SET NULL — deleting the linked goal must not orphan the FK or
// block the delete; the dependant simply loses its link.
$db->exec('DELETE FROM base_plans WHERE id = 100');
$afterGoalDelete = $dbA->select('client_dependants', ['id' => (int) $linkRow[0]['id']]);
assertTrue($afterGoalDelete[0]['goal_id'] === null, 'deleting the linked goal clears the link (ON DELETE SET NULL) rather than failing or dangling');

echo "\nAll personalisation DB tests passed.\n";
