<?php
declare(strict_types=1);

// docs/09 Session 6 — read-only audit trail view. change_log already captures
// every base_plans/sub_scenarios mutation (CLAUDE.md non-negotiable rule #4);
// nothing surfaced it to a human until now. GET only, no write/revert path —
// reverting a value goes through the normal update endpoints, which write
// their own new change_log row.
//
// Tenant scoping follows goals_list.php's own precedent for a super_admin
// session: behaves like an advisor of their own tenant by default. The one
// escape hatch is an explicit ?tenant_id= param, super_admin-only, same
// pattern tenant_detail.php already uses for cross-tenant reads.
//
// TenantScopedDb::select() can't express the JOIN to users.email, so this
// drops to a raw tenant-scoped query — the same precedent clients_list.php
// already set.

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
if ($session['role'] === 'super_admin' && isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '') {
    $tenantId = (int) $_GET['tenant_id'];
    if ($tenantId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'tenant_id must be a positive integer.']);
        exit();
    }
}

$where = ['cl.tenant_id = :tenant_id'];
$params = [':tenant_id' => $tenantId];

if (isset($_GET['entity_type']) && $_GET['entity_type'] !== '') {
    $entityType = (string) $_GET['entity_type'];
    if (!in_array($entityType, ['base_plan', 'sub_scenario'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "entity_type must be 'base_plan' or 'sub_scenario'."]);
        exit();
    }
    $where[] = 'cl.entity_type = :entity_type';
    $params[':entity_type'] = $entityType;
}

if (isset($_GET['entity_id']) && $_GET['entity_id'] !== '') {
    $entityId = (int) $_GET['entity_id'];
    if ($entityId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'entity_id must be a positive integer.']);
        exit();
    }
    $where[] = 'cl.entity_id = :entity_id';
    $params[':entity_id'] = $entityId;
}

// Pagination — no existing list endpoint in this codebase paginates yet
// (goals_list.php/clients_list.php/etc. all return unbounded result sets
// against tables that stay small per-tenant); change_log is the first one
// expected to grow indefinitely, so this session establishes page/per_page
// as the convention rather than inventing cursor-based paging nobody else
// uses yet.
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
if ($perPage < 1) {
    $perPage = 25;
}
$perPage = min($perPage, 100);
$offset = ($page - 1) * $perPage;

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM change_log cl WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT
        cl.id, cl.entity_type, cl.entity_id, cl.field_changed,
        cl.old_value, cl.new_value, cl.changed_by_user_id, cl.changed_at,
        u.email AS changed_by_email
     FROM change_log cl
     LEFT JOIN users u ON u.id = cl.changed_by_user_id
     WHERE $whereSql
     ORDER BY cl.changed_at DESC, cl.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$entries = array_map(static function (array $r): array {
    return [
        'id'               => (int) $r['id'],
        'entity_type'      => $r['entity_type'],
        'entity_id'        => (int) $r['entity_id'],
        'field_changed'    => $r['field_changed'],
        'old_value'        => $r['old_value'],
        'new_value'        => $r['new_value'],
        'changed_by_user_id' => (int) $r['changed_by_user_id'],
        'changed_by_email'  => $r['changed_by_email'],
        'changed_at'       => $r['changed_at'],
    ];
}, $rows);

echo json_encode([
    'status'    => 'success',
    'entries'   => $entries,
    'total'     => $total,
    'page'      => $page,
    'per_page'  => $perPage,
]);
