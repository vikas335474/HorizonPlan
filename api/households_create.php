<?php
declare(strict_types=1);

// P0-1 · Households — create a household in the advisor's own firm.
// Advisor-only, tenant-scoped (TenantScopedDb::insert injects tenant_id).

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
$name = trim((string) ($input['name'] ?? ''));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A household name is required.']);
    exit();
}
if (mb_strlen($name) > 191) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Household name is too long (max 191 characters).']);
    exit();
}

$id = $scopedDb->insert('households', ['name' => $name]);

echo json_encode(['status' => 'success', 'household' => ['id' => $id, 'name' => $name, 'member_count' => 0]]);
