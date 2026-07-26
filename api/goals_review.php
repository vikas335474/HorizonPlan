<?php
declare(strict_types=1);

// Jr -> Sr Advisor Plan-Approval Workflow — a Sr advisor/firm_admin approves
// or requests changes on a goal currently in 'pending_review' (decision #5:
// any sr_advisor/firm_admin in the firm may review, no assigned-reviewer
// routing; same requireFirmRole() model templates_approve.php and
// risk_question_set_approve.php already use).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // super_admin also passes, per verifyAccess()
requireFirmRole($db, $session, ['sr_advisor', 'firm_admin']);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$goalId = (int) ($input['goal_id'] ?? 0);
$action = (string) ($input['action'] ?? '');
$notes = isset($input['notes']) ? trim((string) $input['notes']) : '';

if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'goal_id is required.']);
    exit();
}
if (!in_array($action, ['approve', 'request_changes'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "action must be 'approve' or 'request_changes'."]);
    exit();
}
if ($action === 'request_changes' && $notes === '') {
    // Decision #4: three states specifically so a rejection isn't silent —
    // notes are how that feedback actually reaches the jr advisor.
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'notes are required when requesting changes.']);
    exit();
}

$rows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $rows[0];

if ($goal['review_status'] !== 'pending_review') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This goal is not pending review.']);
    exit();
}

$newStatus = $action === 'approve' ? 'approved' : 'changes_requested';

$scopedDb->update('base_plans', [
    'review_status'       => $newStatus,
    'reviewed_by_user_id' => $userId,
    'reviewed_at'         => date('Y-m-d H:i:s'),
    'review_notes'        => $action === 'request_changes' ? $notes : null,
], ['id' => $goalId]);

$scopedDb->logChange('base_plan', $goalId, 'review_status', 'pending_review', $newStatus, $userId);

echo json_encode([
    'status'        => 'success',
    'goal_id'       => $goalId,
    'review_status' => $newStatus,
]);
