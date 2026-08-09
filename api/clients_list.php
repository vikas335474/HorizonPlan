<?php
declare(strict_types=1);

// Advisor dashboard data: the clients in the advisor's tenant, each with a goal
// count, total tracked net worth, at-a-glance health (readiness + risk band),
// and the advisor they're assigned to. This is what the advisor lands on — it
// replaces the old "type a client_id blind" flow.
//
// Tenant-scoped: an advisor only ever sees clients in their own tenant. A client
// role has no business here (they don't have a client list) — advisor and
// super_admin only.
//
// Filtering / paging (added with the persona-dashboard work). The whole book
// used to be returned in one unbounded array and filtered/sorted in the
// browser, which is fine at 40 clients and a real problem at a few thousand
// (docs/10 §4 pain point 2: tools that stop fitting "past a few hundred
// clients"). Sorting and filtering therefore moved SERVER-side — paginating
// alone would have silently broken client-side sort, since page 1 of an
// unsorted set is not the top of a sorted one.
//
//   ?page=       1-based page number (default 1)
//   ?per_page=   default 25, clamped to 100 — same convention change_log_list.php set
//   ?q=          case-insensitive email substring
//   ?scope=      all (default) | mine | unassigned — see below
//   ?attention_only=1  only clients that need attention
//   ?sort=       recent (default) | attention | readiness | corpus
//
// `scope=mine` is a FILTER, never a permission boundary: assigned_advisor_id
// (migration 031) is attribution only, and every advisor in the firm can still
// read every client in the tenant exactly as before. Tenant isolation remains
// the one security boundary.
//
// `stats` and `attention_count` are always computed across the WHOLE tenant
// book, never just the returned page — a firm-level number that changed when
// you turned a page would be a lie.

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
// The join to the assigned advisor is a second LEFT JOIN on users, also
// tenant-pinned: an assignment can only ever point at an advisor in the same
// firm (client_assign_advisor.php enforces that on write), and pinning it here
// too means even a row somehow assigned cross-tenant renders as unassigned
// rather than leaking another firm's advisor email.
$stmt = $db->prepare(
    "SELECT
        u.id            AS client_id,
        u.email         AS email,
        u.created_at    AS client_since,
        u.assigned_advisor_id AS assigned_advisor_id,
        adv.email       AS assigned_advisor_email,
        COUNT(bp.id)    AS goal_count,
        COALESCE(SUM(bp.initial_net_worth), 0) AS total_net_worth
     FROM users u
     LEFT JOIN base_plans bp
        ON bp.client_id = u.id AND bp.tenant_id = :tenant_id_join
     LEFT JOIN users adv
        ON adv.id = u.assigned_advisor_id AND adv.tenant_id = :tenant_id_adv
     WHERE u.tenant_id = :tenant_id
       AND u.role = 'client'
     GROUP BY u.id, u.email, u.created_at, u.assigned_advisor_id, adv.email
     ORDER BY u.created_at DESC"
);
$stmt->execute([
    ':tenant_id'      => $tenantId,
    ':tenant_id_join' => $tenantId,
    ':tenant_id_adv'  => $tenantId,
]);
$rows = $stmt->fetchAll();

$clients = array_map(static function (array $r): array {
    return [
        'client_id'              => (int) $r['client_id'],
        'email'                  => $r['email'],
        'client_since'           => $r['client_since'],
        'assigned_advisor_id'    => $r['assigned_advisor_id'] !== null ? (int) $r['assigned_advisor_id'] : null,
        'assigned_advisor_email' => $r['assigned_advisor_email'],
        'goal_count'             => (int) $r['goal_count'],
        'total_net_worth'        => (float) $r['total_net_worth'],
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

// P1-1 · Worst drift-from-plan per client, from the MOST RECENT snapshot of
// each of their goals. The minimum, not an average — same reasoning as
// min_readiness_score above: one badly-drifting goal shouldn't be diluted by
// healthy ones into looking fine at a glance.
//
// Only the latest row per goal counts; older snapshots are history, not
// current status. Goals with no expected_value (target-based ones) contribute
// nothing here — they track coverage, not drift, and have no "behind" to report.
$latestSnapshotByGoal = [];
foreach ($scopedDb->select('goal_snapshots') as $snap) {
    $goalId = (int) $snap['goal_id'];
    $existing = $latestSnapshotByGoal[$goalId] ?? null;
    if ($existing === null || $snap['as_of_date'] > $existing['as_of_date']) {
        $latestSnapshotByGoal[$goalId] = $snap;
    }
}
$worstDriftByClient = [];
foreach ($latestSnapshotByGoal as $snap) {
    if ($snap['expected_value'] === null) {
        continue;
    }
    $progress = PlanMath::progressStatus((float) $snap['corpus_value'], (float) $snap['expected_value']);
    if ($progress === null) {
        continue;
    }
    $clientId = (int) $snap['client_id'];
    $existing = $worstDriftByClient[$clientId] ?? null;
    if ($existing === null || $progress['drift_pct'] < $existing['drift_pct']) {
        $worstDriftByClient[$clientId] = $progress;
    }
}

$clients = array_map(static function (array $c) use ($minReadinessByClient, $latestRiskProfileByClient, $worstDriftByClient): array {
    $c['min_readiness_score'] = $minReadinessByClient[$c['client_id']] ?? null;
    $c['risk_band'] = $latestRiskProfileByClient[$c['client_id']]['band'] ?? null;
    // Null when nothing has been snapshotted for this client yet — the UI
    // shows no badge at all rather than implying an untracked client is fine.
    $drift = $worstDriftByClient[$c['client_id']] ?? null;
    $c['progress_status'] = $drift['status'] ?? null;
    $c['progress_drift_pct'] = $drift['drift_pct'] ?? null;
    return $c;
}, $clients);

// docs/12 Prompt D-3 — a firm-wide "goals met" count, the positive-signal
// counterpart to attention_count below. Scoped to TARGET-BASED goals
// (education/home/other) only, deliberately NOT retirement goals: a
// target-based goal's "met" state is one cheap computation directly off
// base_plans fields (PlanMath::targetGoalFunding, same call the goal's own
// card/detail page makes), so this count can never drift from what a click-
// through shows. A retirement goal's "met" state (PlanMath::retirementTarget)
// needs the client's cash-flow expenses plus a full accumulation/lifecycle
// projection — reproducing that per-goal, for every retirement goal in the
// whole tenant, on every dashboard load would either duplicate real
// complexity or risk a second, drifting approximation of what the goal page
// itself computes. Same asymmetry this file already accepts for
// min_readiness_score (retirement-only, above) — different goal shapes get
// different aggregate signals rather than one unified figure force-fit
// across both.
$goalsMetCount = 0;
foreach ($scopedDb->select('base_plans') as $goal) {
    $funding = PlanMath::targetGoalFunding(
        $goal['target_amount'] !== null ? (float) $goal['target_amount'] : null,
        $goal['target_date'],
        (float) $goal['initial_net_worth'],
        (float) $goal['inflation_rate']
    );
    if ($funding !== null && $funding['is_met']) {
        $goalsMetCount++;
    }
}

// Aggregate stats for the dashboard header cards — computed over the WHOLE
// tenant book, before any scope/search/attention filter or paging is applied,
// so "clients: 240" always means the firm has 240 clients regardless of which
// page or filter the advisor is looking at.
$totalClients = count($clients);
$totalGoals   = array_sum(array_column($clients, 'goal_count'));
$totalAum     = array_sum(array_column($clients, 'total_net_worth'));

// "Needs attention" — the same definition the dashboard UI used to apply
// client-side, moved here so it can drive both the firm-wide count and the
// server-side filter/sort from ONE rule rather than two that could drift:
// no goals yet, never risk-profiled, or a worst-goal readiness below 40 (the
// "Needs attention" band in ReadinessScore.jsx).
$needsAttention = static function (array $c): bool {
    return $c['goal_count'] === 0
        || $c['risk_band'] === null
        || ($c['min_readiness_score'] !== null && $c['min_readiness_score'] < 40);
};
$attentionCount = count(array_filter($clients, $needsAttention));

// Readiness distribution across the firm's scored clients — powers the
// dashboard's health bar. Buckets match ReadinessScore.jsx's own bands so a
// client counted "at risk" here is the one badged "Needs attention" there.
$distribution = ['strong' => 0, 'fair' => 0, 'at_risk' => 0, 'unscored' => 0];
foreach ($clients as $c) {
    $s = $c['min_readiness_score'];
    if ($s === null)      { $distribution['unscored']++; }
    elseif ($s >= 70)     { $distribution['strong']++; }
    elseif ($s >= 40)     { $distribution['fair']++; }
    else                  { $distribution['at_risk']++; }
}

// --- scope / search / attention filters ------------------------------------
$scope = (string) ($_GET['scope'] ?? 'all');
if (!in_array($scope, ['all', 'mine', 'unassigned'], true)) {
    $scope = 'all';
}
$viewerId = (int) $session['user_id'];
if ($scope === 'mine') {
    $clients = array_values(array_filter($clients, static fn(array $c) => $c['assigned_advisor_id'] === $viewerId));
} elseif ($scope === 'unassigned') {
    $clients = array_values(array_filter($clients, static fn(array $c) => $c['assigned_advisor_id'] === null));
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $clients = array_values(array_filter(
        $clients,
        static fn(array $c) => stripos($c['email'], $q) !== false
    ));
}

if (!empty($_GET['attention_only'])) {
    $clients = array_values(array_filter($clients, $needsAttention));
}

// --- sort ------------------------------------------------------------------
// Default 'recent' is the SQL ORDER BY above (created_at DESC) — left alone
// rather than re-sorted, so the untouched path stays byte-identical.
$sort = (string) ($_GET['sort'] ?? 'recent');
if ($sort === 'attention') {
    // Stable partition — needs-attention first, original order within groups.
    usort($clients, static fn(array $a, array $b) => (int) $needsAttention($b) <=> (int) $needsAttention($a));
} elseif ($sort === 'readiness') {
    // Unscored clients sort last: "no score" isn't the same signal as
    // "scored and struggling," and shouldn't crowd it out.
    usort($clients, static function (array $a, array $b) {
        if ($a['min_readiness_score'] === null) return 1;
        if ($b['min_readiness_score'] === null) return -1;
        return $a['min_readiness_score'] <=> $b['min_readiness_score'];
    });
} elseif ($sort === 'corpus') {
    usort($clients, static fn(array $a, array $b) => $b['total_net_worth'] <=> $a['total_net_worth']);
}

// --- paginate --------------------------------------------------------------
$filteredTotal = count($clients);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
if ($perPage < 1) {
    $perPage = 25;
}
$perPage = min($perPage, 100);
$pageItems = array_slice($clients, ($page - 1) * $perPage, $perPage);

// The firm's advisors — needed by the dashboard for the assign-to dropdown and
// the "mine / unassigned" scope control, and cheap enough (a handful of rows
// per firm) to include here rather than cost a second round trip. Tenant-scoped
// via the helper. Never includes password/MFA columns — just what the picker needs.
$advisors = array_map(
    static fn(array $a) => [
        'user_id'   => (int) $a['id'],
        'email'     => $a['email'],
        'firm_role' => $a['firm_role'],
    ],
    $scopedDb->select('users', ['role' => 'advisor'], 'id, email, firm_role')
);

echo json_encode([
    'status' => 'success',
    'stats'  => [
        'total_clients'   => $totalClients,
        'total_goals'     => $totalGoals,
        'total_aum'       => $totalAum, // sum of initial_net_worth across all goals
        'attention_count' => $attentionCount,
        // docs/12 Prompt D-3 — target-based goals only; see the comment
        // above $goalsMetCount for why retirement goals aren't folded in.
        'goals_met_count' => $goalsMetCount,
        'distribution'    => $distribution,
    ],
    'advisors' => $advisors,
    'clients'  => $pageItems,
    'page'     => $page,
    'per_page' => $perPage,
    'total'    => $filteredTotal, // count AFTER filters, for the pager
]);
