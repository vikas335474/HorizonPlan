<?php
declare(strict_types=1);

// Jr -> Sr Advisor Plan-Approval Workflow — the review queue: every goal in
// this tenant currently sitting in the workflow (i.e. review_status !=
// 'not_required'), joined to the client's email so the queue is readable
// without a second round trip per row. TenantScopedDb::select() can't express
// the JOIN, so this drops to a raw tenant-scoped query — same precedent
// change_log_list.php and clients_list.php already set for the identical
// reason.
//
// Readable by any advisor/super_admin in the tenant (a jr_advisor can see the
// status of their own submissions, not just sr_advisor/firm_admin) — the
// approve/request-changes action itself is what's gated to sr_advisor/
// firm_admin, in goals_review.php.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'super_admin']);
$tenantId = (int) $session['tenant_id'];

$ALL_STATUSES = ['pending_review', 'changes_requested', 'approved', 'not_required'];

$statusParam = (string) ($_GET['status'] ?? 'active');
if ($statusParam === 'active') {
    $statusFilter = ['pending_review', 'changes_requested'];
} elseif ($statusParam === 'all') {
    $statusFilter = ['pending_review', 'changes_requested', 'approved'];
} else {
    $statusFilter = array_values(array_filter(array_map('trim', explode(',', $statusParam))));
    foreach ($statusFilter as $s) {
        if (!in_array($s, $ALL_STATUSES, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Unknown status '$s'."]);
            exit();
        }
    }
    if ($statusFilter === []) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'status must not be empty.']);
        exit();
    }
}

$placeholders = [];
$params = [':tenant_id' => $tenantId];
foreach ($statusFilter as $i => $s) {
    $key = ":status_$i";
    $placeholders[] = $key;
    $params[$key] = $s;
}

$sql = "SELECT
            bp.id, bp.goal_label, bp.goal_type, bp.client_id, bp.review_status,
            bp.reviewed_by_user_id, bp.reviewed_at, bp.review_notes,
            cu.email AS client_email,
            ru.email AS reviewed_by_email
        FROM base_plans bp
        LEFT JOIN users cu ON cu.id = bp.client_id
        LEFT JOIN users ru ON ru.id = bp.reviewed_by_user_id
        WHERE bp.tenant_id = :tenant_id
          AND bp.review_status IN (" . implode(', ', $placeholders) . ")
        ORDER BY FIELD(bp.review_status, 'pending_review', 'changes_requested', 'approved', 'not_required'), bp.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = array_map(static function (array $r): array {
    return [
        'id'                  => (int) $r['id'],
        'goal_label'          => $r['goal_label'],
        'goal_type'           => $r['goal_type'],
        'client_id'           => (int) $r['client_id'],
        'client_email'        => $r['client_email'],
        'review_status'       => $r['review_status'],
        'reviewed_by_user_id' => $r['reviewed_by_user_id'] !== null ? (int) $r['reviewed_by_user_id'] : null,
        'reviewed_by_email'   => $r['reviewed_by_email'],
        'reviewed_at'         => $r['reviewed_at'],
        'review_notes'        => $r['review_notes'],
    ];
}, $rows);

echo json_encode(['status' => 'success', 'items' => $items]);
