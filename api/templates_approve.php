<?php
declare(strict_types=1);

// Strategy templates (docs/07 Bet 1): approve a template or customization for
// real client use. POST, advisor session. Approval authority splits by kind: a
// GLOBAL (is_system_template) template needs a super_admin; a firm's own
// template or a customization needs firm role sr_advisor/firm_admin in the
// OWNING tenant (a super_admin does NOT get a blanket cross-firm bypass for a
// firm-owned template — approving one is tenant-scoped, 0 rows -> 403). Exactly
// one of template_id / customization_id. Writes the approval fields + an
// 'approved' template_audit_log row. Output: {status, template_id|customization_id}.
// Errors: 400 (input), 403 (authority / other firm), 404, 405.

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
$session = verifyAccess($db, 'advisor'); // super_admin also passes, per verifyAccess()
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$templateId = isset($input['template_id']) ? (int) $input['template_id'] : null;
$customizationId = isset($input['customization_id']) ? (int) $input['customization_id'] : null;

if (($templateId === null || $templateId <= 0) && ($customizationId === null || $customizationId <= 0)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'template_id or customization_id is required.']);
    exit();
}
if ($templateId && $customizationId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Provide only one of template_id or customization_id.']);
    exit();
}

// Approval authority (docs/07 Bet 1): a global SaaS template can only be
// approved by a super_admin — the HorizonPlan-side compliance review the
// vision doc describes. An advisor-created template or customization can
// only be approved by an advisor session in the SAME tenant that owns it.
// Phase 1 has no separate "firm principal" role distinct from advisor — the
// approving advisor and the template's creator may be the same person
// today. That is a real, documented limitation (docs/07 §3 Bet 1), not an
// oversight. It still adds a genuine control an advisor typing numbers
// straight into goals_update.php does not have: a deliberate, logged,
// second step before a template can touch a real client's plan.
if ($templateId !== null && $templateId > 0) {
    // Unscoped read (see TenantScopedDb::findTemplateStrategyById) — needed to
    // learn is_system_template before routing to the right approval path.
    $template = $scopedDb->findTemplateStrategyById($templateId);
    if ($template === null) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
        exit();
    }

    $isSystemTemplate = (int) $template['is_system_template'] === 1;

    if ($isSystemTemplate) {
        if ($session['role'] !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can approve a global template.']);
            exit();
        }
        $scopedDb->approveGlobalTemplate($templateId, $userId);
    } else {
        // docs/09 Piece 2: approving a firm's own (non-system) template
        // requires sr_advisor/firm_admin — a jr_advisor can still create and
        // fork templates, just not approve them for real client use.
        requireFirmRole($db, $session, ['sr_advisor', 'firm_admin']);

        // Tenant-scoped update — naturally 0 rows affected (not a 404 vs 403
        // distinction, same "acts like nonexistent" pattern templates_customize.php
        // already uses) if this template belongs to a different tenant, even
        // for a super_admin session: approving another firm's own template is
        // not the same thing as approving a HorizonPlan global template, so
        // super_admin does not get a blanket bypass here.
        $affected = $scopedDb->update('template_strategies', [
            'approval_status'     => 'approved',
            'approved_by_user_id' => $userId,
            'approved_at'         => date('Y-m-d H:i:s'),
        ], ['id' => $templateId]);
        if ($affected === 0) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Cannot approve a template belonging to another firm.']);
            exit();
        }
    }

    $scopedDb->insert('template_audit_log', [
        'template_id'         => $templateId,
        'customization_id'    => null,
        'user_id'             => $userId,
        'action'               => 'approved',
        'entity_details_json' => json_encode(['template_name' => $template['template_name']]),
    ]);

    echo json_encode(['status' => 'success', 'template_id' => $templateId]);
    exit();
}

// --- customization branch: always tenant-owned, never system-level ---
requireFirmRole($db, $session, ['sr_advisor', 'firm_admin']);

$rows = $scopedDb->select('template_customizations', ['id' => $customizationId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Customization not found.']);
    exit();
}
$existing = $rows[0];

$scopedDb->update('template_customizations', [
    'approval_status'     => 'approved',
    'approved_by_user_id' => $userId,
    'approved_at'         => date('Y-m-d H:i:s'),
], ['id' => $customizationId]);

$scopedDb->insert('template_audit_log', [
    'template_id'         => null,
    'customization_id'    => $customizationId,
    'user_id'             => $userId,
    'action'               => 'approved',
    'entity_details_json' => json_encode(['template_name' => $existing['template_name']]),
]);

echo json_encode(['status' => 'success', 'customization_id' => $customizationId]);
