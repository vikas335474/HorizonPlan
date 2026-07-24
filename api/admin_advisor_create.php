<?php
declare(strict_types=1);

// Super Admin adds an advisor to an existing firm. Like clients_create.php, but
// cross-tenant: the target tenant is chosen by the super_admin rather than taken
// from the session. The user insert + audit row still go through TenantScopedDb,
// bound to the chosen tenant, so the helper pattern holds (docs/02 3.1).

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccess($db, 'super_admin');

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$tenantId = (int) ($input['tenant_id'] ?? 0);
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['temporary_password'] ?? '');

if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant_id is required.']);
    exit();
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email is required.']);
    exit();
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Temporary password must be at least 8 characters.']);
    exit();
}

$tenant = $db->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
$tenant->execute([':id' => $tenantId]);
if (!$tenant->fetch()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Tenant not found.']);
    exit();
}

$existing = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'A user with that email already exists.']);
    exit();
}

$scopedDb  = new TenantScopedDb($db, $tenantId);
$advisorId = $scopedDb->insert('users', [
    'email'         => $email,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role'          => 'advisor',
]);
$scopedDb->logChange('user', $advisorId, 'created', null,
    json_encode(['email' => $email, 'role' => 'advisor']), (int) $session['user_id']);

echo json_encode(['status' => 'success', 'advisor_id' => $advisorId, 'email' => $email]);
