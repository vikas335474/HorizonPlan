<?php
declare(strict_types=1);

// Super Admin console: full profile for one firm — the actual advisor list
// (not just a head-count, per tenants_list.php), plus a goal count so the
// "how live is this firm" question doesn't require a separate query. Backs
// the Firms tab's detail drawer. Cross-tenant read, gated to super_admin —
// operates on the tenants registry plus a tenant-filtered read of users/
// base_plans, same pattern as tenants_list.php.

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

$tenantId = (int) ($_GET['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'tenant_id is required.']);
    exit();
}

$tenantStmt = $db->prepare(
    'SELECT t.id, t.company_name, t.advisory_mode, t.white_label_settings, t.requires_plan_review, t.created_at,
            t.kind, p.code AS plan_code, p.name AS plan_name, p.max_advisors, p.price_inr_monthly
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
      WHERE t.id = :id
      LIMIT 1'
);
$tenantStmt->execute([':id' => $tenantId]);
$tenant = $tenantStmt->fetch();

if (!$tenant) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Firm not found.']);
    exit();
}

$whiteLabel = null;
if (!empty($tenant['white_label_settings'])) {
    $decoded = json_decode((string) $tenant['white_label_settings'], true);
    if (is_array($decoded)) {
        $whiteLabel = $decoded;
    }
}

$advisorsStmt = $db->prepare(
    "SELECT id, email, firm_role, created_at FROM users WHERE tenant_id = :tenant_id AND role = 'advisor' ORDER BY created_at ASC"
);
$advisorsStmt->execute([':tenant_id' => $tenantId]);
$advisors = array_map(static fn(array $a): array => [
    'id'         => (int) $a['id'],
    'email'      => $a['email'],
    'firm_role'  => $a['firm_role'],
    'created_at' => $a['created_at'],
], $advisorsStmt->fetchAll());

$clientCountStmt = $db->prepare(
    "SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND role = 'client'"
);
$clientCountStmt->execute([':tenant_id' => $tenantId]);
$clientCount = (int) $clientCountStmt->fetchColumn();

$goalCountStmt = $db->prepare('SELECT COUNT(*) FROM base_plans WHERE tenant_id = :tenant_id');
$goalCountStmt->execute([':tenant_id' => $tenantId]);
$goalCount = (int) $goalCountStmt->fetchColumn();

echo json_encode([
    'status' => 'success',
    'tenant' => [
        'id'            => (int) $tenant['id'],
        'company_name'  => $tenant['company_name'],
        'advisory_mode' => $tenant['advisory_mode'],
        'white_label'   => $whiteLabel,
        'requires_plan_review' => $tenant['requires_plan_review'] === 'on',
        'created_at'    => $tenant['created_at'],
        'advisor_count' => count($advisors),
        'client_count'  => $clientCount,
        'goal_count'    => $goalCount,
        // Billing (docs/09 Session 8, sql/044) — null for a personal-kind
        // tenant, which never has a plan_id (sql/044's decision record).
        'plan'          => $tenant['plan_code'] !== null ? [
            'code'              => $tenant['plan_code'],
            'name'              => $tenant['plan_name'],
            'max_advisors'      => $tenant['max_advisors'] !== null ? (int) $tenant['max_advisors'] : null,
            'price_inr_monthly' => $tenant['price_inr_monthly'] !== null ? (int) $tenant['price_inr_monthly'] : null,
        ] : null,
    ],
    'advisors' => $advisors,
]);
