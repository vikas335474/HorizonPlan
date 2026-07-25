<?php
declare(strict_types=1);

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
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$baseTemplateId = (int) ($input['base_template_id'] ?? 0);
if ($baseTemplateId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'base_template_id is required.']);
    exit();
}

// Deliberately unscoped read (see TenantScopedDb::findTemplateStrategyById) —
// the source template may legitimately live in a different tenant (a global
// SaaS template, or another advisor's published one). Eligibility is checked
// explicitly below, not by the DB query.
$baseTemplate = $scopedDb->findTemplateStrategyById($baseTemplateId);
if ($baseTemplate === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
    exit();
}

// Security rule (Phase 1): advisors cannot fork private templates belonging
// to other advisors. Own-tenant templates (even unpublished drafts) and any
// published template (system or advisor-shared) are fork-eligible.
$isOwnTenant = (int) $baseTemplate['tenant_id'] === $tenantId;
$isPublished = (int) $baseTemplate['is_published'] === 1;
if (!$isOwnTenant && !$isPublished) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Cannot fork a private template that is not yours.']);
    exit();
}

$templateName = trim((string) ($input['template_name'] ?? ''));
if ($templateName === '') {
    $templateName = $baseTemplate['template_name'] . ' (customized)';
}

// Overrides are optional — a bare fork with no overrides inherits the base
// template's allocation/return assumption at read time (application-layer
// fallback, not stored here as a copy, so the fork stays linked to the base
// rather than diverging from it silently).
$allocationOverride = null;
if (array_key_exists('allocation_json', $input) && $input['allocation_json'] !== null) {
    $allocationError = validateAllocationJson($input['allocation_json']);
    if ($allocationError !== null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $allocationError]);
        exit();
    }
    $allocationOverride = json_encode($input['allocation_json']);
}

$returnAssumptionOverride = $input['return_assumption_pct'] ?? null;

$isPrivate = array_key_exists('is_private', $input) ? (empty($input['is_private']) ? 0 : 1) : 1;
$isShareable = !empty($input['is_shareable']) ? 1 : 0;

$data = [
    'base_template_id'    => $baseTemplateId,
    'template_name'        => $templateName,
    'description'          => $input['description'] ?? null,
    'allocation_json'      => $allocationOverride,
    'return_assumption_pct' => $returnAssumptionOverride,
    'is_private'           => $isPrivate,
    'is_shareable'         => $isShareable,
    'created_by_user_id'   => $userId,
];

$customizationId = $scopedDb->insert('template_customizations', $data);

$scopedDb->insert('template_audit_log', [
    'template_id'         => $baseTemplateId,
    'customization_id'    => $customizationId,
    'user_id'             => $userId,
    'action'               => 'forked_from',
    'entity_details_json' => json_encode([
        'base_template_id'   => $baseTemplateId,
        'base_template_name' => $baseTemplate['template_name'],
        'base_tenant_id'     => (int) $baseTemplate['tenant_id'],
    ]),
]);

echo json_encode(['status' => 'success', 'customization_id' => $customizationId]);
