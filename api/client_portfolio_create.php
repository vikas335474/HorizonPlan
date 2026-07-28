<?php
declare(strict_types=1);

// Client portfolio ledger (docs/05 item 3 / docs/10 P0): add ONE asset or
// liability row to a client's balance sheet. POST, advisor-only (verifyAccess
// 'advisor'; super_admin also passes) — the advisor records the client's
// holdings. Tenant-scoped via TenantScopedDb; the target client must be a
// 'client' in the acting advisor's tenant (404 otherwise).
//
// Inputs (JSON body): client_id, item_kind (asset|liability), category, and
// either a plain `value` or a NAV-tracked pair (amfi_scheme_code + units_held,
// both-or-neither). `bucket` (liquid|locked) is required for an asset, ignored
// and nulled for a liability. A NAV-tracked row seeds its value from whatever
// mf_nav_cache already holds for that scheme (never a live AMFI call here); a
// brand-new scheme stays "price pending" (0) until the daily cron fills it.
// Output: {status, item_id}. Errors: 400 (validation), 404 (client not in
// tenant), 405 (non-POST).

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
$schemeCode = isset($input['amfi_scheme_code']) && trim((string) $input['amfi_scheme_code']) !== ''
    ? trim((string) $input['amfi_scheme_code'])
    : null;
$unitsHeld = $input['units_held'] ?? null;

// NAV tracking is both-or-neither, same precedent as liquid/locked corpus
// composition on base_plans — a row can't be "half" NAV-tracked.
if (($schemeCode !== null) !== ($unitsHeld !== null && $unitsHeld !== '')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'amfi_scheme_code and units_held must both be provided, or neither.']);
    exit();
}
if ($schemeCode !== null && (!preg_match('/^[A-Za-z0-9]{1,20}$/', $schemeCode) || !is_numeric($unitsHeld) || (float) $unitsHeld <= 0)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'amfi_scheme_code must be alphanumeric (max 20 chars) and units_held must be a positive number.']);
    exit();
}
// value is optional once NAV-tracked (the price sync populates it once the
// scheme is cached — "price pending" until then, per CLAUDE.md); still
// required for a plain manually-entered item.
if ($schemeCode === null && $value === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'value is required unless the item is NAV-tracked (amfi_scheme_code + units_held).']);
    exit();
}
if ($value !== null && (!is_numeric($value) || (float) $value < 0)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'value must be a non-negative number.']);
    exit();
}
if ($clientId <= 0 || !in_array($itemKind, ['asset', 'liability'], true) || $category === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id, item_kind (asset|liability), and category are required.']);
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

// A NAV-tracked row's value: seed it from whatever is already cached for
// this scheme code right now (e.g. a second client holding a fund already
// tracked elsewhere on the platform) — never a live AMFI fetch inside this
// request. A genuinely brand-new scheme code has no cache entry yet and
// stays "price pending" (value 0) until the next daily cron run.
$resolvedValue = (float) ($value ?? 0);
if ($schemeCode !== null) {
    $cacheStmt = $db->prepare('SELECT nav_value FROM mf_nav_cache WHERE amfi_scheme_code = :code');
    $cacheStmt->execute([':code' => $schemeCode]);
    $navValue = $cacheStmt->fetchColumn();
    if ($navValue !== false) {
        $resolvedValue = round((float) $unitsHeld * (float) $navValue, 2);
    }
}

$id = $scopedDb->insert('client_portfolio_items', [
    'client_id'          => $clientId,
    'item_kind'          => $itemKind,
    'bucket'             => $bucket,
    'category'           => $category,
    'description'        => $description,
    'value'              => $resolvedValue,
    'amfi_scheme_code'   => $schemeCode,
    'units_held'         => $schemeCode !== null ? (float) $unitsHeld : null,
    'created_by_user_id' => $userId,
]);

echo json_encode(['status' => 'success', 'item_id' => $id]);
