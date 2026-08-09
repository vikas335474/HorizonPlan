<?php
declare(strict_types=1);

// docs/12 Prompt D-1 · Reconcile-by-holding CAS/KFintech/MFCentral CSV
// import. Rewritten from the original always-INSERT behavior (docs/11-era),
// which silently duplicated a client's whole mutual fund book on a second
// upload — the exact bug this session fixes.
//
// This endpoint APPLIES a reconciliation; it does not compute the preview
// the user reviewed (that's client_portfolio_reconcile_preview.php). The
// diff is deliberately RECOMPUTED here from `items` rather than trusting a
// client-submitted diff — a browser could otherwise claim any old_value/
// new_value it liked. `remove_ids` is the one piece of real user judgment
// this endpoint takes on faith, and it's the safest possible one: it only
// ever deletes an id that (a) belongs to this client's own
// source='cas_import' rows and (b) is independently recomputed as
// "flagged missing from this statement" — never anything the user didn't
// see flagged, never a manually-entered row.
//
// Every imported/updated row is forced to item_kind='asset', bucket='liquid',
// category='mutual_fund', source='cas_import' — a CAS/MFCentral statement
// only ever lists mutual fund folios (see PortfolioReconcile.php's header
// for why reconciliation only ever touches cas_import-sourced rows).
//
// The whole reconciliation — updates, inserts, and any confirmed removals —
// applies in one transaction: either the whole statement lands, or none of
// it does, so a bad row can't leave a half-reconciled portfolio.
//
// POST only. Same self-service-write gate as every other write here.
// Input (JSON body): client_id, items: [{description, value, folio_number?}],
// remove_ids?: [int,...] (ids the user explicitly confirmed to remove, from
// the preview's to_flag list).
// Output: {status, updated_count, added_count, removed_count}.
// Errors: 400 (validation), 404 (client not in tenant), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/PortfolioReconcile.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifySelfServiceWrite($db);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
$clientId = resolveSelfServiceClientId($session, $clientId);
$items = $input['items'] ?? null;
$removeIds = $input['remove_ids'] ?? [];

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'items must be a non-empty array.']);
    exit();
}
if (count($items) > 500) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Cannot import more than 500 items at once.']);
    exit();
}
if (!is_array($removeIds)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'remove_ids must be an array.']);
    exit();
}
$removeIds = array_map('intval', $removeIds);

$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

// Validate every row before writing anything — same "fail before touching
// the DB" posture as goals_create.php's field validation.
$clean = [];
foreach ($items as $i => $item) {
    $description = trim((string) ($item['description'] ?? ''));
    $value = $item['value'] ?? null;
    $folio = isset($item['folio_number']) && trim((string) $item['folio_number']) !== ''
        ? trim((string) $item['folio_number'])
        : null;

    if ($description === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Row " . ($i + 1) . ": a fund/scheme name is required."]);
        exit();
    }
    if (!is_numeric($value) || (float) $value < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Row " . ($i + 1) . " (\"$description\"): value must be a non-negative number."]);
        exit();
    }
    $clean[] = ['description' => $description, 'value' => (float) $value, 'folio_number' => $folio];
}

$existingCasRows = array_map(
    static fn(array $r): array => [
        'id'            => (int) $r['id'],
        'description'   => $r['description'],
        'value'         => (float) $r['value'],
        'folio_number'  => $r['folio_number'],
    ],
    $scopedDb->select('client_portfolio_items', ['client_id' => $clientId, 'source' => 'cas_import'])
);

$diff = computePortfolioReconciliation($existingCasRows, $clean);

// Defense in depth: only ever delete an id that THIS recomputed diff itself
// flagged as missing — a client-submitted remove_ids value can never reach
// beyond what the server independently derives as safe to remove.
$confirmedRemovals = confirmedPortfolioRemovalIds($diff, $removeIds);

$db->beginTransaction();
try {
    foreach ($diff['to_update'] as $update) {
        $scopedDb->update('client_portfolio_items', [
            'value'       => $update['new_value'],
            'description' => $update['new_description'],
        ], ['id' => $update['id']]);
    }
    foreach ($diff['to_add'] as $add) {
        $scopedDb->insert('client_portfolio_items', [
            'client_id'          => $clientId,
            'item_kind'          => 'asset',
            'bucket'             => 'liquid',
            'category'           => 'mutual_fund',
            'source'             => 'cas_import',
            'description'        => $add['description'],
            'value'              => $add['value'],
            'folio_number'       => $add['folio_number'],
            'created_by_user_id' => $userId,
        ]);
    }
    foreach ($confirmedRemovals as $id) {
        $scopedDb->delete('client_portfolio_items', ['id' => $id]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Import failed — no changes were saved.']);
    exit();
}

echo json_encode([
    'status'        => 'success',
    'updated_count' => count($diff['to_update']),
    'added_count'   => count($diff['to_add']),
    'removed_count' => count($confirmedRemovals),
]);
