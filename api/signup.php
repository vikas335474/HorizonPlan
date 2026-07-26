<?php
declare(strict_types=1);

// Self-serve trial signup — the one deliberate exception to docs/04's
// "tenant onboarding is admin-created, not self-serve" rule, scoped narrowly
// enough that it doesn't actually weaken the thing that rule exists to
// protect. A trial tenant created here is ALWAYS 'distribution' mode — the
// advisory_mode field is never accepted from this endpoint's input at all,
// only ever hardcoded below — so the real compliance boundary (docs/02
// Section 3.6: advisory_mode requires a genuine SEBI-RIA review, super_admin
// action only) is completely untouched. signup_source='trial_signup'
// (sql/022) just tags the row for bookkeeping/reporting; it grants no
// capability and nothing security-relevant reads it.
//
// Unauthenticated and CSRF-exempt, same reasoning as login.php/mfa_verify.php
// — no session/CSRF cookie exists yet. The account created here is subject
// to every existing security control identically to an admin-created one:
// mandatory MFA enrollment kicks in on the very next gated request, same as
// any fresh account (see requireMfaEnrollment()) — self-serve signup changes
// who can create a tenant, not what that tenant/user is allowed to do.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TrialSignup.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();

$settings = getPlatformSettings($db, true);
if ($settings['signup_enabled'] !== 'on') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Self-serve signup is currently disabled.']);
    exit();
}

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$companyName = trim((string) ($input['company_name'] ?? ''));
$email       = strtolower(trim((string) ($input['email'] ?? '')));
$password    = (string) ($input['password'] ?? '');
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($companyName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Firm name is required.']);
    exit();
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email is required.']);
    exit();
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
    exit();
}

// Reuses the login-attempt limiter keyed by IP only (empty email), same
// technique as auth_google.php — this endpoint has no prior failed-attempt
// history to rate-limit by email (every attempt here is a brand new email by
// definition), but repeated tenant creation from one IP is exactly what this
// guards against.
if (!checkLoginRateLimit($db, '', $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Try again later.']);
    exit();
}

$existing = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    recordLoginAttempt($db, '', $ipAddress, false);
    // Unlike login.php's deliberately-identical error for "no such user" vs.
    // "wrong password" (anti-enumeration on an existing account), a signup
    // form telling you an email is already registered is normal, expected
    // signup UX everywhere — not the same leak profile as a login attempt.
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'An account with that email already exists.']);
    exit();
}

try {
    // The founding user of a self-signed-up firm is its own firm_admin — the
    // top tier of docs/09 Piece 2's role hierarchy, since they're the only
    // person in it. createTrialTenant() forces distribution mode +
    // signup_source unconditionally (see TrialSignup.php).
    $created = createTrialTenant($db, $companyName, $email, password_hash($password, PASSWORD_BCRYPT));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not create your account.']);
    exit();
}
$tenantId = $created['tenant_id'];
$userId   = $created['user_id'];

recordLoginAttempt($db, '', $ipAddress, true);

// Issue a full session immediately, same as login.php does for any brand-new
// unenrolled account — the next gated request will redirect to Settings to
// complete MFA enrollment (TOTP or Google), exactly like an admin-created
// advisor's very first login.
issueSession($db, $userId, $tenantId, 'advisor');

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'                => $userId,
        'tenant_id'         => $tenantId,
        'role'              => 'advisor',
        'mfa_enrolled'      => false,
        'mfa_totp_enrolled' => false,
        'google_linked'     => false,
    ],
    'company_name' => $companyName,
]);
