<?php
declare(strict_types=1);

/**
 * Self-serve individual tier — the write-permission gate (api/lib/SelfService.php).
 *
 * The single most important property in this feature, asserted against a real
 * database inside a rolled-back transaction:
 *
 *   GRANTING SELF-SERVICE WRITES TO INDIVIDUALS MUST NOT GRANT THEM TO
 *   ADVISOR-MANAGED CLIENTS.
 *
 * If that ever regresses, a client of a real firm could rewrite advice their
 * advisor authored — breaking the Jr->Sr approval workflow and the change_log's
 * meaning. So the gate is tested from both sides, plus the tenant-kind lookup
 * that decides it (including its fail-closed behaviour).
 *
 * verifySelfServiceWrite() itself exits on failure (it is an HTTP-layer
 * function), so what is unit-testable here is the DECISION it is built on:
 * tenantIsPersonal() and resolveSelfServiceClientId(). The exit paths are
 * exercised in the browser pass instead.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/SelfService.php';

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

// A normal advisor-managed firm, and a self-serve individual's tenant of one.
$db->exec("INSERT INTO tenants (id, company_name, kind, advisory_mode) VALUES
    (1, 'Firm A', 'firm', 'distribution'),
    (2, 'Priya (personal)', 'personal', 'self_directed')");

$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (10, 1, 'advisor@example.invalid', 'h', 'advisor'),
    (11, 1, 'firm.client@example.invalid', 'h', 'client'),
    (20, 2, 'priya@example.invalid', 'h', 'client')");

// --- tenantIsPersonal ------------------------------------------------------
assertTrue(tenantIsPersonal($db, 2) === true, 'a personal tenant reads as personal');
assertTrue(tenantIsPersonal($db, 1) === false, 'a firm tenant reads as firm — advisor-managed clients stay gated');

// Fails CLOSED: an id with no tenant row must read as 'firm' (restrictive),
// never as personal, so a broken lookup can't grant write access.
assertTrue(tenantIsPersonal($db, 999999) === false, 'a missing tenant fails CLOSED to firm, never to personal');

// --- the invariant, stated directly ----------------------------------------
// These two rows differ ONLY by which tenant they're in. Same role, same
// shape. The gate must treat them differently, and that difference is the
// entire safety story of this tier.
$firmClientTenant     = (int) $db->query("SELECT tenant_id FROM users WHERE id = 11")->fetchColumn();
$personalUserTenant   = (int) $db->query("SELECT tenant_id FROM users WHERE id = 20")->fetchColumn();
assertTrue(
    tenantIsPersonal($db, $personalUserTenant) === true && tenantIsPersonal($db, $firmClientTenant) === false,
    'two identical client rows are gated differently purely by tenant kind — role alone never grants self-service'
);

// --- resolveSelfServiceClientId -------------------------------------------
// A personal user's own id always wins; a supplied client_id is ignored
// rather than trusted, exactly like the client-facing read endpoints.
$clientSession  = ['user_id' => 20, 'tenant_id' => 2, 'role' => 'client'];
$advisorSession = ['user_id' => 10, 'tenant_id' => 1, 'role' => 'advisor'];

assertTrue(
    resolveSelfServiceClientId($clientSession, 11) === 20,
    'a personal user writing with someone else\'s client_id in the body still resolves to THEMSELVES — the body is ignored'
);
assertTrue(
    resolveSelfServiceClientId($clientSession, 0) === 20,
    'a personal user with no client_id supplied resolves to themselves'
);
assertTrue(
    resolveSelfServiceClientId($advisorSession, 11) === 11,
    'an advisor still acts on the client they named'
);

// --- tenant isolation is untouched by any of this --------------------------
// The tier adds no cross-tenant reach: a personal tenant is still just a
// tenant, and TenantScopedDb behaves identically.
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
$scopedPersonal = new TenantScopedDb($db, 2);
assertTrue(
    $scopedPersonal->select('users', ['id' => 11]) === [],
    'a personal tenant cannot see a firm tenant\'s client — isolation is unchanged'
);
$scopedFirm = new TenantScopedDb($db, 1);
assertTrue(
    $scopedFirm->select('users', ['id' => 20]) === [],
    'a firm cannot see an individual\'s data either — isolation cuts both ways'
);

$db->rollBack();

echo "\nAll self-service gate tests passed.\n";
