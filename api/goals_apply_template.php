<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PlanReview.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // goal-level assumption edits are an advisor action, same as goals_update.php
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$goalId = (int) ($input['goal_id'] ?? 0);
$templateId = isset($input['template_id']) ? (int) $input['template_id'] : null;
$customizationId = isset($input['customization_id']) ? (int) $input['customization_id'] : null;

if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'goal_id is required.']);
    exit();
}
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

$goalRows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($goalRows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $goalRows[0];

// drawdown_return_rate only has meaning for retirement-type goals (docs/02
// §4.3) — same gate goals_projection.php enforces.
if ($goal['goal_type'] !== 'retirement') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Templates apply to retirement-type goals only.']);
    exit();
}

// Resolve the effective return_assumption_pct and enforce the approval gate
// (docs/07 Bet 1): an unapproved template/customization is illustration-only
// and cannot touch a real client's plan, regardless of the caller's role.
// This is the data-level guarantee described in docs/06 guardrail 2.
if ($templateId !== null && $templateId > 0) {
    // Deliberately unscoped read (see TenantScopedDb::findTemplateStrategyById)
    // — the template may legitimately live in a different tenant (global or
    // another advisor's published template). Eligibility checked explicitly
    // below, same rule templates_fork.php already enforces.
    $template = $scopedDb->findTemplateStrategyById($templateId);
    if ($template === null) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
        exit();
    }

    $isOwnTenant = (int) $template['tenant_id'] === $tenantId;
    $isPublished = (int) $template['is_published'] === 1;
    if (!$isOwnTenant && !$isPublished) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cannot apply a private template that is not yours.']);
        exit();
    }
    if ($template['approval_status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This template has not been approved yet and cannot be applied to a client plan.']);
        exit();
    }
    if ($template['return_assumption_pct'] === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'This template has no return assumption set.']);
        exit();
    }

    $returnAssumption = (float) $template['return_assumption_pct'];
    $sourceName = $template['template_name'];
    $applyTemplateId = $templateId;
    $applyCustomizationId = null;
} else {
    $rows = $scopedDb->select('template_customizations', ['id' => $customizationId]);
    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Customization not found.']);
        exit();
    }
    $customization = $rows[0];

    if ($customization['approval_status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This customization has not been approved yet and cannot be applied to a client plan.']);
        exit();
    }

    // Inherit from the base template if the customization didn't override the
    // return assumption — same fallback rule templates_fork.php documents.
    $returnAssumption = $customization['return_assumption_pct'] !== null
        ? (float) $customization['return_assumption_pct']
        : null;
    if ($returnAssumption === null) {
        $baseTemplate = $scopedDb->findTemplateStrategyById((int) $customization['base_template_id']);
        if ($baseTemplate !== null && $baseTemplate['return_assumption_pct'] !== null) {
            $returnAssumption = (float) $baseTemplate['return_assumption_pct'];
        }
    }
    if ($returnAssumption === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'This customization (and its base template) has no return assumption set.']);
        exit();
    }

    $sourceName = $customization['template_name'];
    $applyTemplateId = null;
    $applyCustomizationId = $customizationId;
}

$oldRate = $goal['drawdown_return_rate'];
$newRate = $returnAssumption;

$scopedDb->update('base_plans', [
    'drawdown_return_rate'     => $newRate,
    'applied_template_id'      => $applyTemplateId,
    'applied_customization_id' => $applyCustomizationId,
], ['id' => $goalId]);

$scopedDb->logChange(
    'base_plan',
    $goalId,
    'drawdown_return_rate',
    $oldRate !== null ? (string) $oldRate : null,
    (string) $newRate,
    $userId
);

// Jr -> Sr Advisor Plan-Approval Workflow (decision #1): applying a template
// is explicitly one of the "advice" actions that can trigger review, same as
// hand-editing drawdown_return_rate in goals_update.php.
applyPlanReviewTransitionOnAdviceEdit($scopedDb, $db, $tenantId, $goalId, $goal, $userId);

// Global Inheritance Engine cascade — identical rule to goals_update.php:
// only non-overridden children inherit, so a protected what-if scenario
// never gets silently touched by a template application.
$childRows = $scopedDb->select('sub_scenarios', ['base_plan_id' => $goalId, 'is_overridden' => 0]);
if (!empty($childRows)) {
    $scopedDb->update(
        'sub_scenarios',
        ['custom_drawdown_return_rate' => $newRate],
        ['base_plan_id' => $goalId, 'is_overridden' => 0]
    );

    foreach ($childRows as $child) {
        $scopedDb->logChange(
            'sub_scenario',
            (int) $child['id'],
            'custom_drawdown_return_rate',
            $child['custom_drawdown_return_rate'] !== null ? (string) $child['custom_drawdown_return_rate'] : null,
            (string) $newRate,
            $userId
        );
    }
}

// The loop-closer: this is the 'used_in_plan' action templates_list.php's
// usage_count has been counting since Phase 1, but nothing wrote it until now.
$scopedDb->insert('template_audit_log', [
    'template_id'         => $applyTemplateId,
    'customization_id'    => $applyCustomizationId,
    'user_id'             => $userId,
    'action'               => 'used_in_plan',
    'entity_details_json' => json_encode([
        'goal_id'                      => $goalId,
        'source_name'                  => $sourceName,
        'applied_drawdown_return_rate' => $newRate,
    ]),
]);

echo json_encode([
    'status'               => 'success',
    'goal_id'              => $goalId,
    'drawdown_return_rate' => $newRate,
    'cascaded_scenarios'   => count($childRows),
]);
