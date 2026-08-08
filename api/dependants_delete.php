<?php
declare(strict_types=1);

// docs/11 Prompt E-2 · Remove one dependant (child) row. Same
// verifySelfServiceWrite() gate and ownership check as dependants_upsert.php —
// only the client who owns the row may delete it.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifySelfServiceWrite($db);
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$clientId = resolveSelfServiceClientId($session, (int) ($input['client_id'] ?? 0));

$rows = $scopedDb->select('client_dependants', ['id' => $id]);
if ($rows === [] || (int) $rows[0]['client_id'] !== $clientId) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No dependant with that ID for this client.']);
    exit();
}

$scopedDb->delete('client_dependants', ['id' => $id]);
echo json_encode(['status' => 'success']);
