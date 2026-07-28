<?php
declare(strict_types=1);

// Strategy templates: one combined, searchable library view for the template
// picker. GET, advisor, tenant-scoped. Merges global published templates
// (cross-tenant by design), this tenant's own templates, and its customizations
// into a single list, each tagged with `source` (global | own | customized) so
// the frontend can show provenance. Optional filters: ?q= (name/description),
// ?risk_profile=, ?market_code=. Output: {status, templates[]}. Errors:
// 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor']);
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$search = trim((string) ($_GET['q'] ?? ''));
$riskProfile = $_GET['risk_profile'] ?? null;
$marketCode = $_GET['market_code'] ?? null;

// Combine global (cross-tenant, published-only) with the caller's own
// templates and customizations into one searchable result set, each tagged
// with where it came from so the frontend can render provenance directly.
$entries = [];

foreach ($scopedDb->selectGlobalPublishedTemplates() as $row) {
    $entries[] = [
        'source'                 => 'global',
        'id'                     => (int) $row['id'],
        'template_name'          => $row['template_name'],
        'description'            => $row['description'],
        'market_code'            => $row['market_code'],
        'allocation_json'        => json_decode($row['allocation_json'], true),
        'return_assumption_pct'  => $row['return_assumption_pct'] !== null ? (float) $row['return_assumption_pct'] : null,
        'risk_profile_enum'      => $row['risk_profile_enum'],
        'approval_status'        => $row['approval_status'],
    ];
}

foreach ($scopedDb->select('template_strategies', ['is_system_template' => 0]) as $row) {
    $entries[] = [
        'source'                 => 'own',
        'id'                     => (int) $row['id'],
        'template_name'          => $row['template_name'],
        'description'            => $row['description'],
        'market_code'            => $row['market_code'],
        'allocation_json'        => json_decode($row['allocation_json'], true),
        'return_assumption_pct'  => $row['return_assumption_pct'] !== null ? (float) $row['return_assumption_pct'] : null,
        'risk_profile_enum'      => $row['risk_profile_enum'],
        'approval_status'        => $row['approval_status'],
    ];
}

foreach ($scopedDb->select('template_customizations') as $row) {
    $entries[] = [
        'source'                 => 'customized',
        'id'                     => (int) $row['id'],
        'base_template_id'        => (int) $row['base_template_id'],
        'template_name'          => $row['template_name'],
        'description'            => $row['description'],
        'market_code'            => null, // customizations inherit market_code from the base template
        'allocation_json'        => $row['allocation_json'] !== null ? json_decode($row['allocation_json'], true) : null,
        'return_assumption_pct'  => $row['return_assumption_pct'] !== null ? (float) $row['return_assumption_pct'] : null,
        'risk_profile_enum'      => null,
        'approval_status'        => $row['approval_status'],
    ];
}

if ($search !== '') {
    $entries = array_values(array_filter(
        $entries,
        static fn(array $e) => stripos($e['template_name'], $search) !== false
            || ($e['description'] !== null && stripos($e['description'], $search) !== false)
    ));
}

if ($riskProfile !== null && $riskProfile !== '') {
    $entries = array_values(array_filter(
        $entries,
        static fn(array $e) => $e['risk_profile_enum'] === $riskProfile
    ));
}

if ($marketCode !== null && $marketCode !== '') {
    $marketCode = strtoupper((string) $marketCode);
    $entries = array_values(array_filter(
        $entries,
        static fn(array $e) => $e['market_code'] === null || $e['market_code'] === $marketCode
    ));
}

echo json_encode(['status' => 'success', 'templates' => $entries]);
