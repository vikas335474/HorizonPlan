<?php
declare(strict_types=1);

// Lets a signed-in user unlink Google from their own account, falling back to
// password+TOTP. Safe/reversible: if the account has no TOTP secret either
// after this, requireMfaEnrollment() simply starts nagging it again on the
// next gated request — the same "unenrolled" state a brand-new account is in
// — never a lockout, since session.php (used to read state back on the
// frontend) doesn't gate on MFA enrollment at all.
//
// Only ever touches the caller's own row (session's user_id) — there is no
// user_id in the request body, same "can't act on another account" shape as
// password_update.php.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccessAny($db, ['client', 'advisor', 'super_admin']);
$userId  = (int) $session['user_id'];

$db->prepare("UPDATE users SET google_sub = NULL WHERE id = :id")
    ->execute([':id' => $userId]);

echo json_encode([
    'status'  => 'success',
    'message' => 'Google account unlinked.',
]);
