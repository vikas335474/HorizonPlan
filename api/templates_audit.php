<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor']);
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$templateId = isset($input['template_id']) ? (int) $input['template_id'] : null;
$customizationId = isset($input['customization_id']) ? (int) $input['customization_id'] : null;

if (($templateId === null || $templateId <= 0) && ($customizationId === null || $customizationId <= 0)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'template_id or customization_id is required.']);
    exit();
}

// Tenant-scoped select: this only ever returns audit rows this tenant itself
// generated (its own creates/forks/customizations/usage) — not other
// tenants' provenance events on the same shared global template. That's the
// correct isolation boundary: "show me my own history with this template",
// not a global usage feed across every advisor.
$conditions = [];
if ($templateId !== null && $templateId > 0) {
    $conditions['template_id'] = $templateId;
}
if ($customizationId !== null && $customizationId > 0) {
    $conditions['customization_id'] = $customizationId;
}

$rows = $scopedDb->select('template_audit_log', $conditions);

usort($rows, static fn(array $a, array $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

$result = array_map(static function (array $row): array {
    return [
        'id'                  => (int) $row['id'],
        'template_id'         => $row['template_id'] !== null ? (int) $row['template_id'] : null,
        'customization_id'    => $row['customization_id'] !== null ? (int) $row['customization_id'] : null,
        'user_id'             => (int) $row['user_id'],
        'action'               => $row['action'],
        'entity_details'      => $row['entity_details_json'] !== null ? json_decode($row['entity_details_json'], true) : null,
        'created_at'          => $row['created_at'],
    ];
}, $rows);

echo json_encode(['status' => 'success', 'audit_log' => $result]);
