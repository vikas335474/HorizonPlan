<?php
declare(strict_types=1);

// Self-service password change.
//
// Requires the caller to re-enter their CURRENT password, not merely hold an
// active session — an attacker on an unlocked machine (or a hijacked session)
// still can't rotate the password without knowing the existing one. This is the
// standard "reauthenticate before a sensitive account change" pattern.
//
// Reachable by any authenticated role (client, advisor, super_admin): every
// user can change their own password. The session's user_id is the only account
// touched — there is no user_id in the request body, so one user can never
// change another user's password through this endpoint.
//
// Error messages are deliberately generic and uniform — we don't reveal which
// individual check failed (wrong current password vs. too-short new password is
// still distinguishable by design here since both are the caller's own input,
// but we never leak anything about account existence or session internals).

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccessAny($db, ['client', 'advisor', 'super_admin']);
$userId  = (int) $session['user_id'];

$input           = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPassword = (string) ($input['current_password'] ?? '');
$newPassword     = (string) ($input['new_password'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Current and new passwords are required.']);
    exit();
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'New password must be at least 8 characters.']);
    exit();
}

if ($newPassword === $currentPassword) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'New password must be different from the current password.']);
    exit();
}

// Fetch the caller's own password hash by session user_id — same "current user's
// own record by id" pattern used in mfa_enroll.php / mfa_verify.php.
$stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

// Reauthenticate: the active session is not enough — the current password must
// be re-supplied and verified. Generic 401 so nothing about the account leaks.
if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect.']);
    exit();
}

$newHash = password_hash($newPassword, PASSWORD_BCRYPT);

$update = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
$update->execute([':hash' => $newHash, ':id' => $userId]);

echo json_encode([
    'status'  => 'success',
    'message' => 'Password updated.',
]);
