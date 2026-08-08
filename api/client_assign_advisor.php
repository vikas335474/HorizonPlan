<?php
declare(strict_types=1);

// Assign a client to an advisor, or clear the assignment (advisor_id null).
// POST, advisor-only (verifyAccess 'advisor'; super_admin also passes),
// tenant-scoped. Mirrors household_assign.php almost exactly — the same
// "both sides must be in the acting advisor's tenant" validation, because a
// plain FK cannot express that constraint (migration 031's own comment).
//
// Assignment is ATTRIBUTION, not access control (see migration 031): it drives
// the "my clients" filter and the firm's per-advisor analytics. It never
// changes who can read or write a client — every advisor in the firm still
// reaches every client in the tenant, exactly as before. So this deliberately
// does NOT require a firm_role: any advisor can pick up an unassigned client
// or hand one over, the same way any advisor can already edit any client's
// plan. Restricting hand-offs to sr_advisor/firm_admin would imply a
// permission boundary that does not exist underneath.
//
// Inputs (JSON body): client_id (required), advisor_id (required, null to
// unassign). Output: {status, client_id, advisor_id}. Errors: 400 (missing/
// invalid client_id), 404 (client or advisor not in this tenant), 405.

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
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$clientId = (int) ($input['client_id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

// advisor_id must be present in the body; null is the explicit "unassign"
// value. array_key_exists rather than ?? so a missing key is a 400 (probably a
// caller bug) while an explicit null is honoured as a real instruction.
if (!array_key_exists('advisor_id', $input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'advisor_id is required (send null to unassign).']);
    exit();
}
$advisorId = $input['advisor_id'] === null ? null : (int) $input['advisor_id'];

// The client must be a client in THIS tenant. Tenant-scoped select, so a
// client_id from another firm simply isn't in the result set and 404s exactly
// like a nonexistent one — never confirming another tenant's row exists.
if ($scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

// Same check for the advisor side — this is what stops a cross-tenant
// assignment that the FK alone would happily allow.
if ($advisorId !== null) {
    if ($advisorId <= 0 || $scopedDb->select('users', ['id' => $advisorId, 'role' => 'advisor']) === []) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No advisor with that ID in this tenant.']);
        exit();
    }
}

$scopedDb->update('users', ['assigned_advisor_id' => $advisorId], ['id' => $clientId]);

// change_log is specified for base_plans/sub_scenarios mutations (CLAUDE.md
// rule #4) and its entity_type column reflects that; an assignment is neither,
// so it deliberately writes no change_log row — the same choice
// household_assign.php makes for the identical kind of grouping change.

echo json_encode([
    'status'     => 'success',
    'client_id'  => $clientId,
    'advisor_id' => $advisorId,
]);
