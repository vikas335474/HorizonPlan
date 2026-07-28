<?php
declare(strict_types=1);

// P0-3 · Scheduled plan reviews — read a client's current review cadence.
// Advisor-only (an advisor manages their clients' schedules); tenant-scoped so
// an advisor can only ever read a client in their own firm. Absence of a row
// means 'off' — the default, no client is silently enrolled.

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

// Confirm the client is in this advisor's tenant before revealing anything.
if ($scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Client not found.']);
    exit();
}

$rows = $scopedDb->select('plan_review_schedules', ['client_id' => $clientId]);
$cadence = $rows[0]['cadence'] ?? 'off';
$lastSentAt = $rows[0]['last_sent_at'] ?? null;

echo json_encode([
    'status'       => 'success',
    'cadence'      => $cadence,
    'last_sent_at' => $lastSentAt,
]);
