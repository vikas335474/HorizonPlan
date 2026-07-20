<?php
declare(strict_types=1);

// Advisor onboards a new client into their own tenant. Creates a users row with
// role='client', tenant stamped automatically by TenantScopedDb, and a password
// the advisor sets (a temporary one they share with the client, who changes it
// later — password-change flow is a separate future endpoint).
//
// Scope: advisor and super_admin only. The new client always lands in the
// creating advisor's tenant — client_id/tenant boundaries can't be crossed here
// because TenantScopedDb::insert() overwrites tenant_id with the session's tenant.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db       = getPdo();
$session  = verifyAccessAny($db, ['advisor', 'super_admin']);
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['temporary_password'] ?? '');

// Validation
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

// Email is globally unique (uq_users_email), not just per-tenant — check before
// insert so we return a clean 409 rather than a raw DB constraint error.
$existing = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'A user with that email already exists.']);
    exit();
}

$clientId = $scopedDb->insert('users', [
    'email'         => $email,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role'          => 'client',
    // tenant_id is stamped by TenantScopedDb::insert() — do not pass it here
]);

// Audit: log the creation, but never log the password or its hash.
$scopedDb->logChange('user', $clientId, 'created', null,
    json_encode(['email' => $email, 'role' => 'client']), (int) $session['user_id']);

echo json_encode([
    'status'    => 'success',
    'client_id' => $clientId,
    'email'     => $email,
]);
