<?php
declare(strict_types=1);

// Jr -> Sr Advisor Plan-Approval Workflow. Shared by goals_create.php,
// goals_update.php, goals_apply_template.php, goals_submit_review.php, and
// goals_review.php so the transition rules can't drift between entry points —
// same "one shared helper" precedent as GoalFieldValidation.php.
//
// Scope, per the decisions this session confirmed before writing any of this:
//   - Only base_plans has a review workflow — sub_scenarios are out of scope.
//   - Only the "advice" fields (withdrawal_rate, drawdown_return_rate, and
//     template application, which itself only ever touches
//     drawdown_return_rate) put a goal into the workflow. Cosmetic/logistic
//     edits (goal_label, target_date, ...) never touch review_status.
//   - There is no hard gate anywhere yet — review_status is a visible signal
//     plus an audit trail, not a block on PlanReport.jsx/MeetingMode.jsx.

/** Advice fields whose change can trigger a review-state transition. */
const PLAN_REVIEW_ADVICE_FIELDS = ['withdrawal_rate', 'drawdown_return_rate'];

/**
 * Whether a tenant has opted into the plan-review workflow at all. Default
 * off (migration 026) — every existing firm is unaffected until a
 * firm_admin/super_admin explicitly turns this on via tenant_update.php.
 */
function tenantRequiresPlanReview(PDO $db, int $tenantId): bool
{
    $stmt = $db->prepare('SELECT requires_plan_review FROM tenants WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tenantId]);
    $row = $stmt->fetch();
    return $row !== false && $row['requires_plan_review'] === 'on';
}

/**
 * Apply the review-state transition after one or more advice fields changed
 * on an existing goal (goals_update.php's partial-update loop, or
 * goals_apply_template.php's drawdown_return_rate write). No-op entirely if
 * the tenant hasn't opted into plan review.
 *
 * Transition rules (decision #6, and its natural extension to a goal that has
 * never entered the workflow yet):
 *   - 'approved'         -> 'pending_review' (an approval covers the numbers
 *                           as they stood at approval time, same precedent as
 *                           templates_customize.php reverting an approved fork
 *                           to draft on edit) — reviewer fields cleared.
 *   - 'not_required'     -> 'pending_review' (the goal is entering the
 *                           workflow for the first time: either the tenant
 *                           just turned review on, or this is the first advice
 *                           edit since it did).
 *   - 'pending_review'   -> unchanged (already awaiting review).
 *   - 'changes_requested' -> unchanged. A reviewer already acted on this goal;
 *                           the jr advisor must explicitly resubmit via
 *                           goals_submit_review.php once they're done
 *                           addressing the feedback, rather than every
 *                           keystroke silently re-queuing it.
 *
 * Writes its own change_log row when the status actually changes, same
 * "mutation + audit in the same block" pattern as the cascade in
 * goals_update.php.
 */
function applyPlanReviewTransitionOnAdviceEdit(
    TenantScopedDb $scopedDb,
    PDO $db,
    int $tenantId,
    int $goalId,
    array $existingGoal,
    int $userId
): void {
    if (!tenantRequiresPlanReview($db, $tenantId)) {
        return;
    }

    $currentStatus = $existingGoal['review_status'];
    if ($currentStatus !== 'approved' && $currentStatus !== 'not_required') {
        return; // pending_review / changes_requested: no automatic change
    }

    $scopedDb->update('base_plans', [
        'review_status'       => 'pending_review',
        'reviewed_by_user_id' => null,
        'reviewed_at'         => null,
        'review_notes'        => null,
    ], ['id' => $goalId]);

    $scopedDb->logChange('base_plan', $goalId, 'review_status', $currentStatus, 'pending_review', $userId);
}
