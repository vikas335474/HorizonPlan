<?php
declare(strict_types=1);

/**
 * Daily MF NAV price-sync tests. Two parts, same split as api/lib/MfNavSync.php
 * itself:
 *   - parseAmfiNavFile() is pure (no DB/network) — tested against a synthetic
 *     fixture string shaped like a real AMFI NAVAll.txt export (AMC header
 *     lines, blank separators, an "N.A." row, an unrequested scheme code),
 *     never a live fetch (this sandbox's outbound access to amfiindia.com is
 *     blocked at the proxy policy level — confirmed via
 *     `curl $HTTPS_PROXY/__agentproxy/status`, a 403 policy denial, same
 *     class of block the historical-market-data session hit against
 *     Wikipedia — so fetchAmfiNavRawText() itself is code-reviewed only, not
 *     exercised here).
 *   - syncMfNavCache()'s DB-touching half (applyParsedNavToCache) and
 *     refreshPortfolioValuesFromCache() are tested against a real database
 *     inside a rolled-back transaction, same pattern as test_plan_review_db.php.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/MfNavSync.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// ─── Part 1: parseAmfiNavFile() — pure, no DB ──────────────────────────────

$fixture = <<<TXT
Open Ended Schemes(Debt Scheme - Overnight Fund)


ABC Mutual Fund

Scheme Code;ISIN Div Payout/ISIN Growth;ISIN Div Reinvestment;Scheme Name;Net Asset Value;Date
119551;INF200K01UP2;-;ABC Overnight Fund - Growth;1234.5678;25-Jul-2026
100034;-;-;ABC Liquid Fund - Regular Growth;N.A.;25-Jul-2026
999999;-;-;Some Fund Nobody Holds;500.00;25-Jul-2026


Open Ended Schemes(Equity Scheme - Flexi Cap Fund)

XYZ Mutual Fund

Scheme Code;ISIN Div Payout/ISIN Growth;ISIN Div Reinvestment;Scheme Name;Net Asset Value;Date
145678;INF300K01AB1;-;XYZ Flexi Cap Fund - Direct Growth;87.4321;25-Jul-2026
TXT;

$held = ['119551', '145678', '100034']; // deliberately NOT including 999999
$parsed = parseAmfiNavFile($fixture, $held);

assertTrue(count($parsed) === 2, 'only held+matched schemes are parsed (999999 excluded even though it appears in the file)');
assertTrue(!isset($parsed['999999']), 'a scheme code not in the held list is never returned, even though its row is well-formed');
assertTrue(isset($parsed['119551']) && $parsed['119551']['nav'] === 1234.5678, 'scheme 119551 NAV parsed correctly');
assertTrue($parsed['119551']['date'] === '2026-07-25', 'AMFI\'s d-M-Y date format is converted to Y-m-d');
assertTrue($parsed['119551']['name'] === 'ABC Overnight Fund - Growth', 'scheme name parsed correctly');
assertTrue(!isset($parsed['100034']), 'a held scheme with "N.A." as its NAV that day is skipped, not inserted as zero/garbage');
assertTrue(isset($parsed['145678']) && $parsed['145678']['nav'] === 87.4321, 'a second held scheme in a different AMC section is still found');

$emptyParsed = parseAmfiNavFile($fixture, []);
assertTrue($emptyParsed === [], 'an empty held-schemes filter returns nothing, however large the source file is');

// ─── Part 2: DB-touching functions, real database, rolled back ────────────

$db = getPdo();
$db->beginTransaction();

$db->exec("DELETE FROM client_protection"); // migration 034 also FKs to users
$db->exec("DELETE FROM client_dependants"); // migration 036 also FKs to users/base_plans
$db->exec("DELETE FROM client_context"); // migration 036 also FKs to users
$db->exec("DELETE FROM cash_flow_items"); // migration 030 also FKs to users
$db->exec("DELETE FROM client_portfolio_items");
$db->exec("DELETE FROM mf_nav_cache");
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM households"); // migration 029 FKs to tenants
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Advisor Firm A'), (2, 'Advisor Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'advisor_a@example.com', 'hash', 'advisor'),
    (2, 1, 'client_a@example.com', 'hash', 'client'),
    (3, 2, 'advisor_b@example.com', 'hash', 'advisor'),
    (4, 2, 'client_b@example.com', 'hash', 'client')");

$dbAdvisorA = new TenantScopedDb($db, 1);
$dbAdvisorB = new TenantScopedDb($db, 2);

// A NAV-tracked holding for client_a (tenant 1) and one for client_b (tenant 2)
// on the SAME scheme code — proving the cache/propagation is global (one
// cron pass updates every tenant's matching rows), while everything else
// about the row (which tenant, which client) stays properly scoped.
$itemA = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid', 'category' => 'mutual_fund',
    'description' => 'Overnight fund', 'value' => 0.0,
    'amfi_scheme_code' => '119551', 'units_held' => 100.0,
    'created_by_user_id' => 1,
]);
$itemB = $dbAdvisorB->insert('client_portfolio_items', [
    'client_id' => 4, 'item_kind' => 'asset', 'bucket' => 'liquid', 'category' => 'mutual_fund',
    'description' => 'Overnight fund (firm B client)', 'value' => 0.0,
    'amfi_scheme_code' => '119551', 'units_held' => 50.0,
    'created_by_user_id' => 3,
]);
// A manually-entered item with no NAV tracking — must be completely untouched.
$manualItem = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid', 'category' => 'fd',
    'description' => 'Fixed deposit', 'value' => 250000.0,
    'amfi_scheme_code' => null, 'units_held' => null,
    'created_by_user_id' => 1,
]);

assertTrue(distinctHeldSchemeCodes($db) === ['119551'], 'distinctHeldSchemeCodes() returns exactly the one scheme code actually held, across both tenants, no duplicates');

// --- applyParsedNavToCache(): upserts the cache and propagates to every matching row, any tenant ---
$applied = applyParsedNavToCache($db, ['119551' => ['name' => 'ABC Overnight Fund - Growth', 'nav' => 1234.5678, 'date' => '2026-07-25']]);
assertTrue($applied['cached'] === 1 && $applied['portfolio_rows_updated'] === 2, 'one scheme cached, both tenants\' matching rows updated in the same pass');

$cacheRow = $db->query("SELECT * FROM mf_nav_cache WHERE amfi_scheme_code = '119551'")->fetch();
assertTrue($cacheRow !== false && (float) $cacheRow['nav_value'] === 1234.5678, 'mf_nav_cache holds the upserted NAV');

$rowA = $dbAdvisorA->select('client_portfolio_items', ['id' => $itemA])[0];
$rowB = $dbAdvisorB->select('client_portfolio_items', ['id' => $itemB])[0];
assertTrue((float) $rowA['value'] === round(100.0 * 1234.5678, 2), 'client A\'s holding value = units × NAV after the sync pass ("automatic refresh")');
assertTrue((float) $rowB['value'] === round(50.0 * 1234.5678, 2), 'client B\'s holding (different tenant, same scheme) is updated in the same pass too');

$manualRow = $dbAdvisorA->select('client_portfolio_items', ['id' => $manualItem])[0];
assertTrue((float) $manualRow['value'] === 250000.0, 'a manually-entered, non-NAV-tracked item is completely untouched by the sync');

// --- a second cache upsert (simulating the next day's cron) updates in place, not a new row ---
applyParsedNavToCache($db, ['119551' => ['name' => 'ABC Overnight Fund - Growth', 'nav' => 1300.0, 'date' => '2026-07-26']]);
$cacheCount = (int) $db->query("SELECT COUNT(*) FROM mf_nav_cache WHERE amfi_scheme_code = '119551'")->fetchColumn();
assertTrue($cacheCount === 1, 'mf_nav_cache upserts in place (a cache, not an append-only history) — still exactly one row for this scheme');
$rowAAfterSecondSync = $dbAdvisorA->select('client_portfolio_items', ['id' => $itemA])[0];
assertTrue((float) $rowAAfterSecondSync['value'] === round(100.0 * 1300.0, 2), 'the next sync run propagates the new NAV correctly');

// --- refreshPortfolioValuesFromCache(): the manual "Refresh" button, cache-only, no network ---
// Manually desync the stored value (simulating staleness) without touching the cache, then confirm
// the manual refresh recomputes it from whatever is already cached — not a live fetch.
$dbAdvisorA->update('client_portfolio_items', ['value' => 1.0], ['id' => $itemA]);
$refreshResult = refreshPortfolioValuesFromCache($dbAdvisorA, $db, 2);
assertTrue($refreshResult['updated_count'] === 1, 'manual refresh recomputes exactly the one stale NAV-tracked row for this client');
assertTrue($refreshResult['nav_last_updated'] !== null, 'manual refresh reports the freshness timestamp it used');
$rowAAfterRefresh = $dbAdvisorA->select('client_portfolio_items', ['id' => $itemA])[0];
assertTrue((float) $rowAAfterRefresh['value'] === round(100.0 * 1300.0, 2), 'manual refresh recomputed the value from the CURRENT cache, matching the last cron-synced NAV');

// A brand-new scheme code with no cache entry yet ("price pending") is left untouched, not zeroed.
$pendingItem = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid', 'category' => 'mutual_fund',
    'description' => 'Brand new fund', 'value' => 0.0,
    'amfi_scheme_code' => '555555', 'units_held' => 10.0,
    'created_by_user_id' => 1,
]);
$refreshResult2 = refreshPortfolioValuesFromCache($dbAdvisorA, $db, 2);
assertTrue($refreshResult2['updated_count'] === 0, 'a scheme with no cache entry yet is not touched by a manual refresh — nothing to compute from');
$pendingRow = $dbAdvisorA->select('client_portfolio_items', ['id' => $pendingItem])[0];
assertTrue((float) $pendingRow['value'] === 0.0, '"price pending" holding stays at its prior value, never guessed at');

// --- attachNavFreshness(): the read-side helper client_portfolio_list.php uses ---
$rowsForAttach = $dbAdvisorA->select('client_portfolio_items', ['client_id' => 2]);
$attached = attachNavFreshness($db, $rowsForAttach);
assertTrue($attached['portfolio_nav_freshness'] !== null, 'portfolio_nav_freshness is set when at least one NAV-tracked row is cached');
$foundPendingInAttached = false;
foreach ($attached['rows'] as $r) {
    if ((int) $r['id'] === $pendingItem) {
        $foundPendingInAttached = ($r['nav_value'] === null);
    }
}
assertTrue($foundPendingInAttached, 'a not-yet-cached NAV-tracked row reports nav_value = null ("price pending"), not a fabricated number');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll MF NAV sync tests passed.\n";
