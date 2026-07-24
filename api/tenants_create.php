<?php
declare(strict_types=1);

// Super Admin onboards a new advisory firm (tenant), optionally with its first
// advisor in the same step. This is the browser path that replaces raw SQL for
// firm onboarding (docs/04 Phase 1: "Tenant onboarding — admin-created").
//
// Cross-tenant by design: only a super_admin reaches this, and it operates on
// the tenants registry (which has no tenant_id of its own). Advisor creation is
// still routed through TenantScopedDb — bound to the *new* tenant — so the user
// insert + audit row stay inside the helper pattern (docs/02 3.1).

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
$session = verifyAccess($db, 'super_admin'); // super_admin only — never self-serve (docs/02 3.6)

$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$companyName  = trim((string) ($input['company_name'] ?? ''));
$advisoryMode = (string) ($input['advisory_mode'] ?? 'distribution');
$advisor      = is_array($input['first_advisor'] ?? null) ? $input['first_advisor'] : null;

if ($companyName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Company name is required.']);
    exit();
}
if (!in_array($advisoryMode, ['distribution', 'advisory'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "advisory_mode must be 'distribution' or 'advisory'."]);
    exit();
}

// Validate the optional first advisor up front so we don't create a tenant and
// then fail on the user — either both succeed or we stop before touching the DB.
$advisorEmail = null;
$advisorPass  = null;
if ($advisor !== null) {
    $advisorEmail = strtolower(trim((string) ($advisor['email'] ?? '')));
    $advisorPass  = (string) ($advisor['temporary_password'] ?? '');
    if ($advisorEmail === '' || !filter_var($advisorEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'A valid advisor email is required.']);
        exit();
    }
    if (strlen($advisorPass) < 8) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Advisor temporary password must be at least 8 characters.']);
        exit();
    }
    $existing = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $existing->execute([':email' => $advisorEmail]);
    if ($existing->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'A user with that email already exists.']);
        exit();
    }
}

$db->beginTransaction();
try {
    // tenants has no tenant_id column (it IS the tenant registry) — raw insert.
    $ins = $db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES (:name, :mode)");
    $ins->execute([':name' => $companyName, ':mode' => $advisoryMode]);
    $tenantId = (int) $db->lastInsertId();

    $advisorId = null;
    if ($advisorEmail !== null) {
        $scopedDb = new TenantScopedDb($db, $tenantId); // bound to the new tenant
        $advisorId = $scopedDb->insert('users', [
            'email'         => $advisorEmail,
            'password_hash' => password_hash($advisorPass, PASSWORD_BCRYPT),
            'role'          => 'advisor',
        ]);
        $scopedDb->logChange('user', $advisorId, 'created', null,
            json_encode(['email' => $advisorEmail, 'role' => 'advisor']), (int) $session['user_id']);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not create the firm.']);
    exit();
}

echo json_encode([
    'status'       => 'success',
    'tenant_id'    => $tenantId,
    'company_name' => $companyName,
    'advisory_mode' => $advisoryMode,
    'advisor_id'   => $advisorId,
    'advisor_email' => $advisorEmail,
]);
