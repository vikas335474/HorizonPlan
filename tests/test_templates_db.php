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

// --- Setup: clean slate, two tenants (a system-template owner + an advisor tenant) ---
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
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

echo "\nAll template tests passed.\n";
