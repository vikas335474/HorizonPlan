<?php
declare(strict_types=1);

// Client portfolio ledger: partial-update ONE line item. POST or PUT,
// advisor-only, tenant-scoped (404 if the row isn't this tenant's). Only the
// fields sent are touched. item_kind is intentionally NOT editable — flipping an
// asset to a liability is "delete and re-add", not an edit. NAV tracking fields
// stay both-or-neither and are only re-validated when the request actually
// touches one of them; changing the scheme re-seeds value from mf_nav_cache (no
// live AMFI call). docs/12 Prompt D-2: fund_type/acquisition_value/
// acquisition_date are each independently editable and clearable back to
// NULL, gated to asset rows (fund_type additionally gated to category=
// mutual_fund). Output: {status, item_id, changed_fields[]}. Errors:
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
// docs/10 P1-4 — what a liability costs. Gated on item_kind the same way
// bucket is above, and explicitly clearable back to NULL ("not recorded"),
// which FinancialFoundations reports as unrated rather than cheap.
if (array_key_exists('interest_rate', $input) && $existing['item_kind'] === 'liability') {
    $rate = $input['interest_rate'];
    if ($rate === null || $rate === '') {
        $updateData['interest_rate'] = null;
    } elseif (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'interest_rate must be between 0 and 100.']);
        exit();
    } else {
        $updateData['interest_rate'] = (float) $rate;
    }
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

// docs/12 Prompt D-2 — fund_type, only meaningful for a mutual_fund asset.
// Gated against the EFFECTIVE category (the one in this same request if
// it's changing category, else the existing one) — same reasoning as
// bucket's re-validation, just against category instead of item_kind.
if (array_key_exists('fund_type', $input)) {
    $effectiveCategory = $updateData['category'] ?? $existing['category'];
    if ($existing['item_kind'] !== 'asset' || $effectiveCategory !== 'mutual_fund') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'fund_type only applies to a mutual_fund asset.']);
        exit();
    }
    $fundType = $input['fund_type'];
    if ($fundType === null || $fundType === '') {
        $updateData['fund_type'] = null;
    } elseif (!in_array($fundType, ['equity', 'debt', 'hybrid'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'fund_type must be equity, debt, or hybrid.']);
        exit();
    } else {
        $updateData['fund_type'] = $fundType;
    }
}

// docs/12 Prompt D-2 — cost basis. Asset-only, each independently
// clearable back to NULL ("not recorded"), same posture as interest_rate
// above. Not paired both-or-neither, per client_portfolio_create.php's own
// reasoning.
if (array_key_exists('acquisition_value', $input)) {
    if ($existing['item_kind'] !== 'asset') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'acquisition_value only applies to an asset.']);
        exit();
    }
    $acqValue = $input['acquisition_value'];
    if ($acqValue === null || $acqValue === '') {
        $updateData['acquisition_value'] = null;
    } elseif (!is_numeric($acqValue) || (float) $acqValue < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'acquisition_value must be a non-negative number.']);
        exit();
    } else {
        $updateData['acquisition_value'] = (float) $acqValue;
    }
}
if (array_key_exists('acquisition_date', $input)) {
    if ($existing['item_kind'] !== 'asset') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'acquisition_date only applies to an asset.']);
        exit();
    }
    $acqDate = $input['acquisition_date'];
    if ($acqDate === null || $acqDate === '') {
        $updateData['acquisition_date'] = null;
    } else {
        $parsedDate = DateTime::createFromFormat('Y-m-d', (string) $acqDate);
        $today = new DateTime('today');
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $acqDate) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'acquisition_date must be a valid date (YYYY-MM-DD).']);
            exit();
        }
        if ($parsedDate > $today) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'acquisition_date cannot be in the future.']);
            exit();
        }
        $updateData['acquisition_date'] = $acqDate;
    }
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
