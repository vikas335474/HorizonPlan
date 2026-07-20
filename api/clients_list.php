<?php
declare(strict_types=1);

// Advisor dashboard data: lists every client in the advisor's tenant, each with
// a count of their goals and total tracked net worth across those goals. This is
// what the advisor lands on — it replaces the old "type a client_id blind" flow.
//
// Tenant-scoped: an advisor only ever sees clients in their own tenant. A client
// role has no business here (they don't have a client list) — advisor and
// super_admin only.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db      = getPdo();
$session = verifyAccessAny($db, ['advisor', 'super_admin']);
$tenantId = (int) $session['tenant_id'];

// One query: every client in the tenant, LEFT JOINed to their goals so clients
// with zero goals still appear. Aggregate goal count and summed starting corpus
// per client. Tenant filter is explicit on both sides of the join to keep the
// hard-isolation guarantee (per the blueprint's Explicit Column Isolation rule).
$stmt = $db->prepare(
    "SELECT
        u.id            AS client_id,
        u.email         AS email,
        u.created_at    AS client_since,
        COUNT(bp.id)    AS goal_count,
        COALESCE(SUM(bp.initial_net_worth), 0) AS total_net_worth
     FROM users u
     LEFT JOIN base_plans bp
        ON bp.client_id = u.id AND bp.tenant_id = :tenant_id_join
     WHERE u.tenant_id = :tenant_id
       AND u.role = 'client'
     GROUP BY u.id, u.email, u.created_at
     ORDER BY u.created_at DESC"
);
$stmt->execute([':tenant_id' => $tenantId, ':tenant_id_join' => $tenantId]);
$rows = $stmt->fetchAll();

$clients = array_map(static function (array $r): array {
    return [
        'client_id'       => (int) $r['client_id'],
        'email'           => $r['email'],
        'client_since'    => $r['client_since'],
        'goal_count'      => (int) $r['goal_count'],
        'total_net_worth' => (float) $r['total_net_worth'],
    ];
}, $rows);

// Aggregate stats for the dashboard header cards.
$totalClients = count($clients);
$totalGoals   = array_sum(array_column($clients, 'goal_count'));
$totalAum     = array_sum(array_column($clients, 'total_net_worth'));

echo json_encode([
    'status' => 'success',
    'stats'  => [
        'total_clients' => $totalClients,
        'total_goals'   => $totalGoals,
        'total_aum'     => $totalAum, // sum of initial_net_worth across all goals
    ],
    'clients' => $clients,
]);
