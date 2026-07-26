<?php
declare(strict_types=1);

// "Sign in with Google" — step 1 and only step (see CLAUDE.md's Google
// Sign-In writeup for the full decision). The frontend's Google Identity
// Services button hands us a signed ID token (the "credential"); once we
// verify it came from Google and matches an existing HorizonPlan account,
// we issue a full session directly — the same thing mfa_verify.php does
// after a correct TOTP code. No pending-MFA dance here: a verified Google
// login *is* treated as satisfying the mandatory-MFA requirement, not as a
// first factor that still needs a second one layered on top.
//
// Unauthenticated and CSRF-exempt, same reasoning as login.php/mfa_verify.php
// — no session/CSRF cookie exists yet at this point in the flow.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/GoogleAuth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '') {
    // Not configured on this deployment — fail closed with a clear message
    // rather than a confusing downstream error. See db_config.example.php.
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Google Sign-In is not configured on this server.']);
    exit();
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$credential = trim((string) ($input['credential'] ?? ''));
$ipAddress  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($credential === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Google credential is required.']);
    exit();
}

$db = getPdo();

// Reuses the existing login-attempt limiter keyed by IP only (empty email) —
// this endpoint doesn't know an email until the token is decoded, and a bad
// actor sending garbage tokens still costs a network round trip to Google
// plus a DB write per attempt, worth capping the same way password guessing
// is. Deliberately the same table/functions as login.php, not a new mechanism.
if (!checkLoginRateLimit($db, '', $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Try again later.']);
    exit();
}

$payload = fetchGoogleTokenInfo($credential);

if ($payload === null || !validateGooglePayload($payload, GOOGLE_CLIENT_ID)) {
    recordLoginAttempt($db, '', $ipAddress, false);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Google sign-in could not be verified. Please try again.']);
    exit();
}

try {
    $user = resolveOrLinkGoogleUser($db, (string) $payload['email'], (string) $payload['sub']);
} catch (GoogleAuthException $e) {
    recordLoginAttempt($db, '', $ipAddress, false);
    http_response_code($e->httpStatus);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit();
}

recordLoginAttempt($db, '', $ipAddress, true);

// Verified Google login — issue a full session, same call login.php/
// mfa_verify.php make once their own authentication is complete.
issueSession($db, (int) $user['id'], (int) $user['tenant_id'], $user['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'                => (int) $user['id'],
        'tenant_id'         => (int) $user['tenant_id'],
        'role'              => $user['role'],
        // Overall "has this account satisfied mandatory MFA" — true here by
        // construction (a verified Google login always satisfies it), same
        // convention as login.php/mfa_verify.php's mfa_enrolled field.
        'mfa_enrolled'      => true,
        'mfa_totp_enrolled' => !empty($user['mfa_secret']),
        'google_linked'     => true,
    ],
]);
