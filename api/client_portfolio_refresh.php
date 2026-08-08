<?php
declare(strict_types=1);

// Client portfolio ledger: recompute a client's NAV-tracked holdings from the
// cached NAVs. POST, advisor-only, tenant-scoped. This is the manual "Refresh
// prices" action — it NEVER calls AMFI live; it only re-derives value =
// units_held x cached NAV from whatever the daily cron (tools/mf_nav_sync.php)
// last stored (see MfNavSync.php). Input: {client_id} (must be a client in this
// tenant, 404 otherwise). Output: {status, updated_count, nav_last_updated}.
// Errors: 400 (missing client_id), 404 (client not in tenant), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/MfNavSync.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
// Same access as the rest of the portfolio ledger — an advisor recomputes a
// client's own holdings. This never calls AMFI; it only recomputes from
// whatever the daily cron already cached (api/lib/MfNavSync.php's own
// docblock: "using the cached data locally" per the user's own instruction).
// Self-serve individual tier (sql/033): advisor-only in a firm, and
// additionally self-service for an individual writing their OWN data in a
// personal tenant. verifySelfServiceWrite() refuses an advisor-managed
// client exactly as before — see api/lib/SelfService.php.
$session = verifySelfServiceWrite($db);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
// A personal user always writes their own data; any client_id in the body
// is ignored rather than trusted, same posture as the client-facing reads.
$clientId = resolveSelfServiceClientId($session, $clientId);
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$result = refreshPortfolioValuesFromCache($scopedDb, $db, $clientId);

echo json_encode([
    'status'           => 'success',
    'updated_count'    => $result['updated_count'],
    'nav_last_updated' => $result['nav_last_updated'],
]);
