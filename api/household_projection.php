<?php
declare(strict_types=1);

// P0-1 · Households — the aggregate (sum-of-members) projection for one
// household. Advisor-only, tenant-scoped: computeHouseholdAggregate() reads
// members and their goals through TenantScopedDb, so a household can never pull
// in another firm's client, and returns null (→ 404) for a household outside
// this tenant.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/HouseholdProjection.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$householdId = (int) ($_GET['household_id'] ?? 0);
if ($householdId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'household_id is required.']);
    exit();
}

$aggregate = computeHouseholdAggregate($scopedDb, $householdId);
if ($aggregate === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Household not found.']);
    exit();
}

echo json_encode(['status' => 'success'] + $aggregate);
