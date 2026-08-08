<?php
declare(strict_types=1);

/**
 * docs/11 Prompt E-3 — the sourced reference-cost library. Two parts, same
 * split as api/lib/ReferenceCosts.php itself:
 *   - referenceCostDataset() is pure (no DB) — checked for shape, driver
 *     coverage (docs/11 §3: government/private, not the aspiration), and
 *     that city tier is a multiplier (never confused with an inflation
 *     rate).
 *   - syncReferenceCosts() / getReferenceCost() / listReferenceCosts() are
 *     DB-touching, tested against a real database inside a rolled-back
 *     transaction, same pattern as test_mf_nav_sync.php.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/ReferenceCosts.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// ─── Part 1: referenceCostDataset() — pure, no DB ──────────────────────────

$dataset = referenceCostDataset();
assertTrue(count($dataset) > 0, 'the curated dataset is non-empty');

$byKey = [];
foreach ($dataset as $row) {
    $key = $row['category'] . '/' . $row['subcategory'];
    assertTrue(!isset($byKey[$key]), "no duplicate (category, subcategory) rows: $key");
    $byKey[$key] = $row;
    assertTrue($row['low'] <= $row['high'], "low <= high for $key");
    assertTrue($row['low'] > 0, "low is positive for $key");
    assertTrue(in_array($row['unit'], ['inr_total', 'inr_annual', 'multiplier'], true), "unit is one of the three known units for $key");
}

assertTrue(isset($byKey['education/engineering_government'], $byKey['education/engineering_private']), 'education asks the driver (government vs. private), not the aspiration (docs/11 §3)');
assertTrue($byKey['education/engineering_private']['low'] > $byKey['education/engineering_government']['high'], 'private engineering range sits clearly above the government range — the driver actually moves the number');
assertTrue(isset($byKey['education/medical_government'], $byKey['education/medical_private']), 'medical also has both drivers');
assertTrue($byKey['education/medical_private']['low'] / $byKey['education/medical_government']['high'] >= 10, 'medical government vs. private is roughly the ~20x-scale swing docs/11 §3 describes');

assertTrue(isset($byKey['healthcare/ongoing_medical_annual']), 'healthcare asks for the financial consequence (an annual rupee figure), not a diagnosis (docs/11 §5)');

foreach (['city_tier_x', 'city_tier_y', 'city_tier_z'] as $tier) {
    $key = "city_expense_multiplier/$tier";
    assertTrue(isset($byKey[$key]), "city tier $tier is present");
    assertTrue($byKey[$key]['unit'] === 'multiplier', "$tier is a multiplier, never an inflation rate (docs/11 §3's load-bearing call)");
}
assertTrue(
    $byKey['city_expense_multiplier/city_tier_x']['low'] > $byKey['city_expense_multiplier/city_tier_z']['high'],
    'tier X (metro) multiplier sits above tier Z — the ordering is sane'
);

foreach ($dataset as $row) {
    assertTrue($row['source_name'] !== '' && stripos($row['source_name'], 'internet') === false, "source_name is a named authority, not \"the internet\", for {$row['category']}/{$row['subcategory']}");
}

// ─── Part 2: DB-touching functions, real database, rolled back ────────────

$db = getPdo();
$db->beginTransaction();

$db->exec('DELETE FROM reference_costs');

$result = syncReferenceCosts($db);
assertTrue($result['rows_written'] === count($dataset), 'sync writes exactly one row per dataset entry');

$countInDb = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countInDb === count($dataset), 'reference_costs holds exactly one row per (category, subcategory)');

$anyVerified = (int) $db->query('SELECT COUNT(*) FROM reference_costs WHERE is_verified = 1')->fetchColumn();
assertTrue($anyVerified === 0, 'every freshly-synced row is is_verified = 0, same disclosure as market_history\'s own seed');

// --- a second sync (simulating the next cron run) upserts in place, not a new row ---
syncReferenceCosts($db);
$countAfterSecondSync = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countAfterSecondSync === count($dataset), 'reference_costs upserts in place (a cache, not an append-only history) — row count unchanged on a re-run');

// --- once a human verifies a row, a later dataset sync must not silently unflip it ---
$db->exec("UPDATE reference_costs SET is_verified = 1 WHERE category = 'education' AND subcategory = 'engineering_private'");
syncReferenceCosts($db);
$stillVerified = (bool) $db->query("SELECT is_verified FROM reference_costs WHERE category = 'education' AND subcategory = 'engineering_private'")->fetchColumn();
assertTrue($stillVerified, 'a re-sync never resets a row a human already verified back to unverified');

// --- getReferenceCost(): single-row read, the opt-in prompt's source ---
$got = getReferenceCost($db, 'education', 'engineering_private');
assertTrue($got !== null, 'getReferenceCost finds an existing (category, subcategory) row');
assertTrue($got['label'] === $byKey['education/engineering_private']['label'], 'getReferenceCost returns the right row');

$missing = getReferenceCost($db, 'education', 'nonexistent_driver');
assertTrue($missing === null, 'getReferenceCost returns null for an uncached combination — never fabricates a range');

// --- listReferenceCosts(): category filter ---
$educationRows = listReferenceCosts($db, 'education');
assertTrue(count($educationRows) === 4, 'listReferenceCosts filters correctly to just the education category');
foreach ($educationRows as $r) {
    assertTrue($r['category'] === 'education', 'every row returned by the category filter actually matches it');
}

$allRows = listReferenceCosts($db);
assertTrue(count($allRows) === count($dataset), 'listReferenceCosts with no filter returns everything');

// --- formatReferenceCostRow(): API-shape helper ---
$formatted = formatReferenceCostRow($got);
assertTrue(is_float($formatted['low']) && is_float($formatted['high']), 'formatReferenceCostRow casts low/high to float');
assertTrue(is_bool($formatted['is_verified']), 'formatReferenceCostRow casts is_verified to a real bool');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll reference-cost tests passed.\n";
