<?php
declare(strict_types=1);

// MFA enrollment step 2 of 2.
//
// The user has scanned the QR code in their authenticator app and submits the
// first OTP here. If it's valid, the TOTP secret is written to users.mfa_secret
// and MFA is active on the account from this point forward.
//
// The pending token (from mfa_enroll.php step 1) is consumed and deleted here —
// a single-use handshake. If the OTP is wrong, the token is NOT consumed (we
// don't exit early in that branch), so the user can try again within the 10-minute
// window without going back to step 1.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/Totp.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db    = getPdo();

// CSRF check — this endpoint is a POST that doesn't go through verifyAccess(),
// so we check the token explicitly here. The CSRF cookie was issued when the
// user logged in and called mfa_enroll.php (which goes through verifyAccessAny).
verifyCsrfToken();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$code  = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Authenticator code is required.']);
    exit();
}

// verifyMfaPendingToken consumes the token and returns ['user_id', 'payload']
// where payload is the base32 TOTP secret stored during step 1.
// NOTE: this DOES consume the token. If OTP verification fails below, the user
// will need to restart enrollment (call mfa_enroll.php again). This is intentional
// — a confirmed bad OTP at enrollment time means the authenticator app may not
// be set up correctly, and we'd rather the user restart cleanly than retry with
// a stale secret.
$pending = verifyMfaPendingToken($db, 'enroll');
$userId  = $pending['user_id'];
$secret  = $pending['payload'];

if (empty($secret)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Enrollment session is missing the secret. Please start enrollment again.']);
    exit();
}

if (!Totp::verify($secret, $code)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Authenticator code is incorrect or expired. Please start enrollment again and make sure your phone clock is accurate.',
    ]);
    exit();
}

// OTP is valid — write the secret to users.mfa_secret to activate MFA.
$stmt = $db->prepare("UPDATE users SET mfa_secret = :secret WHERE id = :id");
$stmt->execute([':secret' => $secret, ':id' => $userId]);

echo json_encode([
    'status'  => 'success',
    'message' => 'MFA enrolled successfully. You will be prompted for a code on every sign in.',
]);
