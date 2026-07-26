<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/MfNavSync.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// A client can only ever see their own portfolio — client_id in the query
// string is ignored for a client session, same pattern used across every
// other client_id-scoped read (goals_list.php, risk_profile_read.php).
$clientId = $session['role'] === 'client'
    ? (int) $session['user_id']
    : (int) ($_GET['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

$rows = $scopedDb->select('client_portfolio_items', ['client_id' => $clientId]);

// docs "session 2" MF NAV price-sync: attach each NAV-tracked row's cached
// price/date/freshness, plus the single oldest fetched_at across all of them
// as the card's own "data as of ..." claim — always present per the user's
// own instruction, not just shown when everything happens to be fresh.
$navAttached = attachNavFreshness($db, $rows);
$rowsWithNav = $navAttached['rows'];

$items = array_map(static function (array $r): array {
    return [
        'id'               => (int) $r['id'],
        'item_kind'        => $r['item_kind'],
        'bucket'           => $r['bucket'],
        'category'         => $r['category'],
        'description'      => $r['description'],
        'value'            => (float) $r['value'],
        'amfi_scheme_code' => $r['amfi_scheme_code'],
        'units_held'       => $r['units_held'] !== null ? (float) $r['units_held'] : null,
        'nav_value'        => $r['nav_value'],
        'nav_date'         => $r['nav_date'],
        'nav_fetched_at'   => $r['nav_fetched_at'],
        'updated_at'       => $r['updated_at'],
    ];
}, $rowsWithNav);

// docs/05 item 3: liquid vs locked totals, plus net worth (assets - liabilities)
// — computed here, never stored, same "computed on every read" posture as
// corpus_multiple in goals_read.php.
$liquidTotal = 0.0;
$lockedTotal = 0.0;
$liabilitiesTotal = 0.0;
foreach ($rows as $r) {
    $value = (float) $r['value'];
    if ($r['item_kind'] === 'liability') {
        $liabilitiesTotal += $value;
    } elseif ($r['bucket'] === 'locked') {
        $lockedTotal += $value;
    } else {
        $liquidTotal += $value;
    }
}

echo json_encode([
    'status' => 'success',
    'items'  => $items,
    'totals' => [
        'liquid_total'      => round($liquidTotal, 2),
        'locked_total'      => round($lockedTotal, 2),
        'assets_total'      => round($liquidTotal + $lockedTotal, 2),
        'liabilities_total' => round($liabilitiesTotal, 2),
        'net_worth'         => round($liquidTotal + $lockedTotal - $liabilitiesTotal, 2),
    ],
    'portfolio_nav_freshness' => $navAttached['portfolio_nav_freshness'],
]);
