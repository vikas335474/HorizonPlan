<?php
declare(strict_types=1);

// Risk profiler (docs/06 §B): read a client's latest captured risk profile plus
// history. GET, advisor OR client (a client reads only their own — query-string
// client_id ignored). Tenant-scoped.
//
// The band's suggested return assumption is surfaced ONLY when the backing
// question set is CURRENTLY approved — re-checked here at read time, not trusted
// from submission time (guardrail 2), so a since-reverted approval hides it and
// a since-granted one reveals it. Output: {status, latest{...}|null, history[]}.
// Errors: 400 (advisor missing client_id), 405 (non-GET).

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
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// A client can only ever read their own risk profile — client_id in the query
// string is ignored for a client session, same pattern as goals_list.php.
$clientId = $session['role'] === 'client'
    ? (int) $session['user_id']
    : (int) ($_GET['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

$rows = $scopedDb->select('risk_profiles', ['client_id' => $clientId]);
usort($rows, static fn(array $a, array $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

if (empty($rows)) {
    echo json_encode(['status' => 'success', 'latest' => null, 'history' => []]);
    exit();
}

$latest = $rows[0];

// Re-check the question set's CURRENT approval status, not whatever it was
// at submission time (docs/06 guardrail 2) — an edit since then may have
// reverted approval, or an approval since then may now allow the
// suggestion to surface for the first time.
$questionSetRows = $scopedDb->select('risk_question_sets', ['id' => (int) $latest['question_set_id']]);
$suggestedReturnPct = null;
$questionSetApproved = false;
if (!empty($questionSetRows)) {
    $questionSet = $questionSetRows[0];
    $questionSetApproved = $questionSet['status'] === 'approved';
    if ($questionSetApproved) {
        $rubric = json_decode($questionSet['scoring_rubric_json'], true);
        foreach ($rubric['bands'] as $band) {
            if ($band['name'] === $latest['band']) {
                $suggestedReturnPct = (float) $band['suggested_return_pct'];
                break;
            }
        }
    }
}

echo json_encode([
    'status'  => 'success',
    'latest'  => [
        'id'                               => (int) $latest['id'],
        'score'                             => (int) $latest['score'],
        'band'                              => $latest['band'],
        'created_at'                        => $latest['created_at'],
        'suggested_return_assumption_pct'  => $suggestedReturnPct,
        'question_set_approved'            => $questionSetApproved,
    ],
    'history' => array_map(static fn(array $r) => [
        'id'         => (int) $r['id'],
        'score'       => (int) $r['score'],
        'band'        => $r['band'],
        'created_at' => $r['created_at'],
    ], array_slice($rows, 1)),
]);
