<?php
declare(strict_types=1);

// MFA login step 2 of 2.
//
// Called after login.php returns 202 / mfa_required. The user submits their
// 6-digit TOTP code here. If valid, a full session is issued (same as login.php
// does for non-MFA users after password verification). If invalid, the pending
// token is consumed and the user must restart from login.php — this prevents
// brute-forcing the OTP by exhausting a long-lived pending token.
//
// The pending token (cookie: hp_mfa_pending) is validated and consumed here.
// The same rate-limiting that applies to login.php does not re-apply here —
// consuming the pending token on the first wrong attempt is already the
// primary defence against brute force at this step.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/Totp.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();

// CSRF check — mfa_verify.php is a POST during the login flow, before a full
// session exists. The frontend receives a CSRF token at... wait: the CSRF cookie
// is issued by issueSession() and session.php — but at this point the user hasn't
// completed login yet, so no session was issued, so there's no CSRF cookie yet.
// This is a genuine bootstrapping gap in the double-submit pattern: the very first
// POST in the auth flow (password→login.php, otp→mfa_verify.php) cannot be CSRF-
// protected by the double-submit cookie because the cookie doesn't exist yet.
//
// Mitigating factors that make this acceptable without a different CSRF scheme:
// 1. mfa_verify.php requires a valid hp_mfa_pending cookie — itself set via an
//    httpOnly Strict cookie. A cross-origin attacker can't trigger login.php to
//    set that cookie AND then forge the mfa_verify.php request in the same
//    cross-origin context, because the pending cookie is httpOnly/Strict.
// 2. The pending token is single-use and short-lived (10 min).
// 3. The login flow (login.php + mfa_verify.php) is the CSRF-protected action's
//    *target*, not the vector — the attack model for CSRF is an attacker causing
//    a logged-in user to take an authenticated action, which login itself is not.
//
// Conclusion: CSRF check intentionally omitted from mfa_verify.php. The existing
// pending-token + httpOnly Strict cookie chain is sufficient for the login flow.
// Document this decision here rather than leaving a silent gap.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$code  = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Authenticator code is required.']);
    exit();
}

// Consume the pending token — single-use. If the OTP is wrong below, the
// user must start over at login.php (re-enter password).
$pending = verifyMfaPendingToken($db, 'login');
$userId  = $pending['user_id'];

// Fetch the user's MFA secret and full session fields
$stmt = $db->prepare("SELECT id, tenant_id, role, mfa_secret FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user || empty($user['mfa_secret'])) {
    // Should not normally happen (login.php only issues a pending token when
    // mfa_secret is set), but guard against a race condition or data inconsistency.
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'MFA configuration error. Please sign in again.']);
    exit();
}

if (!Totp::verify($user['mfa_secret'], $code)) {
    // Pending token already consumed above — user must restart from login.php.
    // Identical message to "expired" to avoid leaking which step failed.
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Authenticator code is incorrect or expired. Please sign in again.',
    ]);
    exit();
}

// OTP is valid — issue a full session. This is exactly what login.php does
// for non-MFA users after password verification.
issueSession($db, (int) $user['id'], (int) $user['tenant_id'], $user['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'           => (int) $user['id'],
        'tenant_id'    => (int) $user['tenant_id'],
        'role'         => $user['role'],
        // True here by construction — this path only runs for MFA-enrolled users
        // whose OTP just verified. Derived from the same column for consistency.
        'mfa_enrolled' => !empty($user['mfa_secret']),
    ],
]);
