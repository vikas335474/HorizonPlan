<?php
declare(strict_types=1);

// Cash-flow module (docs/10): remove one income/expense line item. Advisor-only,
// tenant-scoped — delete() carries the tenant filter, so an id belonging to
// another firm simply affects 0 rows (→ 404), never a cross-tenant delete.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
// Self-serve individual tier (sql/033): advisor-only in a firm, and
// additionally self-service for an individual writing their OWN data in a
// personal tenant. verifySelfServiceWrite() refuses an advisor-managed
// client exactly as before — see api/lib/SelfService.php.
$session = verifySelfServiceWrite($db);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId = (int) ($input['id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$affected = $scopedDb->delete('cash_flow_items', ['id' => $itemId]);
if ($affected === 0) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Cash-flow item not found.']);
    exit();
}

echo json_encode(['status' => 'success', 'item_id' => $itemId]);
