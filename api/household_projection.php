<?php
declare(strict_types=1);

// P0-1 · Households — the aggregate (sum-of-members) projection for one
// household. Tenant-scoped: computeHouseholdAggregate() reads members and their
// goals through TenantScopedDb, so a household can never pull in another firm's
// client, and returns null (→ 404) for a household outside this tenant.
//
// Readable by an advisor (any household in their tenant) AND — since sql/035 —
// by a client in a PERSONAL tenant, for their OWN household only: a couple
// planning together needs the combined view, and it is the sum of the two
// plans they each authored. A client in a FIRM tenant is still refused, because
// there a household groups an advisor's clients and one client has no business
// enumerating the others. See verifyHouseholdAccess() in lib/SelfService.php.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/HouseholdProjection.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$access = verifyHouseholdAccess($db);
$session = $access['session'];
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// A personal client is pinned to their own household; any household_id in the
// query string is ignored rather than trusted.
$householdId = resolveHouseholdId($access, (int) ($_GET['household_id'] ?? 0));
if ($householdId <= 0) {
    http_response_code($access['is_client'] ? 404 : 400);
    echo json_encode([
        'status' => 'error',
        'message' => $access['is_client']
            ? 'You are not part of a household yet.'
            : 'household_id is required.',
    ]);
    exit();
}

$aggregate = computeHouseholdAggregate($scopedDb, $householdId);
if ($aggregate === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Household not found.']);
    exit();
}

echo json_encode(['status' => 'success'] + $aggregate);
