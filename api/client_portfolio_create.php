<?php
declare(strict_types=1);

// Client portfolio ledger (docs/05 item 3 / docs/10 P0): add ONE asset or
// liability row to a client's balance sheet. POST, advisor-only (verifyAccess
// 'advisor'; super_admin also passes, and an individual may record their own
// holdings in a personal tenant) — the advisor records the client's
// holdings. Tenant-scoped via TenantScopedDb; the target client must be a
// 'client' in the acting advisor's tenant (404 otherwise).
//
// Inputs (JSON body): client_id, item_kind (asset|liability), category, and
// either a plain `value` or a NAV-tracked pair (amfi_scheme_code + units_held,
// both-or-neither). `bucket` (liquid|locked) is required for an asset, ignored
// and nulled for a liability. A NAV-tracked row seeds its value from whatever
// mf_nav_cache already holds for that scheme (never a live AMFI call here); a
// brand-new scheme stays "price pending" (0) until the daily cron fills it.
//
// docs/12 Prompt D-2 (tax context, facts only): optional `fund_type`
// (equity|debt|hybrid), meaningful only when category=mutual_fund — the two
// regimes are taxed completely differently, so an unset fund_type is a real
// state (the UI shows all three), never guessed at here. Optional
// `acquisition_value`/`acquisition_date` (asset-only, independent of each
// other — no both-or-neither pairing like NAV tracking, since a person may
// know one without the other) unlock an illustrative gain/holding-period
// fact; both stay NULL, no backfill, until supplied.
//
// Output: {status, item_id}. Errors: 400 (validation), 404 (client not in
// tenant), 405 (non-POST).

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

// interest_rate is the mirror image of bucket: meaningful only for a liability
// (docs/10 P1-4 — what the debt actually costs), meaningless for an asset, so
// it is nulled out for one exactly as bucket is for the other. Optional
// throughout: an unrated loan is reported as unrated by FinancialFoundations,
// never assumed cheap.
$interestRate = $input['interest_rate'] ?? null;
if ($itemKind !== 'liability' || $interestRate === null || $interestRate === '') {
    $interestRate = null;
} elseif (!is_numeric($interestRate) || (float) $interestRate < 0 || (float) $interestRate > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'interest_rate must be between 0 and 100.']);
    exit();
} else {
    $interestRate = (float) $interestRate;
}

// fund_type: only meaningful for a mutual_fund asset — nulled out for
// anything else, same mirror-image pattern as bucket/interest_rate above.
// docs/12 Prompt D-2: equity vs. debt funds are taxed completely
// differently, so this is never inferred or defaulted.
$fundType = $input['fund_type'] ?? null;
if ($itemKind !== 'asset' || $category !== 'mutual_fund' || $fundType === null || $fundType === '') {
    $fundType = null;
} elseif (!in_array($fundType, ['equity', 'debt', 'hybrid'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'fund_type must be equity, debt, or hybrid.']);
    exit();
}

// acquisition_value / acquisition_date (docs/12 Prompt D-2): asset-only,
// deliberately independent of each other (unlike NAV tracking's both-or-
// neither pairing) — a person may remember the purchase date but not the
// exact price, or vice versa, and each is independently useful (holding-
// period classification needs only the date; the illustrative gain needs
// only the value alongside the item's own current value).
$acquisitionValue = $input['acquisition_value'] ?? null;
if ($itemKind !== 'asset' || $acquisitionValue === null || $acquisitionValue === '') {
    $acquisitionValue = null;
} elseif (!is_numeric($acquisitionValue) || (float) $acquisitionValue < 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'acquisition_value must be a non-negative number.']);
    exit();
} else {
    $acquisitionValue = (float) $acquisitionValue;
}

$acquisitionDate = $input['acquisition_date'] ?? null;
if ($itemKind !== 'asset' || $acquisitionDate === null || $acquisitionDate === '') {
    $acquisitionDate = null;
} else {
    $parsedDate = DateTime::createFromFormat('Y-m-d', (string) $acquisitionDate);
    $today = new DateTime('today');
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $acquisitionDate) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'acquisition_date must be a valid date (YYYY-MM-DD).']);
        exit();
    }
    if ($parsedDate > $today) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'acquisition_date cannot be in the future.']);
        exit();
    }
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
    'fund_type'          => $fundType,
    'description'        => $description,
    'value'              => $resolvedValue,
    'acquisition_value'  => $acquisitionValue,
    'acquisition_date'   => $acquisitionDate,
    'interest_rate'      => $interestRate,
    'amfi_scheme_code'   => $schemeCode,
    'units_held'         => $schemeCode !== null ? (float) $unitsHeld : null,
    'created_by_user_id' => $userId,
]);

echo json_encode(['status' => 'success', 'item_id' => $id]);
