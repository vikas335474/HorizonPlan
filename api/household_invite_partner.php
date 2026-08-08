<?php
declare(strict_types=1);

// sql/035 · Invite a partner into a self-serve household — the two-earner case
// (a couple each with their own income, goals and retirement date).
//
// POST only. PERSONAL TENANTS ONLY, and deliberately not available to an
// advisor: a firm builds households through households_create.php +
// household_assign.php, where the advisor groups clients who already exist.
// Here the invited person does not exist yet and is inviting themselves into a
// login, which is a different operation with a different safety profile.
//
// What it does, in one transaction:
//   1. creates the household if the inviter is not in one yet (a solo user who
//      has just decided to plan with someone), and joins the inviter to it;
//   2. creates the partner as a second user — role='client', SAME tenant, same
//      household, with an unusable password hash (InviteTokens.php) so the
//      account can only be reached by redeeming the invite;
//   3. issues a magic-link invite token and returns the link.
//
// Redemption needs no new code: /accept-invite + password_reset_confirm.php
// already handle purpose='invite' exactly as they do for an advisor invite.
//
// WHAT THIS DOES *NOT* GRANT. The partner becomes a peer, not a co-editor.
// SelfService.php still forces every write to the writer's own client_id, so
// each person authors only their own plan — see that file's note on why check
// (2) is now load-bearing rather than defensive. Both can READ the household
// (the combined view is meaningless otherwise); neither can silently change the
// other's assumptions.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/InviteTokens.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

// A bound on household size. This is an ABUSE bound, not a claim about what a
// family looks like: every invite mints a real login, so an unbounded endpoint
// would be a free account factory. Five covers a couple plus earning parents or
// an adult child; raise it if a real household needs more.
const HOUSEHOLD_MAX_MEMBERS = 5;

$db = getPdo();

// Not verifySelfServiceWrite(): that admits advisors, who must not reach this
// endpoint at all. Clients only, then narrowed to personal tenants.
$session = verifyAccessAny($db, ['client']);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];

if (!tenantIsPersonal($db, $tenantId)) {
    // Same response the advisor-only household endpoints have always given a
    // firm client, so this tier is not detectable by probing.
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
    exit();
}

// Creating accounts is the expensive, spam-able operation on this endpoint, so
// it carries the same IP throttle signup_personal.php uses rather than none.
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!checkLoginRateLimit($db, '', $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string) ($input['email'] ?? '')));
$name = trim((string) ($input['display_name'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
    exit();
}
if (mb_strlen($name) > 191) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Name is too long.']);
    exit();
}
$scopedDb = new TenantScopedDb($db, $tenantId);

// The session carries only user_id/tenant_id/role, so the inviter's own email
// has to be read rather than taken from it. Worth catching explicitly: without
// this, inviting yourself falls through to the global-uniqueness check below
// and returns "that address cannot be used here", which is true but baffling
// when the address is your own.
$meRows = $scopedDb->select('users', ['id' => $userId]);
$me = $meRows[0] ?? null;
if ($me === null) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
    exit();
}
if ($email === strtolower((string) $me['email'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "That's your own email address."]);
    exit();
}

// users.email is UNIQUE across the WHOLE platform, not per tenant, so a
// collision is possible with an account in someone else's tenant. Report it
// without confirming whether that address is registered elsewhere.
$existing = $db->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$existing->execute([':e' => $email]);
if ($existing->fetchColumn() !== false) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'message' => 'That email address cannot be used here. If they already have a HorizonPlan account, they should sign in with it instead.',
    ]);
    exit();
}

$members = $scopedDb->select('users', ['role' => 'client']);
if (count($members) >= HOUSEHOLD_MAX_MEMBERS) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'This household already has the maximum number of people.']);
    exit();
}

$db->beginTransaction();
try {
    // The inviter's household, created on first invite. A solo personal user
    // has household_id NULL until this moment — households are opt-in here
    // exactly as they are for an advisor (sql/029: no backfill, nobody is
    // grouped until someone asks for it).
    $householdId = ($me['household_id'] !== null) ? (int) $me['household_id'] : 0;

    if ($householdId === 0) {
        $myName = (string) ($me['display_name'] ?? '');
        $label = $myName !== '' ? "$myName's household" : 'Our household';
        $householdId = (int) $scopedDb->insert('households', ['name' => mb_substr($label, 0, 191)]);
        $scopedDb->update('users', ['household_id' => $householdId], ['id' => $userId]);
    }

    $partnerId = (int) $scopedDb->insert('users', [
        'email'         => $email,
        'display_name'  => $name !== '' ? $name : null,
        'password_hash' => unusablePasswordHash(),
        'role'          => 'client',
        'household_id'  => $householdId,
    ]);

    $scopedDb->logChange(
        'user',
        $partnerId,
        'created',
        null,
        json_encode(['email' => $email, 'role' => 'client', 'via' => 'household_partner_invite']),
        $userId
    );

    $rawToken = issueInviteToken($db, $partnerId);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// The link is returned rather than only emailed: this environment has no
// guaranteed outbound mail, and a person inviting their own spouse can just as
// easily pass it along themselves. Same shape as the advisor invite flow.
echo json_encode([
    'status'       => 'success',
    'partner_id'   => $partnerId,
    'household_id' => $householdId,
    'invite_link'  => buildInviteLink($rawToken),
]);
