<?php
declare(strict_types=1);

// Client portfolio ledger: partial-update ONE line item. POST or PUT,
// advisor-only, tenant-scoped (404 if the row isn't this tenant's). Only the
// fields sent are touched. item_kind is intentionally NOT editable — flipping an
// asset to a liability is "delete and re-add", not an edit. NAV tracking fields
// stay both-or-neither and are only re-validated when the request actually
// touches one of them; changing the scheme re-seeds value from mf_nav_cache (no
// live AMFI call). Output: {status, item_id, changed_fields[]}. Errors:
// 400 (validation), 404 (not found), 405 (other method).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

// NAV tracking fields — both-or-neither, same rule as client_portfolio_create.php.
// Only validated/applied when the request actually touches one of them; an
// edit to, say, just `description` leaves NAV tracking completely untouched.
if (array_key_exists('amfi_scheme_code', $input) || array_key_exists('units_held', $input)) {
    $newSchemeCode = array_key_exists('amfi_scheme_code', $input)
        ? (trim((string) ($input['amfi_scheme_code'] ?? '')) !== '' ? trim((string) $input['amfi_scheme_code']) : null)
        : $existing['amfi_scheme_code'];
    $newUnitsHeld = array_key_exists('units_held', $input)
        ? ($input['units_held'] !== null && $input['units_held'] !== '' ? $input['units_held'] : null)
        : $existing['units_held'];

    if (($newSchemeCode !== null) !== ($newUnitsHeld !== null)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'amfi_scheme_code and units_held must both be set, or neither.']);
        exit();
    }
    if ($newSchemeCode !== null && (!preg_match('/^[A-Za-z0-9]{1,20}$/', $newSchemeCode) || !is_numeric($newUnitsHeld) || (float) $newUnitsHeld <= 0)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'amfi_scheme_code must be alphanumeric (max 20 chars) and units_held must be a positive number.']);
        exit();
    }

    $updateData['amfi_scheme_code'] = $newSchemeCode;
    $updateData['units_held'] = $newSchemeCode !== null ? (float) $newUnitsHeld : null;

    // Re-seed value from whatever is currently cached for the (possibly new)
    // scheme code, same as create — no live AMFI fetch inside this request.
    if ($newSchemeCode !== null && !array_key_exists('value', $input)) {
        $cacheStmt = $db->prepare('SELECT nav_value FROM mf_nav_cache WHERE amfi_scheme_code = :code');
        $cacheStmt->execute([':code' => $newSchemeCode]);
        $navValue = $cacheStmt->fetchColumn();
        if ($navValue !== false) {
            $updateData['value'] = round((float) $newUnitsHeld * (float) $navValue, 2);
        }
    }
}

if (empty($updateData)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes.', 'item_id' => $itemId]);
    exit();
}

$scopedDb->update('client_portfolio_items', $updateData, ['id' => $itemId]);

echo json_encode(['status' => 'success', 'item_id' => $itemId, 'changed_fields' => array_keys($updateData)]);
