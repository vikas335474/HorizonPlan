<?php
declare(strict_types=1);

// Super Admin updates a firm's compliance mode and/or white-label branding.
// advisory_mode is a compliance control (docs/02 3.6) — this endpoint is
// super_admin-gated, so the mode can never be flipped from an advisor/client
// session regardless of what any frontend sends.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
verifyAccess($db, 'super_admin');

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$tenantId = (int) ($input['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant_id is required.']);
    exit();
}

$exists = $db->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
$exists->execute([':id' => $tenantId]);
if (!$exists->fetch()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Tenant not found.']);
    exit();
}

$set = [];
$params = [':id' => $tenantId];

if (array_key_exists('advisory_mode', $input)) {
    $mode = (string) $input['advisory_mode'];
    if (!in_array($mode, ['distribution', 'advisory'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "advisory_mode must be 'distribution' or 'advisory'."]);
        exit();
    }
    $set[] = 'advisory_mode = :mode';
    $params[':mode'] = $mode;
}

if (array_key_exists('white_label', $input)) {
    $wl = $input['white_label'];
    if ($wl === null) {
        $set[] = 'white_label_settings = NULL';
    } elseif (is_array($wl)) {
        // Keep only the known branding keys; validate the colour so a bad value
        // can't land in a CSS variable on the client later.
        $clean = [];
        foreach (['company_name', 'logo_url'] as $k) {
            if (isset($wl[$k]) && is_string($wl[$k]) && trim($wl[$k]) !== '') {
                $clean[$k] = trim($wl[$k]);
            }
        }
        if (isset($wl['primary_color']) && is_string($wl['primary_color'])
            && preg_match('/^#[0-9a-fA-F]{6}$/', trim($wl['primary_color']))) {
            $clean['primary_color'] = strtolower(trim($wl['primary_color']));
        }
        $set[] = 'white_label_settings = :wl';
        $params[':wl'] = $clean === [] ? null : json_encode($clean);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'white_label must be an object or null.']);
        exit();
    }
}

if ($set === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nothing to update.']);
    exit();
}

$stmt = $db->prepare("UPDATE tenants SET " . implode(', ', $set) . " WHERE id = :id");
$stmt->execute($params);

echo json_encode(['status' => 'success', 'tenant_id' => $tenantId]);
