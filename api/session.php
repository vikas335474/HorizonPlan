<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

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
$mfaStmt = $db->prepare("SELECT mfa_secret FROM users WHERE id = :id LIMIT 1");
$mfaStmt->execute([':id' => (int) $session['user_id']]);
$mfaRow = $mfaStmt->fetch();

// Tenant-level context the client-facing UI needs: the compliance disclosure
// mode (docs/02 Section 3.6 — must be read from the tenant, never hardcoded)
// and the white-label branding. advisory_mode is only ever set server-side by a
// Super Admin, so the frontend reads it here but can never change it.
$tenantStmt = $db->prepare("SELECT company_name, advisory_mode, white_label_settings FROM tenants WHERE id = :id LIMIT 1");
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

echo json_encode([
    'status' => 'success',
    'user'   => [
        'user_id'      => (int) $session['user_id'],
        'tenant_id'    => (int) $session['tenant_id'],
        'role'         => $session['role'],
        'mfa_enrolled' => !empty($mfaRow['mfa_secret']),
    ],
    'tenant' => [
        'company_name'  => $tenantRow['company_name'] ?? null,
        'advisory_mode' => $tenantRow['advisory_mode'] ?? 'distribution',
        'white_label'   => $whiteLabel,
    ],
]);
