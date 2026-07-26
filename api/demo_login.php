<?php
declare(strict_types=1);

// Public "try a live demo" one-click login — logs a visitor straight into a
// seeded demo firm's firm_admin account with no credentials typed anywhere.
// See api/lib/DemoAccess.php for the full reasoning on why this deliberately
// does NOT depend on platform_settings.demo_mode: the target account has a
// real TOTP secret (set by tools/seed_demo_data_full.php), so it's already
// genuinely MFA-enrolled and every other security control runs unmodified.
//
// Unauthenticated and CSRF-exempt, same reasoning as login.php — no session
// exists yet. Safe by construction regardless of who calls it:
// resolveDemoFirmAdmin() can only ever resolve one of a handful of known,
// synthetic *.demo.horizonplan.in accounts holding no real client data, never
// an arbitrary or real account.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/DemoAccess.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$slug      = trim((string) ($input['firm'] ?? ''));
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($slug === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'firm is required.']);
    exit();
}

// Same IP-keyed rate limit as auth_google.php/signup.php — repeated session
// creation from one IP is the only real abuse case here (there's no
// credential to guess, and the target accounts hold only synthetic data).
if (!checkLoginRateLimit($db, '', $ipAddress)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Try again later.']);
    exit();
}

$demoUser = resolveDemoFirmAdmin($db, $slug);

if ($demoUser === null) {
    recordLoginAttempt($db, '', $ipAddress, false);
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'That demo firm is not available right now.']);
    exit();
}

recordLoginAttempt($db, '', $ipAddress, true);

issueSession($db, $demoUser['id'], $demoUser['tenant_id'], $demoUser['role']);

echo json_encode([
    'status' => 'success',
    'user'   => [
        'id'                => $demoUser['id'],
        'tenant_id'         => $demoUser['tenant_id'],
        'role'              => $demoUser['role'],
        // True by construction — resolveDemoFirmAdmin() only ever returns an
        // account with a real mfa_secret set.
        'mfa_enrolled'      => true,
        'mfa_totp_enrolled' => true,
        'google_linked'     => false,
        // True by construction — resolveDemoFirmAdmin() only ever resolves a
        // *.demo.horizonplan.in account. Drives the guided demo tour
        // (DemoTour.jsx); session.php recomputes the same flag from the
        // user's email on every subsequent session check, so this is just
        // the immediate value before that first refresh lands.
        'is_demo'           => true,
    ],
    'company_name' => $demoUser['company_name'],
]);
