<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_config.php';

// Cookie config for session tokens — httpOnly/secure/sameSite per docs/02 Section 3.2.
// Never returned in a JSON body for client-side (localStorage) storage.
const SESSION_COOKIE_NAME = 'hp_session';
const SESSION_TTL_SECONDS = 3600 * 8; // 8 hours

const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 15;
const LOGIN_RATE_LIMIT_MAX_ATTEMPTS   = 5;

/**
 * Check whether an email/IP combination has too many recent failed login
 * attempts to allow another try right now. Call this BEFORE verifying a
 * password, so a locked-out attacker can't keep guessing.
 */
function checkLoginRateLimit(PDO $db, string $email, string $ipAddress): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE (email = :email OR ip_address = :ip)
           AND successful = 0
           AND attempted_at > (NOW() - INTERVAL :window MINUTE)"
    );
    $stmt->execute([
        ':email'  => $email,
        ':ip'     => $ipAddress,
        ':window' => LOGIN_RATE_LIMIT_WINDOW_MINUTES,
    ]);
    $failedCount = (int) $stmt->fetchColumn();

    return $failedCount < LOGIN_RATE_LIMIT_MAX_ATTEMPTS;
}

/**
 * Record a login attempt (successful or not) for rate-limiting purposes.
 * Always call this after a login attempt, whether it succeeded or failed.
 */
function recordLoginAttempt(PDO $db, string $email, string $ipAddress, bool $successful): void
{
    $stmt = $db->prepare(
        "INSERT INTO login_attempts (email, ip_address, successful)
         VALUES (:email, :ip, :successful)"
    );
    $stmt->execute([
        ':email'      => $email,
        ':ip'         => $ipAddress,
        ':successful' => $successful ? 1 : 0,
    ]);
}

/**
 * Issue a new session after successful authentication: generates a
 * cryptographically random token, stores it in active_sessions, and sets
 * it as an httpOnly/secure/sameSite cookie. Never returns the token in the
 * response body.
 */
function issueSession(PDO $db, int $userId, int $tenantId, string $role): void
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL_SECONDS);

    $stmt = $db->prepare(
        "INSERT INTO active_sessions (token, user_id, tenant_id, role, expires_at)
         VALUES (:token, :user_id, :tenant_id, :role, :expires_at)"
    );
    $stmt->execute([
        ':token'      => $token,
        ':user_id'    => $userId,
        ':tenant_id'  => $tenantId,
        ':role'       => $role,
        ':expires_at' => $expiresAt,
    ]);

    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => time() + SESSION_TTL_SECONDS,
        'path'     => '/',
        'secure'   => true,     // HTTPS only
        'httponly' => true,     // not readable from JS — mitigates XSS token theft
        'samesite' => 'Strict', // mitigates CSRF
    ]);
}

/**
 * Verify the current request's session cookie, enforce role, and return the
 * session record (user_id, tenant_id, role) on success. Exits with a JSON
 * error response on any failure — callers don't need to handle a false/null
 * return, execution simply stops here on auth failure.
 */
function verifyAccess(PDO $db, string $requiredRole): array
{
    header('Content-Type: application/json; charset=UTF-8');

    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No active session.']);
        exit();
    }

    $stmt = $db->prepare(
        "SELECT user_id, tenant_id, role FROM active_sessions
         WHERE token = :token AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $session = $stmt->fetch();

    if (!$session) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired session context.']);
        exit();
    }

    if ($session['role'] !== $requiredRole && $session['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
        exit();
    }

    return $session; // ['user_id' => ..., 'tenant_id' => ..., 'role' => ...]
}

/**
 * Destroy the current session on logout: removes the active_sessions row
 * and clears the cookie.
 */
function destroySession(PDO $db): void
{
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($token !== '') {
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE token = :token");
        $stmt->execute([':token' => $token]);
    }

    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
