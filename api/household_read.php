<?php
declare(strict_types=1);

// P0-1 · Households — read which household a given client is in (for the assign
// control on the advisor's client page). Advisor-only, tenant-scoped.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$clientId = (int) ($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

$clients = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client'], 'household_id');
if ($clients === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Client not found.']);
    exit();
}

$householdId = $clients[0]['household_id'] !== null ? (int) $clients[0]['household_id'] : null;
$householdName = null;
if ($householdId !== null) {
    $hh = $scopedDb->select('households', ['id' => $householdId], 'name');
    $householdName = $hh[0]['name'] ?? null;
}

echo json_encode([
    'status'         => 'success',
    'household_id'   => $householdId,
    'household_name' => $householdName,
]);
