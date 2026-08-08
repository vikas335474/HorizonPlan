<?php
declare(strict_types=1);

// P1-1 · Goal-progress tracking — capture a snapshot for ONE client, now.
// POST, advisor-only (verifyAccess 'advisor'; super_admin also passes),
// tenant-scoped. This is the manual "Record now" an advisor uses before a
// review meeting; the monthly cron (tools/progress_snapshot.php) writes the
// same rows unattended.
//
// Snapshots every goal the client has, plus the client's portfolio net worth,
// dated today. Idempotent per day — a second call on the same date updates
// that day's rows rather than stacking duplicates (see migration 032's unique
// keys and ProgressSnapshot.php).
//
// Inputs (JSON body): client_id (must be a client in this tenant, 404
// otherwise). Output: {status, as_of_date, goals_captured, net_worth_captured}.
// Errors: 400 (missing client_id), 404 (client not in tenant), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/ProgressSnapshot.php';

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

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

if ($scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$result = captureClientProgress($scopedDb, $db, $tenantId, $clientId, $userId);

echo json_encode(['status' => 'success'] + $result);
