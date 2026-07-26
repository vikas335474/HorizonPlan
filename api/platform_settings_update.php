<?php
declare(strict_types=1);

// docs/09 Piece 1 — super_admin toggles platform-wide MFA enforcement and/or
// demo mode. Both are independent knobs (see platform_settings table comment
// in sql/019): demo_mode='on' implies MFA is skipped regardless of
// mfa_enforcement, but mfa_enforcement can also be disabled on its own for
// testing without the rest of demo-mode's behavior (email suppression, the
// reset button).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccess($db, 'super_admin');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$set = [];
$params = [];
$changes = [];

if (array_key_exists('mfa_enforcement', $input)) {
    $val = (string) $input['mfa_enforcement'];
    if (!in_array($val, ['enabled', 'disabled'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "mfa_enforcement must be 'enabled' or 'disabled'."]);
        exit();
    }
    $set[] = 'mfa_enforcement = :mfa_enforcement';
    $params[':mfa_enforcement'] = $val;
    $changes['mfa_enforcement'] = $val;
}

if (array_key_exists('demo_mode', $input)) {
    $val = (string) $input['demo_mode'];
    if (!in_array($val, ['off', 'on'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "demo_mode must be 'off' or 'on'."]);
        exit();
    }
    $set[] = 'demo_mode = :demo_mode';
    $params[':demo_mode'] = $val;
    $changes['demo_mode'] = $val;
}

if ($set === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nothing to update.']);
    exit();
}

$stmt = $db->prepare("UPDATE platform_settings SET " . implode(', ', $set) . " WHERE id = 1");
$stmt->execute($params);

// platform_settings has no tenant_id of its own (it's platform-wide, not a
// tenant-scoped table) — the audit row is attributed to the acting
// super_admin's own tenant context, same as how every other action they take
// is scoped, since change_log itself requires a tenant_id.
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);
$scopedDb->logChange('platform_settings', 1, 'platform_settings_updated', null,
    json_encode($changes), (int) $session['user_id']);

$fresh = $db->query("SELECT mfa_enforcement, demo_mode FROM platform_settings WHERE id = 1 LIMIT 1")->fetch();

echo json_encode([
    'status'          => 'success',
    'mfa_enforcement' => $fresh['mfa_enforcement'],
    'demo_mode'       => $fresh['demo_mode'],
]);
