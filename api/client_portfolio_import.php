<?php
declare(strict_types=1);

// Bulk import for a client's mutual fund holdings, sourced from a CAMS/
// KFintech/MFCentral Consolidated Account Statement (CAS) export. This is
// deliberately the CSV-mapping path, not a direct CAMS/KFintech/MFCentral API
// integration or the SEBI Account Aggregator framework — those are a much
// bigger, separately-scoped piece of work (OAuth-style consent flows, a new
// external-credential security surface). This endpoint only ever creates
// client_portfolio_items rows through the exact same tenant-scoped path
// client_portfolio_create.php uses — no new table, no schema change.
//
// Every imported row is forced to item_kind='asset', bucket='liquid',
// category='mutual_fund' — a CAS/MFCentral statement only ever lists mutual
// fund folios, which this app's bucket model already treats as liquid/
// market-linked (see ClientPortfolioUI.jsx's ASSET_CATEGORIES), same as a
// manually-added "Mutual fund" portfolio item. The frontend does the actual
// CSV parsing and column mapping (advisor picks which column is the scheme
// name vs. the current value) since CAMS/KFintech/MFCentral don't share one
// guaranteed export layout — this endpoint only ever receives already-
// normalized {description, value} pairs, not raw CSV.
//
// All rows import in one transaction — either the whole statement lands, or
// none of it does, so a bad row can't leave a half-imported portfolio.

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
$clientId = (int) ($input['client_id'] ?? 0);
// A personal user always writes their own data; any client_id in the body
// is ignored rather than trusted, same posture as the client-facing reads.
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
// A sane upper bound — a real CAS can list a few dozen folios, not thousands;
// this guards against a malformed/misconfigured upload rather than any
// expected real usage.
if (count($items) > 500) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Cannot import more than 500 items at once.']);
    exit();
}

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
    $clean[] = ['description' => $description, 'value' => (float) $value];
}

$db->beginTransaction();
try {
    foreach ($clean as $item) {
        $scopedDb->insert('client_portfolio_items', [
            'client_id'          => $clientId,
            'item_kind'          => 'asset',
            'bucket'             => 'liquid',
            'category'           => 'mutual_fund',
            'description'        => $item['description'],
            'value'              => $item['value'],
            'created_by_user_id' => $userId,
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Import failed — no items were saved.']);
    exit();
}

echo json_encode(['status' => 'success', 'imported_count' => count($clean)]);
