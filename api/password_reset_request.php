<?php
declare(strict_types=1);

// Self-service "forgot password" — step 1 of 2: request a reset link by email.
//
// Unauthenticated by nature (the whole point is recovering an account you're
// locked out of), so — like login.php and mfa_verify.php — there is no
// verifyAccess()/CSRF check here: no session exists yet for a double-submit
// cookie to protect. See mfa_verify.php's docblock for the fuller reasoning;
// the same conclusion applies here.
//
// Anti-enumeration: the HTTP response is byte-for-byte identical whether or
// not the email belongs to a real account. Do not add any branch that lets
// the response, status code, or timing profile differ on that question.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/Mailer.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

const RESET_TOKEN_TTL_SECONDS  = 30 * 60; // 30 minutes to click the link
const RESET_REQUEST_COOLDOWN_SECONDS = 120; // don't re-email the same account more than once per 2 min

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string) ($input['email'] ?? '')));

// The generic response every path below returns — declared once so every
// branch (found/not-found/cooldown) is provably identical, not just similar.
$genericResponse = [
    'status'  => 'success',
    'message' => 'If that email is registered, a password reset link has been sent.',
];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Even a malformed email gets the generic response — a validation-error
    // branch here would be a timing/shape oracle for "is this well-formed
    // enough to possibly exist," which is unnecessary information to leak.
    echo json_encode($genericResponse);
    exit();
}

$db = getPdo();

$stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if ($user) {
    $userId = (int) $user['id'];

    // Cooldown: skip issuing (and emailing) another token if a still-valid one
    // was created for this user very recently. Caps email-bombing a known
    // account to at most one message per RESET_REQUEST_COOLDOWN_SECONDS,
    // without needing a whole separate rate-limit table for this endpoint.
    $recent = $db->prepare(
        'SELECT id FROM password_resets
         WHERE user_id = :user_id
           AND used_at IS NULL
           AND created_at > (NOW() - INTERVAL ' . (int) RESET_REQUEST_COOLDOWN_SECONDS . ' SECOND)
         LIMIT 1'
    );
    // NOTE: interval value is inlined, not bound — same reasoning as
    // checkLoginRateLimit() in security_gatekeeper.php: MySQL's native
    // prepared statements reject a parameter marker in the "INTERVAL ? SECOND"
    // position. The value is a hardcoded int constant cast defensively, so
    // inlining carries no injection risk.
    $recent->execute([':user_id' => $userId]);

    if (!$recent->fetch()) {
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL_SECONDS);

        $db->prepare(
            'INSERT INTO password_resets (token_hash, user_id, expires_at)
             VALUES (:token_hash, :user_id, :expires_at)'
        )->execute([
            ':token_hash' => $tokenHash,
            ':user_id'    => $userId,
            ':expires_at' => $expiresAt,
        ]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $link   = "{$scheme}://{$host}/reset-password?token={$rawToken}";

        sendMail(
            $email,
            'Reset your HorizonPlan password',
            "A password reset was requested for your HorizonPlan account.\n\n"
            . "Reset your password here (expires in 30 minutes):\n{$link}\n\n"
            . "If you didn't request this, you can safely ignore this email — "
            . "your password will not be changed."
        );
    }
}

echo json_encode($genericResponse);
