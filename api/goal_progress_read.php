<?php
declare(strict_types=1);

// P1-1 · Goal-progress tracking — read one goal's snapshot history plus its
// current drift from plan. GET, advisor OR client (verifyAccessAny); a client
// may only read their OWN goal (403 otherwise), same rule as goals_read.php.
// Tenant-scoped.
//
// Returns the recorded series (never a modelled one — a date with no snapshot
// simply isn't in the list, because nobody observed it) and, from the most
// recent point, the drift status the UI badges:
//
//   * a projectable goal carries expected_value on each row, so the latest
//     point yields ahead / on_track / behind (PlanMath::progressStatus, ±5%
//     dead zone);
//   * a target-based goal has no expected curve at all and carries
//     funding_covered_pct instead — its progress is the coverage trend, and
//     `status` is null rather than a fabricated verdict.
//
// Inputs: ?goal_id= (required). Output: {status, goal_id, tracking_mode,
// points[], latest{}}. Errors: 400 (missing goal_id), 403 (not your goal),
// 404 (goal not in tenant), 405 (non-GET).

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
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

$goalId = (int) ($_GET['goal_id'] ?? 0);
if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'goal_id is required.']);
    exit();
}

$goalRows = $scopedDb->select('base_plans', ['id' => $goalId]);
if ($goalRows === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$goal = $goalRows[0];

if ($session['role'] === 'client' && (int) $goal['client_id'] !== (int) $session['user_id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not your goal.']);
    exit();
}

$rows = $scopedDb->select('goal_snapshots', ['goal_id' => $goalId]);
usort($rows, static fn(array $a, array $b) => strcmp((string) $a['as_of_date'], (string) $b['as_of_date']));

$points = array_map(static function (array $r): array {
    return [
        'as_of_date'          => $r['as_of_date'],
        'corpus_value'        => (float) $r['corpus_value'],
        'expected_value'      => $r['expected_value'] !== null ? (float) $r['expected_value'] : null,
        'funding_covered_pct' => $r['funding_covered_pct'] !== null ? (float) $r['funding_covered_pct'] : null,
        // sql/042 — the score as the plan stood on this date. Null is "not
        // scored" (target-based goal, zero corpus, or a row predating the
        // migration), never zero.
        'readiness_score'     => $r['readiness_score'] !== null ? (int) $r['readiness_score'] : null,
        // Lets the UI distinguish a reading someone took before a meeting
        // from one the scheduled job recorded unattended.
        'automatic'           => $r['created_by_user_id'] === null,
    ];
}, $rows);

// Which kind of progress this goal can report at all. Decided from the goal
// itself, not from whatever happens to be in the snapshots, so an empty
// history still tells the UI what it WILL show once a point exists.
$isProjectable = $goal['withdrawal_rate'] !== null && $goal['drawdown_return_rate'] !== null;
$isTargetBased = $goal['target_amount'] !== null && $goal['target_date'] !== null;
$trackingMode = $isProjectable ? 'drift' : ($isTargetBased ? 'coverage' : 'none');

$latest = null;
if ($points !== []) {
    $last = $points[count($points) - 1];
    $latest = [
        'as_of_date'          => $last['as_of_date'],
        'corpus_value'        => $last['corpus_value'],
        'expected_value'      => $last['expected_value'],
        'funding_covered_pct' => $last['funding_covered_pct'],
        // Null whenever there's nothing to compare against — the UI renders
        // "not tracked" rather than a false "on track".
        'progress'            => PlanMath::progressStatus($last['corpus_value'], $last['expected_value']),
    ];
}

// How the ANSWER has moved across the tracked window — not how the corpus has.
// The two are different questions and only this one is what a person opens the
// app to ask ("am I in better shape than when I last looked?").
//
// Computed over SCORED points only. Rows can carry a NULL readiness_score for
// several honest reasons (a target-based goal, a zero corpus, or simply
// predating sql/042), and treating any of those as a zero would manufacture a
// catastrophic drop out of a row that never had a score at all.
//
// Null below two scored points, exactly as client_progress_read.php refuses to
// report a net-worth change from a single reading: one observation is a
// position, not a movement, and "0 change" implies a stability nobody measured.
//
// first -> latest, deliberately NOT last-month-vs-this-month. Framing this as a
// monthly delta would teach a person with a 20-year horizon to evaluate on a
// monthly cadence, which is the documented behaviour (myopic loss aversion)
// that makes long-horizon investors worse off. The UI names the start date so
// the window is explicit rather than implied.
$readinessChange = null;
$scored = array_values(array_filter($points, static fn(array $p): bool => $p['readiness_score'] !== null));
if (count($scored) > 1) {
    $firstScored = $scored[0];
    $lastScored  = $scored[count($scored) - 1];
    $readinessChange = [
        'from_date'  => $firstScored['as_of_date'],
        'to_date'    => $lastScored['as_of_date'],
        'from_score' => $firstScored['readiness_score'],
        'to_score'   => $lastScored['readiness_score'],
        'delta'      => $lastScored['readiness_score'] - $firstScored['readiness_score'],
    ];
}

echo json_encode([
    'status'           => 'success',
    'goal_id'          => $goalId,
    'tracking_mode'    => $trackingMode,
    'points'           => $points,
    'latest'           => $latest,
    'readiness_change' => $readinessChange,
]);
