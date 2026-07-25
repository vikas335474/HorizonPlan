<?php
declare(strict_types=1);

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
$session = verifyAccess($db, 'advisor'); // advisor records the client's portfolio, same pattern as goals_create.php
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
$itemKind = (string) ($input['item_kind'] ?? '');
$bucket = $input['bucket'] ?? null;
$category = trim((string) ($input['category'] ?? ''));
$description = isset($input['description']) ? trim((string) $input['description']) : null;
$value = $input['value'] ?? null;

if ($clientId <= 0 || !in_array($itemKind, ['asset', 'liability'], true) || $category === '' || $value === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id, item_kind (asset|liability), category, and value are required.']);
    exit();
}
if (!is_numeric($value) || (float) $value < 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'value must be a non-negative number.']);
    exit();
}

// bucket only means anything for an asset (liquid vs locked, docs/05 item 3);
// a liability has no bucket — silently ignore/null it out rather than storing
// a value that doesn't apply, same posture goals_create.php takes with
// withdrawal_rate/drawdown_return_rate for non-retirement goal types.
if ($itemKind === 'asset') {
    if (!in_array($bucket, ['liquid', 'locked'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'bucket (liquid|locked) is required for an asset.']);
        exit();
    }
} else {
    $bucket = null;
}

$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$id = $scopedDb->insert('client_portfolio_items', [
    'client_id'          => $clientId,
    'item_kind'          => $itemKind,
    'bucket'             => $bucket,
    'category'           => $category,
    'description'        => $description,
    'value'              => (float) $value,
    'created_by_user_id' => $userId,
]);

echo json_encode(['status' => 'success', 'item_id' => $id]);
