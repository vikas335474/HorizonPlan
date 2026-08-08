<?php
declare(strict_types=1);

/**
 * Phase 1 strategy-template tests — runs the same TenantScopedDb methods the
 * templates_*.php endpoints use, against a real MySQL (same pattern as
 * test_tenant_isolation.php: assumes a live DB, no self-skip, wired into
 * tests/run_all.sh which provisions MySQL in CI).
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/security_gatekeeper.php';
require_once __DIR__ . '/../api/lib/TemplateValidation.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();

// Non-destructive: everything below runs inside one transaction, rolled back
// at the very end (docs/09 Session 2) — see test_tenant_isolation.php for the
// full reasoning. On a failed assertion, assertTrue() calls exit() directly —
// killing the process mid-transaction drops the connection, and MySQL
// implicitly rolls back, so there's no try/finally needed.
$db->beginTransaction();

// --- Setup: clean slate, two tenants (a system-template owner + an advisor tenant) ---
// base_plans must be cleared BEFORE template_strategies/template_customizations —
// migration 014 added applied_template_id/applied_customization_id FKs on
// base_plans pointing at those tables, so deleting the referenced rows first
// (the previous order) now fails with a FK violation.
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
// risk_profiles and client_portfolio_items (migrations 017/018) also FK to
// users — must clear before DELETE FROM users.
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
$db->exec("DELETE FROM client_protection"); // migration 034 also FKs to users
$db->exec("DELETE FROM cash_flow_items"); // migration 030 also FKs to users
$db->exec("DELETE FROM client_portfolio_items");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM households"); // migration 029 FKs to tenants
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'HorizonPlan Admin'), (2, 'Advisor Firm A'), (3, 'Advisor Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'super_admin@example.com', 'hash', 'super_admin'),
    (2, 2, 'advisor_a@example.com', 'hash', 'advisor'),
    (3, 3, 'advisor_b@example.com', 'hash', 'advisor')");

$dbSuperAdmin = new TenantScopedDb($db, 1);
$dbAdvisorA = new TenantScopedDb($db, 2);
$dbAdvisorB = new TenantScopedDb($db, 3);

// --- Test 1: ALLOWED_TABLES accepts the three new template tables ---
$threw = false;
try {
    $dbAdvisorA->select('template_strategies');
    $dbAdvisorA->select('template_customizations');
    $dbAdvisorA->select('template_audit_log');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue(!$threw, 'TenantScopedDb allows template_strategies/template_customizations/template_audit_log');

// --- Test 2: super_admin creates a global (system) template ---
$globalTemplateId = $dbSuperAdmin->insert('template_strategies', [
    'creator_user_id'      => 1,
    'creator_org_id'        => null,
    'template_name'         => 'Aggressive Growth (Global)',
    'market_code'           => 'IN',
    'allocation_json'       => json_encode(['equity' => 80, 'debt' => 15, 'gold' => 5]),
    'return_assumption_pct' => 11.5,
    'risk_profile_enum'     => 'aggressive',
    'is_system_template'    => 1,
    'is_published'          => 1,
    'published_at'          => date('Y-m-d H:i:s'),
]);
assertTrue($globalTemplateId > 0, 'super_admin can insert a system template');

// --- Test 3: an advisor in a DIFFERENT tenant can see the published global template ---
$globalForAdvisorA = $dbAdvisorA->selectGlobalPublishedTemplates();
$globalForAdvisorB = $dbAdvisorB->selectGlobalPublishedTemplates();
assertTrue(
    count($globalForAdvisorA) === 1 && count($globalForAdvisorB) === 1,
    'selectGlobalPublishedTemplates() is visible across tenants (published system templates only)'
);

// --- Test 4: an unpublished draft (system or advisor) is NOT globally visible ---
$draftId = $dbAdvisorA->insert('template_strategies', [
    'creator_user_id'      => 2,
    'creator_org_id'        => 2,
    'template_name'         => 'Advisor A Private Draft',
    'market_code'           => 'IN',
    'allocation_json'       => json_encode(['equity' => 50, 'debt' => 50]),
    'is_system_template'    => 0,
    'is_published'          => 0,
]);
$globalAfterDraft = $dbAdvisorB->selectGlobalPublishedTemplates();
assertTrue(count($globalAfterDraft) === 1, 'unpublished template does not appear in selectGlobalPublishedTemplates()');

// --- Test 5: template_strategies rows are tenant-isolated via normal select() ---
$advisorATemplates = $dbAdvisorA->select('template_strategies');
$advisorBTemplates = $dbAdvisorB->select('template_strategies');
assertTrue(
    count($advisorATemplates) === 1 && count($advisorBTemplates) === 0,
    'select() on template_strategies is tenant-scoped as normal (draft only visible to its own tenant)'
);

// --- Test 6: findTemplateStrategyById() is a deliberate cross-tenant read (needed for fork eligibility) ---
$foundDraftFromB = $dbAdvisorB->findTemplateStrategyById($draftId);
assertTrue(
    $foundDraftFromB !== null && (int) $foundDraftFromB['id'] === $draftId,
    'findTemplateStrategyById() can read a row belonging to a different tenant (fork-eligibility check is application-layer)'
);

// --- Test 7: fork-eligibility logic (as implemented in templates_fork.php) rejects a private cross-tenant template ---
function isForkEligible(array $template, int $callingTenantId): bool
{
    $isOwnTenant = (int) $template['tenant_id'] === $callingTenantId;
    $isPublished = (int) $template['is_published'] === 1;
    return $isOwnTenant || $isPublished;
}
assertTrue(
    isForkEligible($foundDraftFromB, 3) === false,
    'fork eligibility check rejects advisor B forking advisor A\'s private draft'
);
assertTrue(
    isForkEligible($foundDraftFromB, 2) === true,
    'fork eligibility check allows advisor A forking their own draft'
);
$globalTemplateRow = $dbAdvisorB->findTemplateStrategyById($globalTemplateId);
assertTrue(
    isForkEligible($globalTemplateRow, 3) === true,
    'fork eligibility check allows forking a published global template from any tenant'
);

// --- Test 8: advisor B forks the published global template into template_customizations ---
$customizationId = $dbAdvisorB->insert('template_customizations', [
    'base_template_id'      => $globalTemplateId,
    'template_name'          => 'Aggressive Growth (Advisor B copy)',
    'allocation_json'        => json_encode(['equity' => 85, 'debt' => 10, 'gold' => 5]),
    'is_private'            => 1,
    'is_shareable'          => 0,
    'created_by_user_id'    => 3,
]);
assertTrue($customizationId > 0, 'advisor B can fork the global template into their own template_customizations row');

$dbAdvisorB->insert('template_audit_log', [
    'template_id'         => $globalTemplateId,
    'customization_id'    => $customizationId,
    'user_id'             => 3,
    'action'               => 'forked_from',
    'entity_details_json' => json_encode(['base_template_id' => $globalTemplateId]),
]);

// --- Test 9: template_customizations is tenant-isolated (advisor A can't see advisor B's fork) ---
$advisorACustomizations = $dbAdvisorA->select('template_customizations');
$advisorBCustomizations = $dbAdvisorB->select('template_customizations');
assertTrue(
    count($advisorACustomizations) === 0 && count($advisorBCustomizations) === 1,
    'template_customizations rows are tenant-scoped — advisor A cannot see advisor B\'s fork'
);

// --- Test 10: template_audit_log is tenant-isolated the same way ---
$advisorAAudit = $dbAdvisorA->select('template_audit_log');
$advisorBAudit = $dbAdvisorB->select('template_audit_log');
assertTrue(
    count($advisorAAudit) === 0 && count($advisorBAudit) === 1,
    'template_audit_log rows are tenant-scoped to the acting tenant'
);

// --- Test 11: countGlobalTemplateUsage is an aggregate-only cross-tenant read ---
$db->exec("INSERT INTO template_audit_log (tenant_id, template_id, customization_id, user_id, action)
           VALUES (3, $globalTemplateId, $customizationId, 3, 'used_in_plan')");
$usageFromA = $dbAdvisorA->countGlobalTemplateUsage($globalTemplateId);
assertTrue($usageFromA === 1, 'countGlobalTemplateUsage() returns an aggregate count visible cross-tenant');

// --- Test 12: countTemplateUsage is tenant-scoped (advisor A sees 0 for a template only B used) ---
$usageForATenant = $dbAdvisorA->countTemplateUsage($globalTemplateId, null);
$usageForBTenant = $dbAdvisorB->countTemplateUsage(null, $customizationId);
assertTrue(
    $usageForATenant === 0 && $usageForBTenant === 1,
    'countTemplateUsage() only counts the calling tenant\'s own usage rows'
);

// --- Test 13: validateAllocationJson / validateRiskProfile helpers ---
assertTrue(validateAllocationJson(['equity' => 60, 'debt' => 40]) === null, 'validateAllocationJson accepts a valid 100% split');
assertTrue(validateAllocationJson(['equity' => 60, 'debt' => 30]) !== null, 'validateAllocationJson rejects a split that does not sum to 100');
assertTrue(validateAllocationJson(['equity' => -10, 'debt' => 110]) !== null, 'validateAllocationJson rejects a negative allocation');
assertTrue(validateAllocationJson([]) !== null, 'validateAllocationJson rejects an empty allocation');
assertTrue(validateRiskProfile('moderate') === null, 'validateRiskProfile accepts a known profile');
assertTrue(validateRiskProfile('yolo') !== null, 'validateRiskProfile rejects an unknown profile');

// docs/07 Bet 1: approval workflow — an unapproved template/customization is
// illustration-only until a deliberate second step marks it approved.

// --- Test 14: every newly inserted row defaults to approval_status = 'draft' ---
$freshGlobal = $dbSuperAdmin->select('template_strategies', ['id' => $globalTemplateId]);
$freshCustomization = $dbAdvisorB->select('template_customizations', ['id' => $customizationId]);
assertTrue(
    $freshGlobal[0]['approval_status'] === 'draft' && $freshCustomization[0]['approval_status'] === 'draft',
    'newly created templates/customizations default to approval_status = draft, even for a super_admin-created global template'
);

// --- Test 15: approveGlobalTemplate() is the one write exception — approves
// across tenants, restricted to is_system_template = 1 rows ---
$dbSuperAdmin->approveGlobalTemplate($globalTemplateId, 1);
$approvedGlobal = $dbAdvisorB->findTemplateStrategyById($globalTemplateId); // unscoped read, any tenant can check
assertTrue(
    $approvedGlobal['approval_status'] === 'approved' && (int) $approvedGlobal['approved_by_user_id'] === 1,
    'approveGlobalTemplate() marks a system template approved and records the approver, readable cross-tenant'
);

// approveGlobalTemplate() is guarded by is_system_template = 1 in its own SQL
// — calling it against advisor A's non-system draft must not approve it.
$dbSuperAdmin->approveGlobalTemplate($draftId, 1);
$stillDraft = $dbAdvisorA->select('template_strategies', ['id' => $draftId]);
assertTrue(
    $stillDraft[0]['approval_status'] === 'draft',
    'approveGlobalTemplate() refuses to approve a non-system template even if called directly (WHERE is_system_template = 1 guard)'
);

// --- Test 16: a non-system template is approved via the normal tenant-scoped
// update() — advisor A can approve their own draft, advisor B cannot ---
$affectedByOwner = $dbAdvisorA->update('template_strategies', [
    'approval_status'     => 'approved',
    'approved_by_user_id' => 2,
    'approved_at'         => date('Y-m-d H:i:s'),
], ['id' => $draftId]);
assertTrue($affectedByOwner === 1, 'the owning tenant\'s advisor can approve their own non-system template via the normal tenant-scoped update()');

$affectedByOtherTenant = $dbAdvisorB->update('template_strategies', [
    'approval_status' => 'approved',
], ['id' => $draftId]);
assertTrue($affectedByOtherTenant === 0, 'a different tenant cannot approve advisor A\'s template — tenant-scoped update() naturally blocks it, same as any other cross-tenant write attempt');

// --- Test 17: goals_apply_template.php's core sequence — approved-only gate,
// cascade to non-overridden children, skip overridden ones, log usage ---
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES (4, 3, 'client_b@example.com', 'hash', 'client')");
$goalId = $dbAdvisorB->insert('base_plans', [
    'client_id'                => 4,
    'goal_type'                 => 'retirement',
    'goal_label'                => 'Retirement — Apply Template Test',
    'initial_net_worth'         => 10000000.00,
    'inflation_rate'            => 6.0,
    'withdrawal_rate'           => 3.5,
    'drawdown_return_rate'      => null,
    'projection_horizon_years'  => 30,
]);
$freeChildId = $dbAdvisorB->insert('sub_scenarios', ['base_plan_id' => $goalId, 'custom_inflation' => 6.0, 'is_overridden' => 0]);
$protectedChildId = $dbAdvisorB->insert('sub_scenarios', ['base_plan_id' => $goalId, 'custom_drawdown_return_rate' => 5.0, 'is_overridden' => 1]);

// The approval gate itself: an unapproved template must be refused before
// any of the write steps below run — this mirrors the exact check
// goals_apply_template.php makes before touching base_plans. Use a fresh
// draft (still-published, so it's fork/apply-eligible on the ownership
// check) rather than reusing $draftId, which Test 16 already approved.
$stillDraftId = $dbAdvisorA->insert('template_strategies', [
    'creator_user_id'      => 2,
    'creator_org_id'        => 2,
    'template_name'         => 'Advisor A Published But Unapproved',
    'market_code'           => 'IN',
    'allocation_json'       => json_encode(['equity' => 50, 'debt' => 50]),
    'return_assumption_pct' => 9.0,
    'is_system_template'    => 0,
    'is_published'          => 1,
]);
$unapprovedCheck = $dbAdvisorB->findTemplateStrategyById($stillDraftId);
assertTrue($unapprovedCheck['approval_status'] !== 'approved', 'an unapproved template is correctly identified as not applicable to a goal (the exact check goals_apply_template.php makes)');

// Now apply the approved global template's return assumption to the goal —
// same statements goals_apply_template.php runs.
$appliedRate = (float) $approvedGlobal['return_assumption_pct'];
$dbAdvisorB->update('base_plans', [
    'drawdown_return_rate'     => $appliedRate,
    'applied_template_id'      => $globalTemplateId,
    'applied_customization_id' => null,
], ['id' => $goalId]);
$dbAdvisorB->update('sub_scenarios', ['custom_drawdown_return_rate' => $appliedRate], ['base_plan_id' => $goalId, 'is_overridden' => 0]);
$dbAdvisorB->insert('template_audit_log', [
    'template_id'         => $globalTemplateId,
    'customization_id'    => null,
    'user_id'             => 3,
    'action'               => 'used_in_plan',
    'entity_details_json' => json_encode(['goal_id' => $goalId]),
]);

$updatedGoal = $dbAdvisorB->select('base_plans', ['id' => $goalId]);
assertTrue(
    (float) $updatedGoal[0]['drawdown_return_rate'] === $appliedRate && (int) $updatedGoal[0]['applied_template_id'] === $globalTemplateId,
    'applying a template writes its return_assumption_pct into drawdown_return_rate and records applied_template_id'
);

$freeChild = $dbAdvisorB->select('sub_scenarios', ['id' => $freeChildId]);
$protectedChild = $dbAdvisorB->select('sub_scenarios', ['id' => $protectedChildId]);
assertTrue(
    (float) $freeChild[0]['custom_drawdown_return_rate'] === $appliedRate,
    'a non-overridden sub-scenario inherits the applied template\'s return assumption, same cascade rule as goals_update.php'
);
assertTrue(
    (float) $protectedChild[0]['custom_drawdown_return_rate'] === 5.0,
    'an overridden (is_overridden=1) sub-scenario is untouched by the template application — the cascade skips it exactly like a normal goals_update.php cascade'
);

// countTemplateUsage($globalTemplateId, null) filters only on template_id
// (customization_id param is null -> not filtered), so it picks up BOTH
// Test 11's seeded used_in_plan row (which also happened to carry a
// customization_id) and this apply's row: 2 total, both tenant 3.
// countGlobalTemplateUsage is the same count with no tenant filter — since
// both rows are tenant 3, it agrees.
$usageAfterApply = $dbAdvisorB->countTemplateUsage($globalTemplateId, null);
$globalUsageAfterApply = $dbAdvisorA->countGlobalTemplateUsage($globalTemplateId);
assertTrue(
    $usageAfterApply === 2 && $globalUsageAfterApply === 2,
    'applying a template writes a used_in_plan audit row that the existing usage-count methods pick up — this is the loop Phase 1\'s template system left unclosed'
);

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll template tests passed.\n";
