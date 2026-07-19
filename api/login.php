<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
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

$stmt = $db->prepare("SELECT id, tenant_id, role, password_hash, mfa_secret FROM users WHERE email = :email LIMIT 1");
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

// MFA not yet enrolled — issue a full session. Once MFA enrollment is
// mandatory (post-MVP), this branch should redirect to enrollment instead.
issueSession($db, (int) $user['id'], (int) $user['tenant_id'], $user['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'        => (int) $user['id'],
        'tenant_id' => (int) $user['tenant_id'],
        'role'      => $user['role'],
    ],
]);
