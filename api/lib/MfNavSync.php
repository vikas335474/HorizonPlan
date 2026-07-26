<?php
declare(strict_types=1);

/**
 * Daily MF NAV price-sync — scoped, by direct user instruction, to only the
 * schemes actually held in a client_portfolio_items row (never AMFI's full
 * scheme universe, to avoid unnecessary data in the DB). Split into layers,
 * same reasoning as GoogleAuth.php/PlanMath.php's own splits:
 *   1. fetchAmfiNavRawText() — the one function that actually calls out to
 *      AMFI over HTTPS. Not unit-tested here (no live network access proved
 *      reachable in this sandbox — see the migration/session notes in
 *      CLAUDE.md) — code-reviewed only, same disclosure as the historical
 *      market-data session's own outbound-block finding.
 *   2. parseAmfiNavFile() — pure, no network/DB. Parses AMFI's NAVAll.txt
 *      pipe-delimited format and filters to only the requested scheme codes
 *      before returning anything, so nothing downstream ever sees (or
 *      persists) a scheme nobody holds. Fully unit-testable with a fixture
 *      string shaped like the real AMFI export.
 *   3. syncMfNavCache() / refreshPortfolioValuesFromCache() — DB-touching but
 *      deterministic given their inputs, so both are testable against a real
 *      database with a synthetic parsed-NAV array, without ever calling AMFI.
 *
 * AMFI's own bulk file (not a per-scheme API — AMFI does not publish one) is
 * used deliberately over an unofficial third-party wrapper (e.g. mfapi.in):
 * this is financial data for a SEBI-RIA-facing product, so the authoritative
 * source is preferred even though it means downloading more than we keep —
 * "avoid unnecessary data in the DB" (the user's own words) is a persistence
 * constraint, not a download-volume one, and parseAmfiNavFile() enforces the
 * DB side of that by filtering before anything is returned.
 */

const AMFI_NAV_ALL_URL = 'https://www.amfiindia.com/spider/getnavopen.aspx';

/**
 * Downloads AMFI's daily NAVAll.txt-format export as a raw string, or null on
 * any network failure / non-200 response — the caller (syncMfNavCache())
 * treats "couldn't fetch today" as a no-op, not an error that corrupts the
 * existing cache.
 */
function fetchAmfiNavRawText(): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init(AMFI_NAV_ALL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200 || $body === '') {
        return null;
    }

    return $body;
}

/**
 * Parses AMFI's semicolon-delimited NAV export. The real file groups schemes
 * under AMC header lines and blank separator lines, with a data row shaped:
 *   Scheme Code;ISIN Div Payout/Growth;ISIN Div Reinvestment;Scheme Name;Net Asset Value;Date
 * Only rows whose Scheme Code is in $schemeCodesFilter are kept — this is the
 * actual mechanism that keeps the DB scoped to held schemes, not a filter
 * applied after the fact.
 *
 * @param string[] $schemeCodesFilter
 * @return array<string,array{name:string,nav:float,date:string}> keyed by scheme code
 */
function parseAmfiNavFile(string $rawText, array $schemeCodesFilter): array
{
    $wanted = array_flip($schemeCodesFilter);
    $result = [];

    $lines = preg_split('/\r\n|\r|\n/', $rawText);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, ';') === false) {
            continue; // blank lines and AMC header/category lines carry no ';'
        }

        $fields = explode(';', $line);
        if (count($fields) < 6) {
            continue;
        }

        $schemeCode = trim($fields[0]);
        if ($schemeCode === '' || !isset($wanted[$schemeCode])) {
            continue;
        }

        $schemeName = trim($fields[3]);
        $navRaw = trim($fields[4]);
        $dateRaw = trim($fields[5]);

        if (!is_numeric($navRaw)) {
            continue; // e.g. "N.A." for a scheme with no NAV declared that day
        }

        $navDate = null;
        $parsed = DateTime::createFromFormat('d-M-Y', $dateRaw);
        if ($parsed !== false) {
            $navDate = $parsed->format('Y-m-d');
        }
        if ($navDate === null) {
            continue; // unparsable date — skip rather than guess
        }

        $result[$schemeCode] = [
            'name' => $schemeName,
            'nav'  => (float) $navRaw,
            'date' => $navDate,
        ];
    }

    return $result;
}

/**
 * The distinct set of AMFI scheme codes actually referenced by any client's
 * portfolio, across every tenant — this IS the "master list of schemes in
 * customer portfolios" the cron is scoped to, computed fresh every run
 * rather than maintained as a separate list that could drift.
 *
 * @return string[]
 */
function distinctHeldSchemeCodes(PDO $db): array
{
    $stmt = $db->query(
        "SELECT DISTINCT amfi_scheme_code FROM client_portfolio_items
          WHERE amfi_scheme_code IS NOT NULL AND units_held IS NOT NULL"
    );
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Upserts mf_nav_cache for every scheme in $parsedNav (already filtered to
 * held codes by the caller), then writes the new value straight into every
 * matching client_portfolio_items row (value = units_held * nav_value) —
 * this single pass IS the "automatic refresh of client data whenever prices
 * are updated" behavior: no separate propagation step, no polling.
 *
 * Deliberately raw SQL, not TenantScopedDb — a cron is trusted global
 * infrastructure updating rows that legitimately span every tenant in one
 * pass, the same category of exception tools/seed_demo_data_full.php and
 * api/demo_reset.php already rely on for their own cross-tenant writes.
 *
 * @param array<string,array{name:string,nav:float,date:string}> $parsedNav
 * @return array{cached:int,portfolio_rows_updated:int}
 */
function applyParsedNavToCache(PDO $db, array $parsedNav): array
{
    $now = date('Y-m-d H:i:s');
    $upsert = $db->prepare(
        'INSERT INTO mf_nav_cache (amfi_scheme_code, scheme_name, nav_value, nav_date, fetched_at)
              VALUES (:code, :name, :nav, :date, :fetched)
         ON DUPLICATE KEY UPDATE
              scheme_name = VALUES(scheme_name),
              nav_value   = VALUES(nav_value),
              nav_date    = VALUES(nav_date),
              fetched_at  = VALUES(fetched_at)'
    );
    $updateItems = $db->prepare(
        'UPDATE client_portfolio_items
            SET value = ROUND(units_held * :nav, 2)
          WHERE amfi_scheme_code = :code AND units_held IS NOT NULL'
    );

    $rowsUpdated = 0;
    foreach ($parsedNav as $code => $info) {
        $upsert->execute([
            ':code' => $code, ':name' => $info['name'], ':nav' => $info['nav'],
            ':date' => $info['date'], ':fetched' => $now,
        ]);
        $updateItems->execute([':nav' => $info['nav'], ':code' => $code]);
        $rowsUpdated += $updateItems->rowCount();
    }

    return ['cached' => count($parsedNav), 'portfolio_rows_updated' => $rowsUpdated];
}

/**
 * The full daily cron: figure out which schemes are actually held, fetch
 * AMFI's export, filter+cache+propagate. Returns a summary for the cron's own
 * log output. If the fetch fails outright (network down, AMFI unreachable),
 * this is a no-op — the existing cache and portfolio values are left exactly
 * as they were, never zeroed or guessed at.
 *
 * @return array{held_scheme_count:int,cached:int,unmatched:string[],portfolio_rows_updated:int,fetch_failed:bool}
 */
function syncMfNavCache(PDO $db): array
{
    $heldCodes = distinctHeldSchemeCodes($db);
    if (empty($heldCodes)) {
        return ['held_scheme_count' => 0, 'cached' => 0, 'unmatched' => [], 'portfolio_rows_updated' => 0, 'fetch_failed' => false];
    }

    $raw = fetchAmfiNavRawText();
    if ($raw === null) {
        return ['held_scheme_count' => count($heldCodes), 'cached' => 0, 'unmatched' => $heldCodes, 'portfolio_rows_updated' => 0, 'fetch_failed' => true];
    }

    $parsed = parseAmfiNavFile($raw, $heldCodes);
    $applied = applyParsedNavToCache($db, $parsed);
    $unmatched = array_values(array_diff($heldCodes, array_keys($parsed)));

    return [
        'held_scheme_count'      => count($heldCodes),
        'cached'                 => $applied['cached'],
        'unmatched'              => $unmatched,
        'portfolio_rows_updated' => $applied['portfolio_rows_updated'],
        'fetch_failed'           => false,
    ];
}

/**
 * The manual "Refresh" button's backend: recompute one client's NAV-tracked
 * rows from whatever is CURRENTLY in mf_nav_cache — no network call, exactly
 * "using the cached data locally" per the user's own instruction. A brand
 * new scheme code with no cache entry yet is left untouched ("price
 * pending") rather than zeroed, until the next daily cron seeds it.
 *
 * @return array{updated_count:int,nav_last_updated:?string}
 */
function refreshPortfolioValuesFromCache(TenantScopedDb $scopedDb, PDO $db, int $clientId): array
{
    $rows = $scopedDb->select('client_portfolio_items', ['client_id' => $clientId]);

    $navTracked = array_values(array_filter(
        $rows,
        static fn(array $r) => $r['amfi_scheme_code'] !== null && $r['units_held'] !== null
    ));
    if (empty($navTracked)) {
        return ['updated_count' => 0, 'nav_last_updated' => null];
    }

    $codes = array_values(array_unique(array_map(static fn(array $r) => $r['amfi_scheme_code'], $navTracked)));
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare("SELECT * FROM mf_nav_cache WHERE amfi_scheme_code IN ($placeholders)");
    $stmt->execute($codes);
    $cacheByCode = [];
    foreach ($stmt->fetchAll() as $cacheRow) {
        $cacheByCode[$cacheRow['amfi_scheme_code']] = $cacheRow;
    }

    $updated = 0;
    $freshest = null;
    foreach ($navTracked as $row) {
        $cache = $cacheByCode[$row['amfi_scheme_code']] ?? null;
        if ($cache === null) {
            continue; // price pending — no cache entry yet for this scheme
        }
        $newValue = round((float) $row['units_held'] * (float) $cache['nav_value'], 2);
        if (abs($newValue - (float) $row['value']) > 0.005) {
            $scopedDb->update('client_portfolio_items', ['value' => $newValue], ['id' => (int) $row['id']]);
            $updated++;
        }
        if ($freshest === null || $cache['fetched_at'] > $freshest) {
            $freshest = $cache['fetched_at'];
        }
    }

    return ['updated_count' => $updated, 'nav_last_updated' => $freshest];
}

/**
 * The read-side counterpart: for a client's already-loaded portfolio rows,
 * attach each NAV-tracked row's cached nav_value/nav_date/fetched_at (null
 * if not yet cached — "price pending"), plus the single oldest fetched_at
 * across all of them as the card's overall freshness claim (the most
 * conservative honest answer to "how fresh is this data", never the newest).
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{rows:array<int,array<string,mixed>>,portfolio_nav_freshness:?string}
 */
function attachNavFreshness(PDO $db, array $rows): array
{
    $codes = array_values(array_unique(array_filter(array_map(
        static fn(array $r) => $r['amfi_scheme_code'] ?? null,
        $rows
    ))));

    $cacheByCode = [];
    if (!empty($codes)) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $db->prepare("SELECT * FROM mf_nav_cache WHERE amfi_scheme_code IN ($placeholders)");
        $stmt->execute($codes);
        foreach ($stmt->fetchAll() as $cacheRow) {
            $cacheByCode[$cacheRow['amfi_scheme_code']] = $cacheRow;
        }
    }

    $freshest = null;
    $enriched = array_map(function (array $row) use ($cacheByCode, &$freshest) {
        $code = $row['amfi_scheme_code'] ?? null;
        $cache = $code !== null ? ($cacheByCode[$code] ?? null) : null;
        if ($cache !== null && ($freshest === null || $cache['fetched_at'] < $freshest)) {
            $freshest = $cache['fetched_at'];
        }
        $row['nav_value'] = $cache !== null ? (float) $cache['nav_value'] : null;
        $row['nav_date'] = $cache['nav_date'] ?? null;
        $row['nav_fetched_at'] = $cache['fetched_at'] ?? null;
        return $row;
    }, $rows);

    return ['rows' => $enriched, 'portfolio_nav_freshness' => $freshest];
}
