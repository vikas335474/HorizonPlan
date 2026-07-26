<?php
declare(strict_types=1);

// Install JSON error handling before requiring anything else — including
// db_config.php. A missing/broken db_config.php (it's gitignored; must be
// hand-created on the server per DEPLOY.md) throws its own fatal on the
// require below, and that fatal must be caught too, not just DB-query errors
// that happen after this file finishes loading.
require_once __DIR__ . '/error_handler.php';
installApiErrorHandlers();

require_once __DIR__ . '/../db_config.php';

// Cookie config for session tokens — httpOnly/secure/sameSite per docs/02 Section 3.2.
// Never returned in a JSON body for client-side (localStorage) storage.
const SESSION_COOKIE_NAME = 'hp_session';
const SESSION_TTL_SECONDS = 3600 * 8; // 8 hours

const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 15;
const LOGIN_RATE_LIMIT_MAX_ATTEMPTS   = 5;

// docs/09 Group 1 Session 3 — demo_reset.php cooldown. Every goals_*/
// subscenarios_*/platform_settings_* endpoint already requires an
// authenticated, CSRF-verified session, and at this app's scale an
// authenticated user hammering the DB is a low-probability, low-blast-radius
// risk — general rate limiting on those is deliberately deferred (not
// built), per the decision recorded in CLAUDE.md. demo_reset.php is the one
// real exception: a repeated call re-seeds 160 clients (~40s each), a
// genuine self-inflicted DoS even from a legitimate super_admin session.
const DEMO_RESET_COOLDOWN_MINUTES = 5;

// MFA-pending token: lives in the mfa_pending table, ties a completed
// password-check to the subsequent OTP-verify step. Short-lived by design.
const MFA_PENDING_COOKIE_NAME = 'hp_mfa_pending';
const MFA_PENDING_TTL_SECONDS = 60 * 10; // 10 minutes to open authenticator app

// CSRF: double-submit cookie pattern. The server sets a readable (non-httpOnly)
// cookie; every state-changing request must echo it back as X-CSRF-Token. The
// backend checks both match. Works without session storage and fits the
// same-origin deployment model naturally.
// Read-only endpoints (GET) are exempt — CSRF only applies to state changes.
const CSRF_COOKIE_NAME = 'hp_csrf';
const CSRF_TTL_SECONDS = 3600 * 8; // matches session TTL
const CSRF_HEADER_NAME = 'X-CSRF-Token';

/**
 * Check whether an email/IP combination has too many recent failed login
 * attempts to allow another try right now. Call this BEFORE verifying a
 * password, so a locked-out attacker can't keep guessing.
 */
function checkLoginRateLimit(PDO $db, string $email, string $ipAddress): bool
{
    // NOTE: the window is inlined, not bound. MySQL native prepared statements
    // (EMULATE_PREPARES => false) reject a parameter marker in the
    // "INTERVAL ? MINUTE" position with a syntax error, which would make every
    // login 500 before the password is ever checked. The value is a hardcoded
    // integer constant — cast defensively — so inlining carries no injection risk.
    $windowMinutes = (int) LOGIN_RATE_LIMIT_WINDOW_MINUTES;
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE (email = :email OR ip_address = :ip)
           AND successful = 0
           AND attempted_at > (NOW() - INTERVAL {$windowMinutes} MINUTE)"
    );
    $stmt->execute([
        ':email'  => $email,
        ':ip'     => $ipAddress,
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
 * Atomically claim a demo_reset.php run. Succeeds (and stamps
 * platform_settings.last_reset_at = NOW() in the same statement) only if
 * the last reset was outside DEMO_RESET_COOLDOWN_MINUTES, or has never run.
 * The UPDATE's WHERE clause IS the whole mechanism — there's no separate
 * read-then-write, so two near-simultaneous calls can't both pass a "read"
 * check before either writes: the second call's UPDATE simply matches zero
 * rows once the first has committed its stamp. Stamping happens at claim
 * time (before the reset's ~40s of work runs), not after, so a reset that's
 * still in flight doesn't leave a window for a concurrent second call.
 * Returns true if claimed (caller may proceed), false if still cooling down.
 */
function claimDemoResetSlot(PDO $db): bool
{
    // Same INTERVAL-inlining reasoning as checkLoginRateLimit(): MySQL native
    // prepared statements reject a parameter marker inside "INTERVAL ? MINUTE".
    // The value is a hardcoded integer constant, cast defensively, so
    // inlining carries no injection risk.
    $minutes = (int) DEMO_RESET_COOLDOWN_MINUTES;
    $stmt = $db->prepare(
        "UPDATE platform_settings
         SET last_reset_at = NOW()
         WHERE id = 1
           AND (last_reset_at IS NULL OR last_reset_at <= NOW() - INTERVAL {$minutes} MINUTE)"
    );
    $stmt->execute();

    return $stmt->rowCount() === 1;
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

    // Issue a paired CSRF token. The session cookie is httpOnly (JS can't
    // read it); the CSRF cookie is not (JS must be able to read it to send
    // it back as a header). Together they form the double-submit pair.
    issueCsrfToken();
}

/**
 * Look up the current request's session without enforcing a role or exiting
 * on failure — returns the session array or null. Use this when an endpoint
 * needs to know "who is logged in, if anyone" (e.g. a session-check or
 * logout endpoint) rather than requiring a specific role. verifyAccess()
 * builds on this for the common case where a role IS required.
 */
function getCurrentSession(PDO $db): ?array
{
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($token === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT user_id, tenant_id, role FROM active_sessions
         WHERE token = :token AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $session = $stmt->fetch();

    return $session ?: null;
}

/**
 * Verify the current request's session cookie, enforce role, and return the
 * session record (user_id, tenant_id, role) on success. Exits with a JSON
 * error response on any failure — callers don't need to handle a false/null
 * return, execution simply stops here on auth failure.
 *
 * $requireMfaEnrolled defaults to true — MFA enrollment is mandatory (see
 * "Security status" in CLAUDE.md), so every endpoint is blocked until the
 * account has completed TOTP enrollment unless it explicitly opts out. The
 * only real caller of `false` is mfa_enroll.php itself, which must stay
 * reachable precisely so an unenrolled user can enroll.
 */
function verifyAccess(PDO $db, string $requiredRole, bool $requireMfaEnrolled = true): array
{
    header('Content-Type: application/json; charset=UTF-8');

    // CSRF check before session check — a missing token gets the same 403 as a
    // wrong one, giving no information about session validity to an attacker.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyCsrfToken();
    }

    $session = getCurrentSession($db);

    if ($session === null) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired session context.']);
        exit();
    }

    if ($session['role'] !== $requiredRole && $session['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
        exit();
    }

    if ($requireMfaEnrolled) {
        requireMfaEnrollment($db, $session);
    }

    return $session; // ['user_id' => ..., 'tenant_id' => ..., 'role' => ...]
}

/**
 * Same contract as verifyAccess(), but for endpoints reachable by more than
 * one role — e.g. base_plans/sub_scenarios reads are legitimate for both
 * 'advisor' and 'client' sessions, just with different write permissions
 * enforced by the calling endpoint. super_admin is always allowed, same as
 * verifyAccess(). Exits with a JSON error response on failure, same as
 * verifyAccess() — callers don't need to handle a null return.
 *
 * $requireMfaEnrolled — see verifyAccess()'s docblock; same default, same
 * one exemption (mfa_enroll.php).
 *
 * @param string[] $allowedRoles
 */
function verifyAccessAny(PDO $db, array $allowedRoles, bool $requireMfaEnrolled = true): array
{
    header('Content-Type: application/json; charset=UTF-8');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyCsrfToken();
    }

    $session = getCurrentSession($db);

    if ($session === null) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired session context.']);
        exit();
    }

    if (!in_array($session['role'], $allowedRoles, true) && $session['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
        exit();
    }

    if ($requireMfaEnrolled) {
        requireMfaEnrollment($db, $session);
    }

    return $session;
}

/**
 * Whether the given user has satisfied mandatory MFA enrollment — either a
 * TOTP secret (users.mfa_secret) or a linked Google account (users.google_sub)
 * is enough; both are alternative ways of satisfying the same requirement, not
 * a stacked "both required" check (see auth_google.php / GoogleAuth.php for
 * why a verified Google login counts as MFA in its own right). Pure DB read,
 * no session/exit side effects — kept separate from requireMfaEnrollment() so
 * it's directly unit-testable against a real DB without needing an HTTP
 * context to exercise the 403 exit path.
 */
function userHasMfaEnrolled(PDO $db, int $userId): bool
{
    $stmt = $db->prepare("SELECT mfa_secret, google_sub FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    return $row !== false && (!empty($row['mfa_secret']) || !empty($row['google_sub']));
}

/**
 * Read the single-row platform_settings config (docs/09 Piece 1). Cached in a
 * static variable so repeated calls within the same request — e.g.
 * verifyAccess() plus an endpoint that separately wants to know demo_mode —
 * only cost one query, not one per call. Each PHP-FPM/php-S request is a
 * fresh process, so this cache never leaks across requests.
 *
 * Defensive default (mfa_enforcement=enabled, demo_mode=off) if the table is
 * somehow missing its seeded row — fails closed toward the stricter
 * production behavior, never toward accidentally skipping MFA.
 *
 * $forceRefresh bypasses the cache — never needed in a real request (one PHP
 * process per request means the cache never goes stale mid-request), but lets
 * a test script exercise multiple toggle states within a single process
 * without the cache masking a real DB change.
 *
 * @return array{mfa_enforcement: string, demo_mode: string, signup_enabled: string}
 */
function getPlatformSettings(PDO $db, bool $forceRefresh = false): array
{
    static $cached = null;
    if ($cached !== null && !$forceRefresh) {
        return $cached;
    }

    $stmt = $db->query("SELECT mfa_enforcement, demo_mode, signup_enabled FROM platform_settings WHERE id = 1 LIMIT 1");
    $row = $stmt ? $stmt->fetch() : false;

    $cached = [
        'mfa_enforcement' => $row['mfa_enforcement'] ?? 'enabled',
        'demo_mode'       => $row['demo_mode'] ?? 'off',
        // Fails closed toward the stricter posture (signups off) if the row
        // can't be read — same defensive default reasoning as the other two.
        'signup_enabled'  => $row['signup_enabled'] ?? 'off',
    ];

    return $cached;
}

/**
 * Enforce mandatory MFA enrollment for the current session. Exits with a 403
 * JSON response (carrying mfa_enrollment_required: true, so a caller could
 * branch on it distinctly from a plain role-based 403, though today the
 * frontend gate works off the session's own mfa_enrolled flag instead) if
 * the account hasn't completed TOTP enrollment. Called from verifyAccess()/
 * verifyAccessAny() by default — see those docblocks for the one exemption.
 *
 * docs/09 Piece 1: platform_settings can lift this requirement wholesale —
 * demo_mode='on' implies MFA is skipped regardless of mfa_enforcement, and
 * mfa_enforcement='disabled' on its own also skips it (useful for testing
 * without the rest of demo-mode's behavior). Checked first, before touching
 * the user's own mfa_secret, so an unenrolled account in demo mode never
 * even needs one.
 */
function requireMfaEnrollment(PDO $db, array $session): void
{
    $settings = getPlatformSettings($db);
    if ($settings['demo_mode'] === 'on' || $settings['mfa_enforcement'] === 'disabled') {
        return;
    }

    if (userHasMfaEnrolled($db, (int) $session['user_id'])) {
        return;
    }

    http_response_code(403);
    echo json_encode([
        'status'                  => 'error',
        'message'                 => 'Two-factor authentication setup is required before you can continue.',
        'mfa_enrollment_required' => true,
    ]);
    exit();
}

/**
 * Issue a short-lived MFA-pending token after a successful password check.
 * Sets a non-httpOnly cookie (it must be readable by PHP on the next step,
 * but is never exposed to JS — hence httponly:true here too). The user's
 * next request must submit the OTP to mfa_verify.php within MFA_PENDING_TTL_SECONDS.
 *
 * @param string $purpose 'login' or 'enroll'
 */
function issueMfaPendingToken(PDO $db, int $userId, string $purpose, ?string $payload = null): void
{
    // Clean up any existing pending tokens for this user+purpose first —
    // if the user starts MFA twice in a row, the older token is useless.
    $db->prepare("DELETE FROM mfa_pending WHERE user_id = :uid AND purpose = :purpose")
       ->execute([':uid' => $userId, ':purpose' => $purpose]);

    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + MFA_PENDING_TTL_SECONDS);

    $db->prepare(
        "INSERT INTO mfa_pending (token, user_id, purpose, payload, expires_at)
         VALUES (:token, :user_id, :purpose, :payload, :expires_at)"
    )->execute([
        ':token'      => $token,
        ':user_id'    => $userId,
        ':purpose'    => $purpose,
        ':payload'    => $payload,
        ':expires_at' => $expiresAt,
    ]);

    setcookie(MFA_PENDING_COOKIE_NAME, $token, [
        'expires'  => time() + MFA_PENDING_TTL_SECONDS,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,   // JS doesn't need to read this; only PHP step 2 does
        'samesite' => 'Strict',
    ]);
}

/**
 * Verify and consume an MFA-pending token from the cookie. Returns the
 * user_id if valid, exits with 401 otherwise. Consuming (deleting) the
 * token on use prevents replay attacks — each pending token is single-use.
 *
 * @param string $purpose 'login' or 'enroll' — must match what was issued
 * @return array{user_id: int, payload: ?string}
 */
function verifyMfaPendingToken(PDO $db, string $purpose): array
{
    $token = $_COOKIE[MFA_PENDING_COOKIE_NAME] ?? '';

    if ($token === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'MFA session not found or expired.']);
        exit();
    }

    $stmt = $db->prepare(
        "SELECT id, user_id, payload FROM mfa_pending
         WHERE token = :token AND purpose = :purpose AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([':token' => $token, ':purpose' => $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'MFA session not found or expired.']);
        exit();
    }

    // Consume the token immediately — single-use
    $db->prepare("DELETE FROM mfa_pending WHERE id = :id")->execute([':id' => $row['id']]);

    // Clear the pending cookie
    setcookie(MFA_PENDING_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // Return both user_id and payload (payload is the TOTP secret for 'enroll'
    // purpose, null for 'login' purpose).
    return ['user_id' => (int) $row['user_id'], 'payload' => $row['payload']];
}

/**
 * docs/09 Piece 2 — check a session's firm-level role (jr_advisor/sr_advisor/
 * firm_admin) against an allowed list. Only meaningful for 'advisor' role
 * sessions; a super_admin session always passes (same "super_admin bypasses
 * every role gate" convention as verifyAccess()/verifyAccessAny()), since
 * firm_role has no meaning cross-tenant for a platform admin.
 *
 * Backward compatibility: an advisor with firm_role = NULL (every advisor
 * created before migration 020) is treated as sr_advisor — the middle tier,
 * not the most permissive (firm_admin) or least (jr_advisor) — so existing
 * accounts keep exactly the approval capability they had before this
 * feature existed, and nothing silently gains firm-admin-only capabilities
 * (add/manage advisors, edit firm branding) it never had.
 *
 * Exits with a 403 JSON response on failure, same contract as verifyAccess().
 *
 * @param array $session the session array from verifyAccess()/verifyAccessAny() —
 *   must additionally carry 'firm_role' (callers fetch it themselves; see
 *   admin_advisor_create.php / templates_approve.php for the query pattern)
 * @param string[] $allowedFirmRoles
 */
function requireFirmRole(PDO $db, array $session, array $allowedFirmRoles): void
{
    if ($session['role'] === 'super_admin') {
        return;
    }

    $stmt = $db->prepare("SELECT firm_role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int) $session['user_id']]);
    $row = $stmt->fetch();

    $firmRole = ($row && $row['firm_role'] !== null) ? $row['firm_role'] : 'sr_advisor';

    if (!in_array($firmRole, $allowedFirmRoles, true)) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'This action requires senior advisor or firm admin privileges.',
        ]);
        exit();
    }
}

/**
 * Issue or refresh the CSRF double-submit cookie. Call this after every
 * successful session issuance (login, MFA verify) and on session.php so the
 * frontend always has a fresh token to attach to mutation requests.
 *
 * The cookie is intentionally NOT httpOnly so the JS fetch layer can read it
 * (that's the whole point of double-submit). The session cookie remains
 * httpOnly — the CSRF token has no secret value on its own, it only proves
 * "the JS that sent this request could read the cookie, therefore it ran on
 * the same origin."
 */
function issueCsrfToken(): void
{
    $token = bin2hex(random_bytes(32));

    setcookie(CSRF_COOKIE_NAME, $token, [
        'expires'  => time() + CSRF_TTL_SECONDS,
        'path'     => '/',
        'secure'   => true,
        'httponly' => false,  // deliberately readable by JS — see docblock above
        'samesite' => 'Strict',
    ]);
}

/**
 * Verify the CSRF double-submit token on a state-changing request.
 * Exits with 403 if the header is missing or doesn't match the cookie.
 *
 * Only call this on POST/PUT/PATCH/DELETE requests — GET endpoints are
 * read-only and exempt from CSRF checks by convention.
 */
function verifyCsrfToken(): void
{
    $cookie = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
        exit();
    }
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

    setcookie(CSRF_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Strict',
    ]);
}
