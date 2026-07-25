<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/TemplateValidation.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$customizationId = (int) ($input['id'] ?? $input['customization_id'] ?? 0);
if ($customizationId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id (customization_id) is required.']);
    exit();
}

// Tenant-scoped select — this is what enforces "own customization only":
// a customization belonging to another tenant simply isn't in this result
// set, so it 404s exactly like a nonexistent row would.
$rows = $scopedDb->select('template_customizations', ['id' => $customizationId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Customization not found.']);
    exit();
}
$existing = $rows[0];

$changes = []; // field => [old, new] (both already string-normalized for logging)

if (array_key_exists('template_name', $input)) {
    $newName = trim((string) $input['template_name']);
    if ($newName === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'template_name cannot be empty.']);
        exit();
    }
    if ($newName !== $existing['template_name']) {
        $changes['template_name'] = [$existing['template_name'], $newName];
    }
}

if (array_key_exists('allocation_json', $input)) {
    $allocationError = validateAllocationJson($input['allocation_json']);
    if ($allocationError !== null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $allocationError]);
        exit();
    }
    $newAllocation = json_encode($input['allocation_json']);
    if ($newAllocation !== $existing['allocation_json']) {
        $changes['allocation_json'] = [$existing['allocation_json'], $newAllocation];
    }
}

foreach (['description', 'return_assumption_pct', 'is_private', 'is_shareable'] as $field) {
    if (!array_key_exists($field, $input)) {
        continue;
    }
    $newValue = $input[$field];
    if (in_array($field, ['is_private', 'is_shareable'], true)) {
        $newValue = empty($newValue) ? 0 : 1;
    }
    $oldValue = $existing[$field];
    if ((string) $oldValue !== (string) $newValue) {
        $changes[$field] = [$oldValue, $newValue];
    }
}

if (empty($changes)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes.', 'customization_id' => $customizationId]);
    exit();
}

$updateData = array_combine(array_keys($changes), array_map(static fn($c) => $c[1], $changes));
$scopedDb->update('template_customizations', $updateData, ['id' => $customizationId]);

$scopedDb->insert('template_audit_log', [
    'template_id'         => null,
    'customization_id'    => $customizationId,
    'user_id'             => $userId,
    'action'               => 'customized',
    'entity_details_json' => json_encode(array_map(
        static fn($c) => ['old' => $c[0], 'new' => $c[1]],
        $changes
    )),
]);

echo json_encode([
    'status'           => 'success',
    'customization_id' => $customizationId,
    'changed_fields'   => array_keys($changes),
]);
