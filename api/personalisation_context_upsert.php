<?php
declare(strict_types=1);

// docs/11 Prompt E-2 · Record the opt-in personalisation facts: city tier
// (+ an optional multiplier override) and an ongoing monthly medical cost.
//
// POST only. Same gate as foundations_upsert.php: advisor-only in a firm
// tenant, additionally self-service for an individual writing their OWN data
// in a personal tenant (sql/033) — verifySelfServiceWrite() refuses an
// advisor-managed client exactly as everywhere else.
//
// One row per client (UNIQUE on tenant_id+client_id) — a true upsert keyed on
// the client. EVERY FIELD IS OPTIONAL AND NULLABLE: omitting a field leaves it
// unchanged, passing null explicitly clears it back to "not recorded" — the
// same not-recorded-vs-answered distinction foundations_upsert.php draws.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/PersonalisationReference.php';

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

$clientId = resolveSelfServiceClientId($session, (int) ($input['client_id'] ?? 0));
if ($clientId <= 0 || $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$fields = [];

if (array_key_exists('city_tier', $input)) {
    $raw = $input['city_tier'];
    if ($raw === null || $raw === '') {
        $fields['city_tier'] = null;
    } elseif (!in_array($raw, ['X', 'Y', 'Z'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'city_tier must be one of X, Y, Z.']);
        exit();
    } else {
        $fields['city_tier'] = $raw;
    }
}

if (array_key_exists('expense_multiplier', $input)) {
    $raw = $input['expense_multiplier'];
    if ($raw === null || $raw === '') {
        // Clears back to "use the tier default" — see client_context's column
        // comment: only an actual OVERRIDE is ever persisted here.
        $fields['expense_multiplier'] = null;
    } elseif (
        !is_numeric($raw)
        || (float) $raw < PersonalisationReference::MULTIPLIER_MIN
        || (float) $raw > PersonalisationReference::MULTIPLIER_MAX
    ) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'expense_multiplier must be between '
            . PersonalisationReference::MULTIPLIER_MIN . ' and ' . PersonalisationReference::MULTIPLIER_MAX . '.']);
        exit();
    } else {
        $fields['expense_multiplier'] = round((float) $raw, 2);
    }
}

if (array_key_exists('monthly_medical_cost', $input)) {
    $raw = $input['monthly_medical_cost'];
    if ($raw === null || $raw === '') {
        $fields['monthly_medical_cost'] = null;
    } elseif (!is_numeric($raw) || (float) $raw < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'monthly_medical_cost cannot be negative.']);
        exit();
    } elseif ((float) $raw > 10_000_000) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'monthly_medical_cost is implausibly large.']);
        exit();
    } else {
        $fields['monthly_medical_cost'] = (float) $raw;
    }
}

if ($fields === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nothing to update.']);
    exit();
}

$existing = $scopedDb->select('client_context', ['client_id' => $clientId]);
if ($existing !== []) {
    $scopedDb->update('client_context', $fields, ['id' => (int) $existing[0]['id']]);
    echo json_encode(['status' => 'success', 'client_id' => $clientId]);
    exit();
}

$fields['client_id'] = $clientId;
$fields['created_by_user_id'] = $userId;
$scopedDb->insert('client_context', $fields);

echo json_encode(['status' => 'success', 'client_id' => $clientId]);
