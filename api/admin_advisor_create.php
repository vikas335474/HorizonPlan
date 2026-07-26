<?php
declare(strict_types=1);

// Super Admin adds an advisor to an existing firm. Like clients_create.php, but
// cross-tenant: the target tenant is chosen by the super_admin rather than taken
// from the session. The user insert + audit row still go through TenantScopedDb,
// bound to the chosen tenant, so the helper pattern holds (docs/02 3.1).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/Mailer.php';
require_once __DIR__ . '/lib/InviteTokens.php';

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
// docs/09 Piece 2: optional firm-level role — NULL (unset) behaves exactly
// as before this feature existed (treated as sr_advisor by requireFirmRole()).
$firmRole = isset($input['firm_role']) ? (string) $input['firm_role'] : null;

if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant_id is required.']);
    exit();
}
if ($firmRole !== null && !in_array($firmRole, ['jr_advisor', 'sr_advisor', 'firm_admin'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "firm_role must be 'jr_advisor', 'sr_advisor', or 'firm_admin'."]);
    exit();
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email is required.']);
    exit();
}

$tenant = $db->prepare("SELECT id, company_name FROM tenants WHERE id = :id LIMIT 1");
$tenant->execute([':id' => $tenantId]);
$tenantRow = $tenant->fetch();
if (!$tenantRow) {
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
// An unusable, never-known password — the account is only reachable by
// redeeming the invite token below (or a later real password reset). See
// InviteTokens.php's own docblock.
$advisorId = $scopedDb->insert('users', [
    'email'         => $email,
    'password_hash' => unusablePasswordHash(),
    'role'          => 'advisor',
    'firm_role'     => $firmRole,
]);
$scopedDb->logChange('user', $advisorId, 'created', null,
    json_encode(['email' => $email, 'role' => 'advisor']), (int) $session['user_id']);

// Best-effort invite email — same non-blocking pattern as tenants_create.php.
// inviteLink is also returned below as a copyable fallback if delivery
// doesn't land.
$rawToken   = issueInviteToken($db, $advisorId);
$inviteLink = buildInviteLink($rawToken);
sendMail(
    $email,
    "You've been added to HorizonPlan — {$tenantRow['company_name']}",
    "An advisor account was created for you at {$tenantRow['company_name']} on HorizonPlan.\n\n"
    . "Set your password and sign in here (link expires in 7 days):\n{$inviteLink}\n"
);

echo json_encode(['status' => 'success', 'advisor_id' => $advisorId, 'email' => $email, 'invite_link' => $inviteLink]);
