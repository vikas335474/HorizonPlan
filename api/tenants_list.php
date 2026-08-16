<?php
declare(strict_types=1);

// Super Admin console: list every advisory firm (tenant) with its mode,
// branding, and a head-count of advisors/clients. Cross-tenant read, gated to
// super_admin — operates on the tenants registry, not a tenant-scoped table.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
verifyAccess($db, 'super_admin');

$rows = $db->query(
    "SELECT t.id, t.company_name, t.advisory_mode, t.white_label_settings, t.created_at,
            p.code AS plan_code, p.name AS plan_name, p.max_advisors,
            SUM(u.role = 'advisor') AS advisor_count,
            SUM(u.role = 'client')  AS client_count
       FROM tenants t
       LEFT JOIN users u ON u.tenant_id = t.id
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
     GROUP BY t.id
     ORDER BY t.created_at DESC, t.id DESC"
)->fetchAll();

$tenants = array_map(static function (array $t): array {
    $whiteLabel = null;
    if (!empty($t['white_label_settings'])) {
        $decoded = json_decode((string) $t['white_label_settings'], true);
        if (is_array($decoded)) {
            $whiteLabel = $decoded;
        }
    }
    return [
        'id'            => (int) $t['id'],
        'company_name'  => $t['company_name'],
        'advisory_mode' => $t['advisory_mode'],
        'white_label'   => $whiteLabel,
        'advisor_count' => (int) $t['advisor_count'],
        'client_count'  => (int) $t['client_count'],
        'created_at'    => $t['created_at'],
        // Billing (docs/09 Session 8, sql/044) — null for a personal-kind
        // tenant, which never has a plan_id.
        'plan'          => $t['plan_code'] !== null ? [
            'code'         => $t['plan_code'],
            'name'         => $t['plan_name'],
            'max_advisors' => $t['max_advisors'] !== null ? (int) $t['max_advisors'] : null,
        ] : null,
    ];
}, $rows);

echo json_encode(['status' => 'success', 'tenants' => $tenants]);
