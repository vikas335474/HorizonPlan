<?php
declare(strict_types=1);

// Strategy templates: create a strategy template. POST, advisor OR super_admin
// (verifyAccess 'advisor' lets super_admin through; this endpoint's own logic is
// what gates is_system_template). Tenant-scoped. is_system_template (a global
// SaaS template) can only be set by a super_admin — every other role is silently
// forced to 0 since the form is shared. Validates allocation_json and optional
// risk_profile_enum (TemplateValidation). Writes a 'created' template_audit_log
// row. Output: {status, template_id}. Errors: 400 (validation), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/TemplateValidation.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
// advisor OR super_admin — verifyAccess('advisor') already lets super_admin
// through, and this endpoint's own logic is what actually gates is_system_template.
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$templateName = trim((string) ($input['template_name'] ?? ''));
$allocationJson = $input['allocation_json'] ?? null;

if ($templateName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'template_name is required.']);
    exit();
}

$allocationError = validateAllocationJson($allocationJson);
if ($allocationError !== null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $allocationError]);
    exit();
}

$riskProfile = $input['risk_profile_enum'] ?? null;
if ($riskProfile !== null) {
    $riskProfile = (string) $riskProfile;
    $riskError = validateRiskProfile($riskProfile);
    if ($riskError !== null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $riskError]);
        exit();
    }
}

// Section: "Super Admin: create global templates (is_system_template=1)" —
// only a super_admin session can set this flag; every other role is silently
// forced to 0 rather than erroring, since the same form/endpoint is shared.
$isSystemTemplate = ($session['role'] === 'super_admin') && !empty($input['is_system_template']);
$isPublished = !empty($input['is_published']);

$data = [
    'creator_user_id'       => $userId,
    'creator_org_id'         => $isSystemTemplate ? null : $tenantId,
    'template_name'          => $templateName,
    'description'            => $input['description'] ?? null,
    'market_code'            => strtoupper((string) ($input['market_code'] ?? 'IN')),
    'allocation_json'        => json_encode($allocationJson),
    'return_assumption_pct'  => $input['return_assumption_pct'] ?? null,
    'risk_profile_enum'      => $riskProfile,
    'is_system_template'     => $isSystemTemplate ? 1 : 0,
    'is_published'           => $isPublished ? 1 : 0,
    'published_at'           => $isPublished ? date('Y-m-d H:i:s') : null,
];

$templateId = $scopedDb->insert('template_strategies', $data);

$scopedDb->insert('template_audit_log', [
    'template_id'         => $templateId,
    'customization_id'    => null,
    'user_id'             => $userId,
    'action'               => 'created',
    'entity_details_json' => json_encode([
        'template_name'      => $templateName,
        'is_system_template' => $isSystemTemplate,
    ]),
]);

echo json_encode(['status' => 'success', 'template_id' => $templateId]);
