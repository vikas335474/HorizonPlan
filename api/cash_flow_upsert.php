<?php
declare(strict_types=1);

// Cash-flow module (docs/10): create or update one income/expense line item.
// Advisor-only (verifyAccess), tenant-scoped. The client must be in the acting
// advisor's tenant (404 otherwise) — the app never fabricates a figure, the
// advisor supplies every number.
//
// Upsert shape: an `id` in the body updates that row (must be in this tenant);
// no `id` creates a new one. Mirrors the single-endpoint create/update pattern
// used elsewhere, kept tenant-scoped via TenantScopedDb (never an inline WHERE).

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
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId = isset($input['id']) ? (int) $input['id'] : 0;
$isUpdate = $itemId > 0;

$clientId = (int) ($input['client_id'] ?? 0);
// A personal user always writes their own data; any client_id in the body
// is ignored rather than trusted, same posture as the client-facing reads.
$clientId = resolveSelfServiceClientId($session, $clientId);
$kind = (string) ($input['kind'] ?? '');
$label = trim((string) ($input['label'] ?? ''));
$amount = $input['amount'] ?? null;
$cadence = (string) ($input['cadence'] ?? 'monthly');
$category = trim((string) ($input['category'] ?? ''));
// is_active is optional; defaults to active on create, unchanged-shape on update.
$isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : 1;

if (!in_array($kind, ['income', 'expense'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'kind must be income or expense.']);
    exit();
}
if (!in_array($cadence, ['monthly', 'annual'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'cadence must be monthly or annual.']);
    exit();
}
if ($label === '' || mb_strlen($label) > 191) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'label is required (max 191 characters).']);
    exit();
}
if ($category === '' || mb_strlen($category) > 50) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'category is required (max 50 characters).']);
    exit();
}
if (!is_numeric($amount) || (float) $amount < 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'amount must be a non-negative number.']);
    exit();
}

$fields = [
    'kind'      => $kind,
    'label'     => $label,
    'amount'    => (float) $amount,
    'cadence'   => $cadence,
    'category'  => $category,
    'is_active' => $isActive,
];

if ($isUpdate) {
    // The row must already be in this tenant — update() is tenant-scoped, so a
    // 0-row result means it's not ours (or doesn't exist): 404, never a leak.
    $affected = $scopedDb->update('cash_flow_items', $fields, ['id' => $itemId]);
    if ($affected === 0) {
        // Could also mean an identical no-op update; disambiguate with a scoped read.
        if ($scopedDb->select('cash_flow_items', ['id' => $itemId]) === []) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Cash-flow item not found.']);
            exit();
        }
    }
    echo json_encode(['status' => 'success', 'item_id' => $itemId]);
    exit();
}

// Create: the client must be in this tenant.
if ($clientId <= 0 || $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$fields['client_id'] = $clientId;
$fields['created_by_user_id'] = $userId;
$id = $scopedDb->insert('cash_flow_items', $fields);

echo json_encode(['status' => 'success', 'item_id' => $id]);
