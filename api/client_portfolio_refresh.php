<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
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
$session = verifyAccess($db, 'advisor');
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
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
