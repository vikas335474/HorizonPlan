<?php
declare(strict_types=1);

// P1-1 · Goal-progress tracking — read a client's NET-WORTH history (the
// portfolio ledger's assets - liabilities, snapshotted over time). GET,
// advisor OR client; a client only ever reads their own (client_id in the
// query string is ignored for a client session, same pattern as
// client_portfolio_list.php / cash_flow_list.php). Tenant-scoped.
//
// This is deliberately a SEPARATE series from the per-goal one
// (goal_progress_read.php). docs/02 §4.1: the portfolio is not a shared pool
// any single goal draws from, so a client's net worth is reported as its own
// line and is never attributed to, or summed into, a goal's progress.
//
// The series is what was observed — no modelled points, no backfill. First/
// last are surfaced so the UI can state the change over the tracked period
// without recomputing it.
//
// Inputs: ?client_id= (advisors only; ignored for a client session).
// Output: {status, client_id, points[], first{}, latest{}, change{}}.
// Errors: 400 (advisor missing client_id), 404 (client not in tenant), 405.

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
$isClient = $session['role'] === 'client';

$clientId = $isClient ? (int) $session['user_id'] : (int) ($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

// An advisor's caller-supplied client_id needs the tenant check; a client's
// own session identity is already verified.
if (!$isClient && $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$rows = $scopedDb->select('client_net_worth_snapshots', ['client_id' => $clientId]);
usort($rows, static fn(array $a, array $b) => strcmp((string) $a['as_of_date'], (string) $b['as_of_date']));

$points = array_map(static fn(array $r) => [
    'as_of_date'        => $r['as_of_date'],
    'net_worth'         => (float) $r['net_worth'],
    'assets_total'      => (float) $r['assets_total'],
    'liabilities_total' => (float) $r['liabilities_total'],
    'automatic'         => $r['created_by_user_id'] === null,
], $rows);

$first = $points === [] ? null : $points[0];
$latest = $points === [] ? null : $points[count($points) - 1];

// Change across the tracked window. Null with fewer than two points — a
// single reading is a position, not a trend, and reporting "0% change" from
// one observation would imply a stability nobody measured.
$change = null;
if ($first !== null && $latest !== null && count($points) > 1) {
    $delta = $latest['net_worth'] - $first['net_worth'];
    $change = [
        'from_date'  => $first['as_of_date'],
        'to_date'    => $latest['as_of_date'],
        'amount'     => round($delta, 2),
        'percent'    => $first['net_worth'] != 0.0
            ? round(($delta / abs($first['net_worth'])) * 100.0, 1)
            : null,
    ];
}

echo json_encode([
    'status'    => 'success',
    'client_id' => $clientId,
    'points'    => $points,
    'first'     => $first,
    'latest'    => $latest,
    'change'    => $change,
]);
