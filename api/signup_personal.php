<?php
declare(strict_types=1);

// Self-serve signup for an INDIVIDUAL planning for themselves — no firm, no
// advisor. POST, unauthenticated by nature (there is no session yet), and
// CSRF-exempt for the same reason as login.php/signup.php.
//
// Creates a "tenant of one" (sql/033): a tenant with kind='personal' and
// advisory_mode='self_directed', containing exactly one user with
// role='client'. See createPersonalTenant() for why each of those three is
// hardcoded rather than accepted as input.
//
// Gated on the SAME platform_settings.signup_enabled toggle as the firm trial
// signup — one switch turns off all self-serve account creation, so an
// operator disabling signups doesn't have to remember there are two doors.
//
// Inputs (JSON body): full_name, email, password (min 8).
// Output: {status, user{...}} and an issued session — the caller is logged
// straight in, same contract as signup.php's non-MFA branch.
// Errors: 400 (validation), 403 (signups disabled), 409 (email taken),
// 429 (rate-limited), 405 (non-POST).

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

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$fullName  = trim((string) ($input['full_name'] ?? ''));
$email     = strtolower(trim((string) ($input['email'] ?? '')));
$password  = (string) ($input['password'] ?? '');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($fullName === '' || mb_strlen($fullName) > 191) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Your name is required.']);
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

// Same IP-keyed limiter as signup.php/auth_google.php — repeated account
// creation from one IP is the thing worth capping here.
if (!checkLoginRateLimit($db, '', $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Try again later.']);
    exit();
}

$existing = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    recordLoginAttempt($db, '', $ipAddress, false);
    // Same reasoning as signup.php: telling a signup form that an email is
    // taken is normal UX, not the enumeration leak it would be on login.
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'An account with that email already exists.']);
    exit();
}

$result = createPersonalTenant($db, $fullName, $email, password_hash($password, PASSWORD_BCRYPT));

recordLoginAttempt($db, '', $ipAddress, true);

// Log them straight in — a signup that then demands a separate login is
// friction with no security value, and matches signup.php's behaviour.
issueSession($db, $result['user_id'], $result['tenant_id'], 'client');

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'                => $result['user_id'],
        'tenant_id'         => $result['tenant_id'],
        'role'              => 'client',
        // A brand-new account has neither factor set up yet, same as
        // signup.php's founding advisor.
        'mfa_enrolled'      => false,
        'mfa_totp_enrolled' => false,
        'google_linked'     => false,
    ],
]);
