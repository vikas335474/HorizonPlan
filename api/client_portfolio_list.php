<?php
declare(strict_types=1);

// Client portfolio ledger: read a client's holdings plus computed balance-sheet
// totals. GET, advisor OR client (verifyAccessAny) — a client sees only their
// own rows (client_id in the query string is ignored for a client session, same
// pattern as goals_list.php / risk_profile_read.php); an advisor must pass
// ?client_id=. Tenant-scoped.
//
// Attaches each NAV-tracked row's cached price/date/freshness (MfNavSync) and a
// single portfolio-wide "data as of" claim. Totals — liquid, locked, assets,
// liabilities, net worth — are computed on every read, never stored (same
// posture as corpus_multiple in goals_read.php).
//
// docs/12 Prompt D-2: every ASSET row also carries a `tax_context` block
// (api/lib/PortfolioTaxContext.php) — the sourced treatment note(s) that
// would apply if sold/withdrawn today, plus an illustrative unrealised
// gain when acquisition_value was recorded. Liabilities never carry one
// (out of scope — see docs/12 §1). The whole tax_reference table is
// preloaded ONCE into a map rather than queried per row, since it's small
// (~14 rows) and static within a request.
//
// Output: {status, items[], totals{}, portfolio_nav_freshness}. Errors:
// 400 (missing client_id), 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/MfNavSync.php';
require_once __DIR__ . '/lib/TaxReference.php';
require_once __DIR__ . '/lib/PortfolioTaxContext.php';

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

// One query for the whole cache, keyed for buildPortfolioTaxContext()'s map
// parameter — see PortfolioTaxContext.php's header for why this is a plain
// PHP map rather than a per-item DB call.
$taxReferenceByKey = [];
foreach (listTaxReference($db) as $row) {
    $formatted = formatTaxReferenceRow($row);
    $taxReferenceByKey[$formatted['category'] . '|' . $formatted['subcategory']] = $formatted;
}

$items = array_map(static function (array $r) use ($taxReferenceByKey): array {
    $item = [
        'id'                => (int) $r['id'],
        'item_kind'         => $r['item_kind'],
        'bucket'            => $r['bucket'],
        'category'          => $r['category'],
        'fund_type'         => $r['fund_type'],
        'description'       => $r['description'],
        'value'             => (float) $r['value'],
        'acquisition_value' => $r['acquisition_value'] !== null ? (float) $r['acquisition_value'] : null,
        'acquisition_date'  => $r['acquisition_date'],
        // docs/12 Prompt D-1: which rows a CAS reconcile is even allowed to
        // touch (source) and its stable match key across re-imports
        // (folio_number) — surfaced read-only here so the UI can badge a
        // holding as "from your CAS import" vs. hand-entered.
        'source'            => $r['source'],
        'folio_number'      => $r['folio_number'],
        // Liabilities only (docs/10 P1-4); NULL on assets and on any loan
        // whose rate has not been recorded.
        'interest_rate'     => $r['interest_rate'] !== null ? (float) $r['interest_rate'] : null,
        'amfi_scheme_code'  => $r['amfi_scheme_code'],
        'units_held'        => $r['units_held'] !== null ? (float) $r['units_held'] : null,
        'nav_value'         => $r['nav_value'],
        'nav_date'          => $r['nav_date'],
        'nav_fetched_at'    => $r['nav_fetched_at'],
        'updated_at'        => $r['updated_at'],
        // docs/12 Prompt D-2 — facts only, never a filing figure or a
        // "sell now" prompt (see PortfolioTaxContext.php's header). null
        // for a liability — tax treatment context is asset-only scope.
        'tax_context'       => null,
    ];
    if ($r['item_kind'] === 'asset') {
        $item['tax_context'] = buildPortfolioTaxContext([
            'category'          => $r['category'],
            'fund_type'         => $r['fund_type'],
            'value'             => (float) $r['value'],
            'acquisition_value' => $r['acquisition_value'] !== null ? (float) $r['acquisition_value'] : null,
            'acquisition_date'  => $r['acquisition_date'],
        ], $taxReferenceByKey);
    }
    return $item;
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
