<?php
declare(strict_types=1);

// Authentication step 1: email + password. POST, UNAUTHENTICATED and
// CSRF-exempt (no session exists yet). Rate-limited before password_verify()
// so a locked-out attacker can't keep guessing (429). "No such user" and "wrong
// password" return the identical 401 to prevent email enumeration — do not make
// this more specific.
//
// On a correct password it branches on the account's second factor: TOTP
// enrolled -> 202 {status: mfa_required} plus a short-lived pending token, and
// the client must POST the OTP to mfa_verify.php; Google-linked (no TOTP) ->
// 401 {google_signin_required} (password alone must not bypass the second
// factor); neither -> issues a full session (usable only for enrollment
// endpoints until mandatory MFA is satisfied). Output on the plain-session path:
// {status, user{...}}. Errors: 400 (missing fields), 401, 429, 405.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/Totp.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$email     = trim((string) ($input['email'] ?? ''));
$password  = (string) ($input['password'] ?? '');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
    exit();
}

$db = getPdo();

// Rate limit check BEFORE password verification — a locked-out attacker
// must not be allowed to keep spending compute on password_verify() calls.
if (!checkLoginRateLimit($db, $email, $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Try again later.']);
    exit();
}

$stmt = $db->prepare("SELECT id, tenant_id, role, password_hash, mfa_secret, google_sub FROM users WHERE email = :email LIMIT 1");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

// Identical error for "no such user" and "wrong password" — prevents email
// enumeration. Do not change this to be more specific.
if (!$user || !password_verify($password, $user['password_hash'])) {
    recordLoginAttempt($db, $email, $ipAddress, false);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    exit();
}

recordLoginAttempt($db, $email, $ipAddress, true);

if (!empty($user['mfa_secret'])) {
    // MFA enrolled — password is verified but no full session yet.
    // Issue a short-lived pending token; the client must POST the OTP
    // to mfa_verify.php to complete authentication.
    issueMfaPendingToken($db, (int) $user['id'], 'login');

    http_response_code(202);
    echo json_encode([
        'status'       => 'mfa_required',
        'message'      => 'Password accepted. Submit your authenticator code to complete sign in.',
    ]);
    exit();
}

// No TOTP secret, but Google IS linked — password alone must NOT issue a
// full session here. Google login counts as MFA precisely because it's a
// verified Google login; a plain password check is not that, so treating
// "google_sub is set" as license to skip the second factor entirely would
// silently downgrade this account's real security to password-only the
// moment Google gets linked. Send the caller to the Google button instead.
// This leaks "this account uses Google Sign-In" only to someone who already
// knows the correct password — the same leak profile as the mfa_required
// branch above leaking "this account has 2FA enabled".
if (!empty($user['google_sub'])) {
    http_response_code(401);
    echo json_encode([
        'status'                 => 'error',
        'message'                => 'This account signs in with Google. Use "Sign in with Google" below.',
        'google_signin_required' => true,
    ]);
    exit();
}

// Neither TOTP nor Google linked — issue a full session anyway (issueSession()
// has no concept of enrollment state, and login.php has no reason to grow
// one). MFA enrollment is mandatory (see "Security status" in CLAUDE.md): the
// session this creates works for mfa_enroll.php, session.php, and logout.php
// only — verifyAccess()/verifyAccessAny() 403 everything else until the user
// completes enrollment (TOTP or Google, either satisfies it). The frontend's
// ProtectedRoute redirects to /settings off the mfa_enrolled flag below rather
// than the user hitting a wall of 403s.
issueSession($db, (int) $user['id'], (int) $user['tenant_id'], $user['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'                => (int) $user['id'],
        'tenant_id'         => (int) $user['tenant_id'],
        'role'              => $user['role'],
        // Always false here — this branch is only reached when neither
        // mfa_secret nor google_sub is set. Derived rather than hardcoded so
        // it stays correct if the branching above ever changes. The frontend
        // uses this to know whether to nudge the user toward MFA enrollment.
        'mfa_enrolled'      => false,
        'mfa_totp_enrolled' => false,
        'google_linked'     => false,
    ],
]);
