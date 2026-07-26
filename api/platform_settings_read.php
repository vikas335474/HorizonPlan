<?php
declare(strict_types=1);

// docs/09 Piece 1 — super_admin-only read of the single-row platform_settings
// config. Powers the AdminConsole "Platform" tab.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();
verifyAccess($db, 'super_admin');

$stmt = $db->query("SELECT mfa_enforcement, demo_mode, signup_enabled, updated_at FROM platform_settings WHERE id = 1 LIMIT 1");
$row = $stmt->fetch();

if (!$row) {
    // Should never happen (migration 019 seeds id=1) — fail closed rather
    // than pretend a safe default, so a missing row is visibly a bug.
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'platform_settings row is missing.']);
    exit();
}

echo json_encode([
    'status'          => 'success',
    'mfa_enforcement' => $row['mfa_enforcement'],
    'demo_mode'       => $row['demo_mode'],
    'signup_enabled'  => $row['signup_enabled'],
    'updated_at'      => $row['updated_at'],
]);
