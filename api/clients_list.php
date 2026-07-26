<?php
declare(strict_types=1);

// Advisor dashboard data: lists every client in the advisor's tenant, each with
// a count of their goals and total tracked net worth across those goals. This is
// what the advisor lands on — it replaces the old "type a client_id blind" flow.
//
// Tenant-scoped: an advisor only ever sees clients in their own tenant. A client
// role has no business here (they don't have a client list) — advisor and
// super_admin only.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PlanMath.php';

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

// At-a-glance client health (docs/08 gap #5 — advisor dashboard / client list
// was flagged weak for lack of exactly this): a risk-profile band and the
// lowest readiness score across the client's retirement goals, the same way
// the goal detail page and ClientGoals.jsx already surface per-goal
// readiness (docs/07 Bet 3). Both go through TenantScopedDb — this file
// required it already but never actually instantiated it, relying only on
// the raw aggregate join above (a pre-existing gap against CLAUDE.md's
// tenant-isolation rule, not introduced here; not fixed here either, since
// TenantScopedDb::select() can't express that JOIN/GROUP BY and rewriting it
// is outside this session's scope — but nothing added below repeats it).
$scopedDb = new TenantScopedDb($db, $tenantId);

// Lowest readiness score per client, across retirement goals that actually
// have enough set to project (same condition goals_list.php/goals_projection.php
// use) — the minimum, not an average, so one at-risk goal can't be diluted
// by other healthy goals into looking fine at a glance.
$minReadinessByClient = [];
foreach ($scopedDb->select('base_plans', ['goal_type' => 'retirement']) as $goal) {
    $withdrawalRate = $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null;
    $drawdownReturnRate = $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null;
    if ($withdrawalRate === null || $drawdownReturnRate === null) {
        continue;
    }

    $score = PlanMath::readinessScoreForGoal(
        (float) $goal['initial_net_worth'],
        $withdrawalRate,
        (float) $goal['inflation_rate'],
        $drawdownReturnRate,
        (int) $goal['projection_horizon_years'],
        $goal['liquid_corpus_amount'] !== null ? (float) $goal['liquid_corpus_amount'] : null,
        $goal['locked_corpus_amount'] !== null ? (float) $goal['locked_corpus_amount'] : null,
        $goal['locked_return_rate'] !== null ? (float) $goal['locked_return_rate'] : null
    );
    if ($score === null) {
        continue;
    }

    $clientId = (int) $goal['client_id'];
    if (!isset($minReadinessByClient[$clientId]) || $score < $minReadinessByClient[$clientId]) {
        $minReadinessByClient[$clientId] = $score;
    }
}

// Latest risk-profile band per client. Deliberately just the band (not the
// suggested_return_assumption_pct) — that field is approval-gated per
// docs/06 guardrail 2 and risk_profile_read.php's own read-time check; the
// dashboard only needs "has this client been risk-profiled, and roughly
// where," not the return-assumption suggestion itself.
$latestRiskProfileByClient = []; // client_id => ['band' => ..., 'created_at' => ...]
foreach ($scopedDb->select('risk_profiles') as $profile) {
    $clientId = (int) $profile['client_id'];
    $existing = $latestRiskProfileByClient[$clientId] ?? null;
    if ($existing === null || $profile['created_at'] > $existing['created_at']) {
        $latestRiskProfileByClient[$clientId] = ['band' => $profile['band'], 'created_at' => $profile['created_at']];
    }
}

$clients = array_map(static function (array $c) use ($minReadinessByClient, $latestRiskProfileByClient): array {
    $c['min_readiness_score'] = $minReadinessByClient[$c['client_id']] ?? null;
    $c['risk_band'] = $latestRiskProfileByClient[$c['client_id']]['band'] ?? null;
    return $c;
}, $clients);

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
