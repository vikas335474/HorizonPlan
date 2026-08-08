<?php
declare(strict_types=1);

// Authentication / bootstrap: "who am I, and what does my UI need to know?"
// Accepts any method. Returns 401 {No active session} when not logged in — an
// expected, normal response on first page load, not an error. On a valid
// session it re-issues the CSRF token (keeping it fresh across reloads) and
// returns three blocks: user (ids, role, firm_role, MFA flags derived from
// mfa_secret/google_sub, is_demo), tenant (company_name, advisory_mode — read
// here but only ever set server-side by a super_admin — white_label branding,
// requires_plan_review), and platform (mfa_enforcement, demo_mode). Reads
// tenants/users directly (not via TenantScopedDb) for its own session's row.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/DemoAccess.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();
$session = getCurrentSession($db);

if ($session === null) {
    // Not an error condition — "not logged in" is an expected, normal response
    // on first page load. Still 401 so the frontend can branch on status code
    // without inspecting the body.
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No active session.']);
    exit();
}

// Re-issue the CSRF token on every session check so it stays fresh after
// page reloads. The JS layer reads it from the cookie and attaches it to
// all subsequent mutation requests as X-CSRF-Token.
issueCsrfToken();

// The active_sessions lookup doesn't carry mfa_secret, so do a small extra
// read to tell the frontend whether this user has MFA enrolled. This drives
// the soft app-layer MFA gate (redirect unenrolled users to Settings) — the
// column value itself is never returned, only the boolean derived from it.
$mfaStmt = $db->prepare("SELECT mfa_secret, google_sub, firm_role, email FROM users WHERE id = :id LIMIT 1");
$mfaStmt->execute([':id' => (int) $session['user_id']]);
$mfaRow = $mfaStmt->fetch();

// Tenant-level context the client-facing UI needs: the compliance disclosure
// mode (docs/02 Section 3.6 — must be read from the tenant, never hardcoded)
// and the white-label branding. advisory_mode is only ever set server-side by a
// Super Admin, so the frontend reads it here but can never change it.
$tenantStmt = $db->prepare("SELECT company_name, kind, advisory_mode, white_label_settings, requires_plan_review FROM tenants WHERE id = :id LIMIT 1");
$tenantStmt->execute([':id' => (int) $session['tenant_id']]);
$tenantRow = $tenantStmt->fetch();

// white_label_settings is stored as a JSON string (nullable). Decode defensively
// so a malformed value degrades to "no branding" rather than breaking the session.
$whiteLabel = null;
if ($tenantRow && !empty($tenantRow['white_label_settings'])) {
    $decoded = json_decode((string) $tenantRow['white_label_settings'], true);
    if (is_array($decoded)) {
        $whiteLabel = $decoded;
    }
}

// docs/09 Piece 1: expose platform-wide MFA enforcement / demo mode so the
// frontend can skip the forced-enrollment redirect and show the demo banner
// without a separate round trip.
$platformSettings = getPlatformSettings($db);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'user_id'      => (int) $session['user_id'],
        'tenant_id'    => (int) $session['tenant_id'],
        'role'         => $session['role'],
        'firm_role'    => $mfaRow['firm_role'] ?? null,
        // MFA is satisfied by either factor — TOTP or a linked Google
        // account (see security_gatekeeper.php::userHasMfaEnrolled()).
        'mfa_enrolled'      => !empty($mfaRow['mfa_secret']) || !empty($mfaRow['google_sub']),
        'mfa_totp_enrolled' => !empty($mfaRow['mfa_secret']),
        'google_linked'     => !empty($mfaRow['google_sub']),
        // Drives the guided demo tour (DemoTour.jsx) — true only for the
        // synthetic *.demo.horizonplan.in accounts DemoAccess.php already
        // treats as the one safety boundary for the public demo-login path,
        // so the tour can never surface for a real admin-created or
        // trial-signup account.
        'is_demo'           => isDemoAccountEmail((string) ($mfaRow['email'] ?? '')),
    ],
    'tenant' => [
        'company_name'  => $tenantRow['company_name'] ?? null,
        // Self-serve individual tier (sql/033). Drives the plain-language UI
        // and the self_directed disclosure; the client role alone can't tell
        // an individual from an advisor-managed client.
        'kind'          => $tenantRow['kind'] ?? 'firm',
        'advisory_mode' => $tenantRow['advisory_mode'] ?? 'distribution',
        'white_label'   => $whiteLabel,
        // Jr -> Sr Advisor Plan-Approval Workflow (decision #3) — lets the
        // frontend show/hide the submit-for-review action and review queue
        // without a separate round trip.
        'requires_plan_review' => ($tenantRow['requires_plan_review'] ?? 'off') === 'on',
    ],
    'platform' => [
        'mfa_enforcement' => $platformSettings['mfa_enforcement'],
        'demo_mode'       => $platformSettings['demo_mode'],
    ],
]);
