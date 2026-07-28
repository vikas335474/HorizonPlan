<?php
declare(strict_types=1);

// P0-1 · Households — list the firm's households with member counts.
// Advisor-only: households are an advisor-facing planning grouping (clients get
// individual self-service only, per the P0-1 decision). Tenant-scoped.

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
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$households = $scopedDb->select('households', [], 'id, name, created_at');

// Member counts per household — a single tenant-scoped grouped read (the same
// documented "raw but always tenant-filtered" pattern clients_list.php uses
// where a GROUP BY the helper can't express is needed).
$countStmt = $db->prepare(
    "SELECT household_id, COUNT(*) AS n
     FROM users
     WHERE tenant_id = :tenant_id AND role = 'client' AND household_id IS NOT NULL
     GROUP BY household_id"
);
$countStmt->execute([':tenant_id' => $tenantId]);
$counts = [];
foreach ($countStmt->fetchAll() as $row) {
    $counts[(int) $row['household_id']] = (int) $row['n'];
}

$out = array_map(static function (array $h) use ($counts): array {
    return [
        'id'           => (int) $h['id'],
        'name'         => $h['name'],
        'member_count' => $counts[(int) $h['id']] ?? 0,
        'created_at'   => $h['created_at'],
    ];
}, $households);

echo json_encode(['status' => 'success', 'households' => $out]);
