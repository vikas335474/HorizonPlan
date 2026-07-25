<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId = (int) ($input['id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$rows = $scopedDb->select('client_portfolio_items', ['id' => $itemId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Portfolio item not found.']);
    exit();
}
$existing = $rows[0];

// item_kind is not editable here — changing an asset into a liability (or
// back) is a different fact than editing one, and would need bucket to be
// re-validated against it; simplest and safest is "delete and re-add" for
// that case, same as most simple ledger UIs.
$updateData = [];
if (array_key_exists('bucket', $input) && $existing['item_kind'] === 'asset') {
    if (!in_array($input['bucket'], ['liquid', 'locked'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'bucket must be liquid or locked.']);
        exit();
    }
    $updateData['bucket'] = $input['bucket'];
}
if (array_key_exists('category', $input)) {
    $category = trim((string) $input['category']);
    if ($category === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'category cannot be empty.']);
        exit();
    }
    $updateData['category'] = $category;
}
if (array_key_exists('description', $input)) {
    $updateData['description'] = $input['description'] !== null ? trim((string) $input['description']) : null;
}
if (array_key_exists('value', $input)) {
    if (!is_numeric($input['value']) || (float) $input['value'] < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'value must be a non-negative number.']);
        exit();
    }
    $updateData['value'] = (float) $input['value'];
}

if (empty($updateData)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes.', 'item_id' => $itemId]);
    exit();
}

$scopedDb->update('client_portfolio_items', $updateData, ['id' => $itemId]);

echo json_encode(['status' => 'success', 'item_id' => $itemId, 'changed_fields' => array_keys($updateData)]);
