<?php
declare(strict_types=1);

// P0-1 · Households — assign a client to a household, or clear it (household_id
// null). Advisor-only, tenant-scoped. Both the client AND the household must be
// in the acting advisor's tenant — this is what keeps a household strictly
// within one firm (a household never crosses tenants).

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
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
// household_id may be null (clear) or a positive int (assign).
$hasHousehold = array_key_exists('household_id', $input) && $input['household_id'] !== null;
$householdId = $hasHousehold ? (int) $input['household_id'] : null;

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

// The client must be in this tenant.
if ($scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Client not found.']);
    exit();
}

// If assigning (not clearing), the household must be in this tenant too.
if ($householdId !== null && $scopedDb->select('households', ['id' => $householdId]) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Household not found.']);
    exit();
}

$scopedDb->update('users', ['household_id' => $householdId], ['id' => $clientId, 'role' => 'client']);

echo json_encode(['status' => 'success', 'client_id' => $clientId, 'household_id' => $householdId]);
