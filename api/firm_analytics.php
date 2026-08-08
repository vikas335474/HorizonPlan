<?php
declare(strict_types=1);

// Firm practice analytics — the firm_admin / sr_advisor view of the PRACTICE
// rather than of one client. GET, advisor session, and additionally requires
// firm role sr_advisor or firm_admin (super_admin passes, same convention as
// templates_approve.php / risk_question_set_approve.php): a jr_advisor sees
// their own book on the dashboard, not the whole firm's performance.
// Tenant-scoped throughout — every figure is this firm's own.
//
// Until now the only firm-level numbers anywhere were the three header tiles
// on the advisor dashboard (clients / goals / corpus). There was no way for a
// principal to see how work is distributed, where the plan-review queue is
// stuck, or how the book's health breaks down — the "analytics dashboard"
// left open in CLAUDE.md's next-build backlog.
//
// Everything here is DERIVED from data the app already stores. Nothing is
// forecast, scored, or benchmarked against an invented standard — an
// "advisor productivity" number that implied a target the firm never set
// would be exactly the kind of opinion CLAUDE.md says to leave to the firm.
// These are counts and sums the firm can interpret itself.
//
// Output: {status, totals{}, per_advisor[], readiness_distribution{},
//          review_pipeline{}, risk_distribution{}, coverage{}}
// Errors: 403 (insufficient firm role), 405 (non-GET).

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

$db = getPdo();
$session = verifyAccess($db, 'advisor');
requireFirmRole($db, $session, ['sr_advisor', 'firm_admin']);

$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

// --- the firm's people ------------------------------------------------------
$advisorRows = $scopedDb->select('users', ['role' => 'advisor'], 'id, email, firm_role');
$clientRows  = $scopedDb->select('users', ['role' => 'client'], 'id, email, assigned_advisor_id');

// --- goals, and the readiness of each ---------------------------------------
// One pass over the tenant's goals: per-client corpus + goal counts, the
// worst readiness score per client, and the review pipeline. Same
// readiness-null condition as goals_list.php — a goal without both rates
// can't be projected, so it contributes no score rather than a zero.
$goals = $scopedDb->select('base_plans');

$goalCountByClient = [];
$corpusByClient    = [];
$minReadinessByClient = [];
$reviewPipeline = ['pending_review' => 0, 'changes_requested' => 0, 'approved' => 0, 'not_required' => 0];
$goalTypeCounts = [];

foreach ($goals as $goal) {
    $clientId = (int) $goal['client_id'];
    $goalCountByClient[$clientId] = ($goalCountByClient[$clientId] ?? 0) + 1;
    $corpusByClient[$clientId] = ($corpusByClient[$clientId] ?? 0.0) + (float) $goal['initial_net_worth'];

    $type = (string) $goal['goal_type'];
    $goalTypeCounts[$type] = ($goalTypeCounts[$type] ?? 0) + 1;

    $status = (string) ($goal['review_status'] ?? 'not_required');
    if (array_key_exists($status, $reviewPipeline)) {
        $reviewPipeline[$status]++;
    }

    if ($goal['goal_type'] !== 'retirement') {
        continue;
    }
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
    if (!isset($minReadinessByClient[$clientId]) || $score < $minReadinessByClient[$clientId]) {
        $minReadinessByClient[$clientId] = $score;
    }
}

// --- risk profiling coverage ------------------------------------------------
// Latest band per client, same "most recent wins" rule clients_list.php uses.
$latestBandByClient = [];
$latestAtByClient   = [];
foreach ($scopedDb->select('risk_profiles') as $profile) {
    $clientId = (int) $profile['client_id'];
    if (!isset($latestAtByClient[$clientId]) || $profile['created_at'] > $latestAtByClient[$clientId]) {
        $latestAtByClient[$clientId] = $profile['created_at'];
        $latestBandByClient[$clientId] = $profile['band'];
    }
}

$riskDistribution = [];
foreach ($latestBandByClient as $band) {
    $riskDistribution[$band] = ($riskDistribution[$band] ?? 0) + 1;
}

// --- per-advisor breakdown --------------------------------------------------
// The point of the assignment field: who is carrying how much of the book.
// Deliberately counts and sums only — no ranking, no target, no score.
$byAdvisor = [];
foreach ($advisorRows as $a) {
    $byAdvisor[(int) $a['id']] = [
        'user_id'        => (int) $a['id'],
        'email'          => $a['email'],
        'firm_role'      => $a['firm_role'] ?? 'sr_advisor', // NULL reads as sr_advisor, same as requireFirmRole()
        'client_count'   => 0,
        'goal_count'     => 0,
        'total_corpus'   => 0.0,
        'attention_count' => 0,
    ];
}
// Clients with no assignment are reported as their own bucket rather than
// silently folded into a random advisor — "nobody owns these" is exactly the
// thing a principal needs to see.
$unassigned = ['client_count' => 0, 'goal_count' => 0, 'total_corpus' => 0.0, 'attention_count' => 0];

$readinessDistribution = ['strong' => 0, 'fair' => 0, 'at_risk' => 0, 'unscored' => 0];
$clientsWithNoGoals = 0;
$clientsNeverProfiled = 0;

foreach ($clientRows as $c) {
    $clientId = (int) $c['id'];
    $goalCount = $goalCountByClient[$clientId] ?? 0;
    $corpus    = $corpusByClient[$clientId] ?? 0.0;
    $score     = $minReadinessByClient[$clientId] ?? null;
    $band      = $latestBandByClient[$clientId] ?? null;

    if ($goalCount === 0) { $clientsWithNoGoals++; }
    if ($band === null)   { $clientsNeverProfiled++; }

    if ($score === null)   { $readinessDistribution['unscored']++; }
    elseif ($score >= 70)  { $readinessDistribution['strong']++; }
    elseif ($score >= 40)  { $readinessDistribution['fair']++; }
    else                   { $readinessDistribution['at_risk']++; }

    // Same "needs attention" rule as clients_list.php / the dashboard UI.
    $needsAttention = $goalCount === 0 || $band === null || ($score !== null && $score < 40);

    $advisorId = $c['assigned_advisor_id'] !== null ? (int) $c['assigned_advisor_id'] : null;
    if ($advisorId !== null && isset($byAdvisor[$advisorId])) {
        $byAdvisor[$advisorId]['client_count']++;
        $byAdvisor[$advisorId]['goal_count'] += $goalCount;
        $byAdvisor[$advisorId]['total_corpus'] += $corpus;
        if ($needsAttention) { $byAdvisor[$advisorId]['attention_count']++; }
    } else {
        $unassigned['client_count']++;
        $unassigned['goal_count'] += $goalCount;
        $unassigned['total_corpus'] += $corpus;
        if ($needsAttention) { $unassigned['attention_count']++; }
    }
}

$perAdvisor = array_values($byAdvisor);
usort($perAdvisor, static fn(array $a, array $b) => $b['total_corpus'] <=> $a['total_corpus']);
foreach ($perAdvisor as &$row) {
    $row['total_corpus'] = round($row['total_corpus'], 2);
}
unset($row);
$unassigned['total_corpus'] = round($unassigned['total_corpus'], 2);

$totalClients = count($clientRows);

echo json_encode([
    'status' => 'success',
    'totals' => [
        'advisors'     => count($advisorRows),
        'clients'      => $totalClients,
        'goals'        => count($goals),
        'total_corpus' => round(array_sum($corpusByClient), 2),
        'households'   => count($scopedDb->select('households', [], 'id')),
    ],
    'per_advisor'            => $perAdvisor,
    'unassigned'             => $unassigned,
    'readiness_distribution' => $readinessDistribution,
    'risk_distribution'      => $riskDistribution,
    'goal_type_counts'       => $goalTypeCounts,
    'review_pipeline'        => $reviewPipeline,
    // Coverage gaps a principal can act on directly — expressed as raw counts
    // out of the firm's client total, never as a grade.
    'coverage' => [
        'clients_with_no_goals'  => $clientsWithNoGoals,
        'clients_never_profiled' => $clientsNeverProfiled,
        'clients_unassigned'     => $unassigned['client_count'],
        'total_clients'          => $totalClients,
    ],
]);
