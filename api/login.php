<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

// NOTE: MFA and password reset are deliberately not implemented in this endpoint —
// see docs/02 Section 3.2. Both are real gaps that must ship before this goes in
// front of real users; they're out of scope for the Phase 3 build prompt this
// file corresponds to. Don't treat this endpoint as launch-ready on its own.

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
    exit();
}

$db = getPdo();

// Rate limit check happens BEFORE any password verification, so a locked-out
// attacker can't keep spending compute on password_verify() calls either.
if (!checkLoginRateLimit($db, $email, $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Try again later.']);
    exit();
}

$stmt = $db->prepare("SELECT id, tenant_id, role, password_hash FROM users WHERE email = :email LIMIT 1");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

// Deliberately identical error for "no such user" and "wrong password" —
// distinguishing them lets an attacker enumerate valid emails.
if (!$user || !password_verify($password, $user['password_hash'])) {
    recordLoginAttempt($db, $email, $ipAddress, false);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    exit();
}

recordLoginAttempt($db, $email, $ipAddress, true);
issueSession($db, (int) $user['id'], (int) $user['tenant_id'], $user['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'        => (int) $user['id'],
        'tenant_id' => (int) $user['tenant_id'],
        'role'      => $user['role'],
    ],
]);
