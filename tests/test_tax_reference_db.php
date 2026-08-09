<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-2 — the sourced tax-treatment reference. Same split as
 * test_reference_costs_db.php: dataset shape checks (pure, no DB) then
 * sync/upsert/prune checks against a real database, rolled back.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TaxReference.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// ─── Part 1: taxReferenceDataset() — pure, no DB ───────────────────────────

$dataset = taxReferenceDataset();
assertTrue(count($dataset) > 0, 'the curated dataset is non-empty');

$byKey = [];
foreach ($dataset as $row) {
    $key = $row['category'] . '/' . $row['subcategory'];
    assertTrue(!isset($byKey[$key]), "no duplicate (category, subcategory) rows: $key");
    $byKey[$key] = $row;
    assertTrue($row['label'] !== '', "$key carries a non-empty label");
    assertTrue($row['source_name'] !== '', "$key carries a non-empty source_name");
    assertTrue(array_key_exists('is_verified', $row) && is_bool($row['is_verified']), "$key carries an explicit boolean is_verified");
    // Every row must say SOMETHING — at minimum one of the four note fields,
    // or it's a row with nothing useful to show.
    $hasContent = $row['short_term_note'] !== null || $row['long_term_note'] !== null || $row['exemption_note'] !== null || $row['other_note'] !== null;
    assertTrue($hasContent, "$key has at least one non-null note field");
}

// mutual_fund's three-regime split — the whole point of this table.
assertTrue(isset($byKey['mutual_fund/equity'], $byKey['mutual_fund/debt'], $byKey['mutual_fund/hybrid']), 'mutual_fund has all three fund_type regimes: equity, debt, hybrid');
assertTrue($byKey['mutual_fund/equity']['holding_period_months'] === 12, 'equity funds carry the 12-month short/long line');
assertTrue($byKey['mutual_fund/debt']['holding_period_months'] === null, 'debt funds carry NO holding-period line — no LTCG concept applies since April 2023');
assertTrue($byKey['mutual_fund/debt']['long_term_note'] === null && $byKey['mutual_fund/debt']['short_term_note'] !== null, 'debt funds have a short_term_note (slab, always) but no long_term_note — there is no LTCG regime to describe');

// Every row this dataset seeds is unverified — tax rules move every budget.
foreach ($dataset as $row) {
    assertTrue($row['is_verified'] === false, "every seeded row is_verified=false (tax rules change every budget) — {$row['category']}/{$row['subcategory']}");
}

// Holding-period thresholds match the two real dividing lines this dataset
// actually uses: 12 months (listed equity/equity-fund/REIT/listed-bond
// style instruments) or 24 months (gold/real estate) or null (interest-
// income-style instruments with no capital-gains concept at all).
foreach ($dataset as $row) {
    $months = $row['holding_period_months'];
    assertTrue($months === null || $months === 12 || $months === 24, "holding_period_months for {$row['category']}/{$row['subcategory']} is one of the two real dividing lines, or null");
}

// Interest-income-style instruments (no ST/LT split at all) each still say something.
foreach (['savings', 'fd', 'cash', 'ppf', 'epf', 'nps'] as $cat) {
    $key = "$cat/default";
    assertTrue(isset($byKey[$key]), "$cat is covered");
    assertTrue($byKey[$key]['holding_period_months'] === null, "$cat correctly has no short/long split — it's an income/exemption fact, not a capital gain");
}

// ─── Part 2: DB-touching functions, real database, rolled back ────────────

$db = getPdo();
$db->beginTransaction();

$db->exec('DELETE FROM tax_reference');

$result = syncTaxReference($db);
assertTrue($result['rows_written'] === count($dataset), 'sync writes exactly one row per dataset entry');
assertTrue($result['rows_pruned'] === 0, 'a sync against an empty table prunes nothing');

$countInDb = (int) $db->query('SELECT COUNT(*) FROM tax_reference')->fetchColumn();
assertTrue($countInDb === count($dataset), 'tax_reference holds exactly one row per (category, subcategory)');

$anyVerified = (int) $db->query('SELECT COUNT(*) FROM tax_reference WHERE is_verified = 1')->fetchColumn();
assertTrue($anyVerified === 0, 'every freshly-synced row is is_verified = 0');

// --- a second sync upserts in place, not a new row ---
syncTaxReference($db);
$countAfterSecondSync = (int) $db->query('SELECT COUNT(*) FROM tax_reference')->fetchColumn();
assertTrue($countAfterSecondSync === count($dataset), 'tax_reference upserts in place — row count unchanged on a re-run');

// --- once a human verifies a row, a re-sync never resets it ---
$db->exec("UPDATE tax_reference SET is_verified = 1 WHERE category = 'stocks' AND subcategory = 'default'");
syncTaxReference($db);
$stillVerified = (bool) $db->query("SELECT is_verified FROM tax_reference WHERE category = 'stocks' AND subcategory = 'default'")->fetchColumn();
assertTrue($stillVerified, 'a re-sync never resets a row a human already verified back to unverified');

// --- PRUNE: a row the dataset no longer defines gets deleted ---
$db->exec("INSERT INTO tax_reference (category, subcategory, label, holding_period_months, short_term_note, long_term_note, exemption_note, other_note, source_name, source_url, as_of_date, is_verified, fetched_at)
           VALUES ('mutual_fund', 'commodity', 'a category this dataset no longer defines', NULL, NULL, NULL, NULL, 'stale', 'stale', NULL, '2024-01-01', 0, NOW())");
$pruneResult = syncTaxReference($db);
assertTrue($pruneResult['rows_pruned'] === 1, 'the stale row is pruned');
assertTrue(getTaxReference($db, 'mutual_fund', 'commodity') === null, 'and is gone from a direct lookup');

// --- getTaxReference() / listTaxReference() ---
$equity = getTaxReference($db, 'mutual_fund', 'equity');
assertTrue($equity !== null && $equity['label'] === $byKey['mutual_fund/equity']['label'], 'getTaxReference finds the right row');
assertTrue(getTaxReference($db, 'mutual_fund', 'commodity') === null, 'an uncached combination returns null, never a fabricated note');

$mfRows = listTaxReference($db, 'mutual_fund');
assertTrue(count($mfRows) === 3, 'listTaxReference filters correctly to just the mutual_fund category — all three regimes');

$allRows = listTaxReference($db);
assertTrue(count($allRows) === count($dataset), 'listTaxReference with no filter returns everything');

// --- formatTaxReferenceRow(): API-shape helper ---
$formatted = formatTaxReferenceRow($equity);
assertTrue(is_int($formatted['holding_period_months']), 'formatTaxReferenceRow casts holding_period_months to a real int');
assertTrue(is_bool($formatted['is_verified']), 'formatTaxReferenceRow casts is_verified to a real bool');

$debtFormatted = formatTaxReferenceRow(getTaxReference($db, 'mutual_fund', 'debt'));
assertTrue($debtFormatted['holding_period_months'] === null, 'a null holding_period_months round-trips as null, not 0');

$db->rollBack();

echo "\nAll tax-reference tests passed.\n";
