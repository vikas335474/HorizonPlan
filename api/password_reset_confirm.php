<?php
declare(strict_types=1);

// Self-service "forgot password" — step 2 of 2: redeem the emailed token for
// a new password.
//
// Unauthenticated by nature (no session exists to CSRF-protect yet) — same
// reasoning as login.php / mfa_verify.php / password_reset_request.php, no
// verifyAccess()/CSRF check here. There is no enumeration concern on this
// endpoint the way there is on the request step: reaching this endpoint
// already requires possessing the raw token from the email, so a "token not
// found" response reveals nothing an attacker didn't already need to have.
//
// Security properties enforced here:
//   - the token is looked up by its SHA-256 hash (see sql/009_password_resets.sql
//     for why the raw token is never stored);
//   - single-use: expires_at/used_at are checked together, and used_at is set
//     on redemption so the same token can never work twice;
//   - redeeming a token invalidates every OTHER outstanding token for that user
//     (an old, unused reset email can't quietly still work after a newer one
//     was used) and every active session (a stolen session gets logged out the
//     moment the legitimate owner recovers the account).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$rawToken    = (string) ($input['token'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');

if ($rawToken === '' || $newPassword === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token and new password are required.']);
    exit();
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'New password must be at least 8 characters.']);
    exit();
}

$db = getPdo();

$tokenHash = hash('sha256', $rawToken);

$stmt = $db->prepare(
    'SELECT id, user_id FROM password_resets
     WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
     LIMIT 1'
);
$stmt->execute([':token_hash' => $tokenHash]);
$reset = $stmt->fetch();

if (!$reset) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This reset link is invalid or has expired. Request a new one.']);
    exit();
}

$userId = (int) $reset['user_id'];

$db->beginTransaction();
try {
    $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
       ->execute([':hash' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $userId]);

    // Mark this token used, and invalidate every other outstanding token for
    // this user in the same statement — a second, older reset email can't
    // still be redeemed after this one just was.
    $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
       ->execute([':user_id' => $userId]);

    // Log out every existing session for this account — if a session was
    // compromised (the reason for the reset in the first place), it dies here.
    $db->prepare('DELETE FROM active_sessions WHERE user_id = :user_id')
       ->execute([':user_id' => $userId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

echo json_encode([
    'status'  => 'success',
    'message' => 'Password updated. Please sign in with your new password.',
]);
