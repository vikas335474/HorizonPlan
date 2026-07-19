<?php
declare(strict_types=1);

// MFA enrollment step 1 of 2.
//
// Any authenticated user calls this to begin TOTP setup. It:
//   1. Generates a new TOTP secret
//   2. Stores the secret in mfa_pending.payload (not users.mfa_secret yet —
//      we only activate MFA once the user proves their authenticator app works)
//   3. Issues an 'enroll' pending token in a cookie
//   4. Returns the otpauth:// URI for QR code rendering + the raw base32 secret
//      for manual entry fallback
//
// The secret is intentionally NOT written to users.mfa_secret here. Writing it
// before confirmation would lock out any user who set up the table entry but
// never confirmed a working OTP.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/Totp.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccessAny($db, ['client', 'advisor', 'super_admin']);
$userId  = (int) $session['user_id'];

// Check if MFA is already enrolled
$stmt = $db->prepare("SELECT email, mfa_secret FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'User record not found.']);
    exit();
}

if (!empty($user['mfa_secret'])) {
    http_response_code(409);
    echo json_encode([
        'status'  => 'error',
        'message' => 'MFA is already enrolled for this account.',
    ]);
    exit();
}

$secret = Totp::generateSecret();
$uri    = Totp::otpauthUri($secret, $user['email']);

// Store the secret in mfa_pending.payload — it moves to users.mfa_secret only
// if mfa_enroll_confirm.php verifies the first OTP successfully.
issueMfaPendingToken($db, $userId, 'enroll', $secret);

echo json_encode([
    'status'      => 'success',
    'secret'      => $secret,   // base32 — for manual-entry fallback in the authenticator app
    'otpauth_uri' => $uri,      // for QR code rendering on the frontend
    'message'     => 'Scan the QR code in your authenticator app, then submit a code to confirm.',
]);
