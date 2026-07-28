<?php
declare(strict_types=1);

// Strategy templates: the advisor's full template management view, grouped by
// origin. POST (a read that takes no body — POST purely by this endpoint's
// convention), advisor, tenant-scoped. Returns three lists — global_templates
// (cross-tenant published system templates), my_templates (this tenant's own,
// non-system), and my_customizations (this tenant's forks) — each row carrying
// its approval_status and a usage_count (tenant-scoped for own rows, global
// count for shared system templates). Output: {status, global_templates[],
// my_templates[], my_customizations[]}. Errors: 405 (non-POST).

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
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

function formatTemplate(array $row, int $usageCount): array
{
    return [
        'id'                     => (int) $row['id'],
        'template_name'          => $row['template_name'],
        'description'            => $row['description'],
        'market_code'            => $row['market_code'],
        'allocation_json'        => json_decode($row['allocation_json'], true),
        'return_assumption_pct'  => $row['return_assumption_pct'] !== null ? (float) $row['return_assumption_pct'] : null,
        'risk_profile_enum'      => $row['risk_profile_enum'],
        'is_system_template'     => (bool) $row['is_system_template'],
        'is_published'           => (bool) $row['is_published'],
        // docs/07 Bet 1: whether this template has been signed off for use on
        // a real client's plan — see goals_apply_template.php's enforcement.
        'approval_status'        => $row['approval_status'],
        'approved_by_user_id'    => $row['approved_by_user_id'] !== null ? (int) $row['approved_by_user_id'] : null,
        'approved_at'            => $row['approved_at'],
        'creator_user_id'        => $row['creator_user_id'] !== null ? (int) $row['creator_user_id'] : null,
        'created_at'             => $row['created_at'],
        'published_at'           => $row['published_at'],
        'usage_count'            => $usageCount,
    ];
}

function formatCustomization(array $row, int $usageCount): array
{
    return [
        'id'                     => (int) $row['id'],
        'base_template_id'        => (int) $row['base_template_id'],
        'template_name'          => $row['template_name'],
        'description'            => $row['description'],
        'allocation_json'        => $row['allocation_json'] !== null ? json_decode($row['allocation_json'], true) : null,
        'return_assumption_pct'  => $row['return_assumption_pct'] !== null ? (float) $row['return_assumption_pct'] : null,
        'is_private'             => (bool) $row['is_private'],
        'is_shareable'           => (bool) $row['is_shareable'],
        'approval_status'        => $row['approval_status'],
        'approved_by_user_id'    => $row['approved_by_user_id'] !== null ? (int) $row['approved_by_user_id'] : null,
        'approved_at'            => $row['approved_at'],
        'created_by_user_id'     => (int) $row['created_by_user_id'],
        'created_at'             => $row['created_at'],
        'last_modified_at'       => $row['last_modified_at'],
        'usage_count'            => $usageCount,
    ];
}

// 1. Global library — cross-tenant by design, published system templates only.
$globalRows = $scopedDb->selectGlobalPublishedTemplates();
$globalTemplates = array_map(
    static fn(array $row) => formatTemplate($row, $scopedDb->countGlobalTemplateUsage((int) $row['id'])),
    $globalRows
);

// 2. My created templates — tenant-scoped, advisor-authored (not system).
$myRows = $scopedDb->select('template_strategies', ['is_system_template' => 0]);
$myTemplates = array_map(
    static fn(array $row) => formatTemplate($row, $scopedDb->countTemplateUsage((int) $row['id'], null)),
    $myRows
);

// 3. My customizations — tenant-scoped forks.
$customizationRows = $scopedDb->select('template_customizations');
$myCustomizations = array_map(
    static fn(array $row) => formatCustomization($row, $scopedDb->countTemplateUsage(null, (int) $row['id'])),
    $customizationRows
);

echo json_encode([
    'status'             => 'success',
    'global_templates'   => $globalTemplates,
    'my_templates'       => $myTemplates,
    'my_customizations'  => $myCustomizations,
]);
