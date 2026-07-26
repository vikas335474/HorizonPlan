<?php
declare(strict_types=1);

// Jr -> Sr Advisor Plan-Approval Workflow — the goal's own advisor (any
// firm_role) puts a goal into the review queue. Most goals get here
// automatically the moment an advice field changes (see PlanReview.php's
// applyPlanReviewTransitionOnAdviceEdit(), wired into goals_update.php /
// goals_apply_template.php / goals_create.php) — this endpoint exists for
// the cases automatic transition doesn't cover: a goal still sitting at
// 'not_required' because the tenant only just turned review on, and
// resubmitting after a reviewer requested changes (decision #6 deliberately
// does NOT auto-resubmit on every subsequent edit — see that function's
// docblock).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PlanReview.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // super_admin also passes, per verifyAccess()
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$goalId = (int) ($input['goal_id'] ?? 0);
if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'goal_id is required.']);
    exit();
}

if (!tenantRequiresPlanReview($db, $tenantId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Plan review is not enabled for this firm.']);
    exit();
}

$rows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $rows[0];
$currentStatus = $goal['review_status'];

if ($currentStatus === 'pending_review') {
    // Idempotent — already in the queue, nothing to do.
    echo json_encode(['status' => 'success', 'goal_id' => $goalId, 'review_status' => 'pending_review']);
    exit();
}

$scopedDb->update('base_plans', [
    'review_status'       => 'pending_review',
    'reviewed_by_user_id' => null,
    'reviewed_at'         => null,
    'review_notes'        => null,
], ['id' => $goalId]);

$scopedDb->logChange('base_plan', $goalId, 'review_status', $currentStatus, 'pending_review', $userId);

echo json_encode(['status' => 'success', 'goal_id' => $goalId, 'review_status' => 'pending_review']);
