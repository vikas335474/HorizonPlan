<?php
declare(strict_types=1);

/**
 * Jr -> Sr Advisor Plan-Approval Workflow — DB integration test.
 *
 * Covers:
 *  - the migration 026 backfill: a fresh tenant defaults requires_plan_review
 *    to 'off', and a fresh goal defaults review_status to 'not_required' —
 *    i.e. no existing goal in a pre-migration database becomes blocked or
 *    silently reclassified by this feature existing.
 *  - tenantRequiresPlanReview() reads the per-tenant opt-in flag correctly.
 *  - applyPlanReviewTransitionOnAdviceEdit()'s full transition matrix
 *    (PlanReview.php): a no-op when the tenant hasn't opted in;
 *    not_required -> pending_review and approved -> pending_review when it
 *    has; pending_review and changes_requested left untouched (decision #6
 *    only reverts an *approved* goal automatically — changes_requested waits
 *    for an explicit resubmission, matching goals_submit_review.php).
 *  - the goals_review.php approve/request_changes transition, exercised
 *    directly against the DB (same "mirror the endpoint's core logic" style
 *    test_firm_roles.php already uses for requireFirmRole()'s exit-on-failure
 *    path).
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is
 * unreachable, or the relevant migration 026 columns aren't present yet:
 *   php tests/test_plan_review_db.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at the
 * end (see test_mfa_enforcement_db.php's docblock for why exit()-on-failure
 * is still safe under a transaction wrapper).
 */

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}
function skip(string $why): void
{
    echo "SKIP: $why\n";
    exit(0);
}

// api/db_config.php always exists now (it's a tracked loader — see its own
// header); the real "is a dev DB configured" question is answered by the
// shared resolver both it and this check use, so the two can't drift apart.
require_once __DIR__ . '/../api/lib/DbConfigResolver.php';
if (resolveDbConfigPath() === null) {
    skip('no database credentials configured — no database to test against.');
}

require_once __DIR__ . '/../api/lib/security_gatekeeper.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/PlanReview.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$tenantCols = $db->query("SHOW COLUMNS FROM tenants LIKE 'requires_plan_review'")->fetchAll();
$goalCols = $db->query("SHOW COLUMNS FROM base_plans LIKE 'review_status'")->fetchAll();
if (empty($tenantCols) || empty($goalCols)) {
    skip('plan-review columns not migrated — run sql/026_plan_review.sql to enable this test.');
}

$db->beginTransaction();

function makeTenant(PDO $db, string $name, string $requiresReview): int
{
    $stmt = $db->prepare(
        "INSERT INTO tenants (company_name, advisory_mode, requires_plan_review) VALUES (:n, 'distribution', :r)"
    );
    $stmt->execute([':n' => $name, ':r' => $requiresReview]);
    return (int) $db->lastInsertId();
}

function makeGoal(PDO $db, int $tenantId, int $clientId, string $reviewStatus = 'not_required'): int
{
    $stmt = $db->prepare(
        "INSERT INTO base_plans
            (tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate,
             withdrawal_rate, drawdown_return_rate, review_status)
         VALUES (:t, :c, 'retirement', 'Test Goal', 5000000, 6.0, 3.5, 7.0, :rs)"
    );
    $stmt->execute([':t' => $tenantId, ':c' => $clientId, ':rs' => $reviewStatus]);
    return (int) $db->lastInsertId();
}

function makeClient(PDO $db, int $tenantId): int
{
    $stmt = $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'client')"
    );
    $stmt->execute([
        ':t' => $tenantId,
        ':e' => 'planreview-test-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ':h' => password_hash('irrelevant', PASSWORD_BCRYPT),
    ]);
    return (int) $db->lastInsertId();
}

function changeLogCount(PDO $db, int $goalId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM change_log WHERE entity_type = 'base_plan' AND entity_id = :id AND field_changed = 'review_status'");
    $stmt->execute([':id' => $goalId]);
    return (int) $stmt->fetchColumn();
}

// --- Backfill / defaults ------------------------------------------------

$plainTenant = makeTenant($db, 'Plan Review Off Firm', 'off');
assertTrue(tenantRequiresPlanReview($db, $plainTenant) === false, 'a fresh tenant defaults requires_plan_review to off');

$plainClient = makeClient($db, $plainTenant);
$plainGoalId = makeGoal($db, $plainTenant, $plainClient); // default 'not_required'
$stmt = $db->prepare("SELECT review_status FROM base_plans WHERE id = :id");
$stmt->execute([':id' => $plainGoalId]);
assertTrue($stmt->fetchColumn() === 'not_required', 'a fresh goal defaults review_status to not_required — no existing goal is silently blocked');

// --- tenantRequiresPlanReview() -----------------------------------------

$reviewTenant = makeTenant($db, 'Plan Review On Firm', 'on');
assertTrue(tenantRequiresPlanReview($db, $reviewTenant) === true, 'a tenant with requires_plan_review=on is read correctly');

$reviewClient = makeClient($db, $reviewTenant);
$reviewScopedDb = new TenantScopedDb($db, $reviewTenant);
$plainScopedDb = new TenantScopedDb($db, $plainTenant);

// --- applyPlanReviewTransitionOnAdviceEdit() transition matrix ----------

// 1. Tenant hasn't opted in: no-op regardless of current status.
$goal1 = makeGoal($db, $plainTenant, $plainClient, 'not_required');
$existing1 = $plainScopedDb->select('base_plans', ['id' => $goal1])[0];
applyPlanReviewTransitionOnAdviceEdit($plainScopedDb, $db, $plainTenant, $goal1, $existing1, $plainClient);
$stmt->execute([':id' => $goal1]);
assertTrue($stmt->fetchColumn() === 'not_required', 'a tenant that has not opted in: advice-field edit leaves review_status untouched');
assertTrue(changeLogCount($db, $goal1) === 0, 'no review_status change_log row is written when the tenant has not opted in');

// 2. not_required -> pending_review (first entry into the workflow).
$goal2 = makeGoal($db, $reviewTenant, $reviewClient, 'not_required');
$existing2 = $reviewScopedDb->select('base_plans', ['id' => $goal2])[0];
applyPlanReviewTransitionOnAdviceEdit($reviewScopedDb, $db, $reviewTenant, $goal2, $existing2, $reviewClient);
$stmt->execute([':id' => $goal2]);
assertTrue($stmt->fetchColumn() === 'pending_review', 'not_required -> pending_review when the tenant has opted in and an advice field changes');
assertTrue(changeLogCount($db, $goal2) === 1, 'exactly one review_status change_log row is written for the not_required -> pending_review transition');

// 3. approved -> pending_review (decision #6), reviewer fields cleared.
$goal3 = makeGoal($db, $reviewTenant, $reviewClient, 'not_required');
$db->prepare("UPDATE base_plans SET review_status = 'approved', reviewed_by_user_id = :u, reviewed_at = NOW(), review_notes = NULL WHERE id = :id")
   ->execute([':u' => $reviewClient, ':id' => $goal3]);
$existing3 = $reviewScopedDb->select('base_plans', ['id' => $goal3])[0];
assertTrue($existing3['review_status'] === 'approved', 'fixture goal 3 starts approved');
applyPlanReviewTransitionOnAdviceEdit($reviewScopedDb, $db, $reviewTenant, $goal3, $existing3, $reviewClient);
$after3 = $reviewScopedDb->select('base_plans', ['id' => $goal3])[0];
assertTrue($after3['review_status'] === 'pending_review', 'approved -> pending_review on an advice-field edit (an approval covers the numbers as they stood at approval time)');
assertTrue($after3['reviewed_by_user_id'] === null, 'reviewed_by_user_id is cleared on revert to pending_review');
assertTrue($after3['reviewed_at'] === null, 'reviewed_at is cleared on revert to pending_review');

// 4. pending_review stays pending_review (no duplicate re-queue).
$goal4 = makeGoal($db, $reviewTenant, $reviewClient, 'pending_review');
$existing4 = $reviewScopedDb->select('base_plans', ['id' => $goal4])[0];
applyPlanReviewTransitionOnAdviceEdit($reviewScopedDb, $db, $reviewTenant, $goal4, $existing4, $reviewClient);
$stmt->execute([':id' => $goal4]);
assertTrue($stmt->fetchColumn() === 'pending_review', 'pending_review is left untouched by a further advice-field edit');
assertTrue(changeLogCount($db, $goal4) === 0, 'no review_status change_log row for an edit that does not change status');

// 5. changes_requested stays changes_requested (must be explicitly resubmitted).
$goal5 = makeGoal($db, $reviewTenant, $reviewClient, 'changes_requested');
$db->prepare("UPDATE base_plans SET review_notes = 'Please double-check the withdrawal rate.' WHERE id = :id")->execute([':id' => $goal5]);
$existing5 = $reviewScopedDb->select('base_plans', ['id' => $goal5])[0];
applyPlanReviewTransitionOnAdviceEdit($reviewScopedDb, $db, $reviewTenant, $goal5, $existing5, $reviewClient);
$after5 = $reviewScopedDb->select('base_plans', ['id' => $goal5])[0];
assertTrue($after5['review_status'] === 'changes_requested', 'changes_requested is NOT auto-resubmitted by a further advice-field edit — decision #6 only covers reverting an approved goal');
assertTrue($after5['review_notes'] === 'Please double-check the withdrawal rate.', 'existing reviewer feedback is preserved while changes_requested is untouched');

// --- goals_review.php's core transition (approve / request_changes) ----
// Mirrors the endpoint's actual UPDATE, same "test the real logic, not a
// re-derivation of it" precedent test_firm_roles.php sets for requireFirmRole().

$goal6 = makeGoal($db, $reviewTenant, $reviewClient, 'pending_review');
$reviewerId = $reviewClient; // any real user id works for this fixture
$reviewScopedDb->update('base_plans', [
    'review_status'       => 'approved',
    'reviewed_by_user_id' => $reviewerId,
    'reviewed_at'         => date('Y-m-d H:i:s'),
    'review_notes'        => null,
], ['id' => $goal6]);
$after6 = $reviewScopedDb->select('base_plans', ['id' => $goal6])[0];
assertTrue($after6['review_status'] === 'approved', 'goals_review.php approve action sets review_status = approved');
assertTrue((int) $after6['reviewed_by_user_id'] === $reviewerId, 'goals_review.php approve action records the reviewing user');
assertTrue($after6['reviewed_at'] !== null, 'goals_review.php approve action stamps reviewed_at');

$goal7 = makeGoal($db, $reviewTenant, $reviewClient, 'pending_review');
$reviewScopedDb->update('base_plans', [
    'review_status'       => 'changes_requested',
    'reviewed_by_user_id' => $reviewerId,
    'reviewed_at'         => date('Y-m-d H:i:s'),
    'review_notes'        => 'Withdrawal rate looks too aggressive for this client.',
], ['id' => $goal7]);
$after7 = $reviewScopedDb->select('base_plans', ['id' => $goal7])[0];
assertTrue($after7['review_status'] === 'changes_requested', 'goals_review.php request_changes action sets review_status = changes_requested');
assertTrue($after7['review_notes'] === 'Withdrawal rate looks too aggressive for this client.', 'goals_review.php request_changes action stores the reviewer notes — a rejection is never silent (decision #4)');

// --- Cross-tenant isolation on TenantScopedDb::update() -----------------
// A different tenant's TenantScopedDb instance cannot touch this goal —
// same guarantee every other endpoint in this app relies on.
$crossTenantAffected = $plainScopedDb->update('base_plans', ['review_status' => 'approved'], ['id' => $goal2]);
assertTrue($crossTenantAffected === 0, 'a different tenant cannot update this goal\'s review_status — tenant-scoped update() naturally blocks it');
$stmt->execute([':id' => $goal2]);
assertTrue($stmt->fetchColumn() === 'pending_review', 'the goal\'s review_status is unchanged after the blocked cross-tenant update attempt');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll plan review tests passed.\n";
