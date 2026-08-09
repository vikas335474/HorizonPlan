<?php
declare(strict_types=1);

// docs/12 Prompt D-1 · Preview what a CAS/MFCentral CSV re-import WOULD do,
// before anything is written — the fix for client_portfolio_import.php's old
// behavior of unconditionally inserting every row (silently duplicating a
// client's whole mutual fund book on a second upload).
//
// POST, same self-service-write gate as the import it precedes (previewing
// is read-only, but only the actor who could then act on it needs it, and
// gating it identically keeps the abuse surface the same shape as the write
// endpoint it's paired with). Tenant-scoped. Read-only: no row is ever
// written by this endpoint.
//
// Reconciliation is scoped to this client's source='cas_import' rows ONLY —
// see api/lib/PortfolioReconcile.php's header for why a hand-entered holding
// must never be matched/updated/flagged by an import, even on a name
// collision.
//
// Input (JSON body): client_id, items: [{description, value, folio_number?}].
// Output: {status, diff: {to_update[], to_add[], to_flag[], unchanged_count}}.
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
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
$clientId = resolveSelfServiceClientId($session, $clientId);
$items = $input['items'] ?? null;

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
// Same sane upper bound as the import endpoint — a real CAS lists a few
// dozen folios, not thousands.
if (count($items) > 500) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Cannot preview more than 500 items at once.']);
    exit();
}

$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

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

// Only this client's CAS-sourced rows are ever visible to reconciliation —
// a manually-entered holding is invisible to this whole flow, by construction.
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

echo json_encode(['status' => 'success', 'diff' => $diff]);
