<?php
declare(strict_types=1);

/**
 * docs/11 Prompt E-3 — the sourced reference-cost library. Two parts, same
 * split as api/lib/ReferenceCosts.php itself:
 *   - referenceCostDataset() is pure (no DB) — checked for shape and driver
 *     coverage (docs/11 §3/§4: government/private/overseas — the same
 *     generic driver enum docs/11 Prompt E-2's `client_dependants.cost_driver`
 *     uses, reconciled here, not the aspiration or the course).
 *   - syncReferenceCosts() / getReferenceCost() / listReferenceCosts() are
 *     DB-touching, tested against a real database inside a rolled-back
 *     transaction, same pattern as test_mf_nav_sync.php — including the
 *     PRUNE behavior (a row the dataset no longer defines gets deleted, not
 *     left as an orphan) this reconciliation added.
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
    // Every unit here must be an ABSOLUTE RUPEE FIGURE. That is the invariant
    // this guards — not the specific list. A dimensionless unit (a multiplier,
    // a ratio, a percentage) would mean someone had cached a city-tier-style
    // scaling factor in this table, which the reconciliation in
    // ReferenceCosts.php's header explicitly rules out; the redundant
    // city_expense_multiplier check below guards the same thing by name.
    // Extend this list when a new absolute-rupee unit ships — living_cost's
    // inr_monthly_per_capita is one, and it is per-capita rather than
    // per-household precisely so nothing downstream can quietly read a
    // per-person figure as a household one (LivingCostReference.php).
    assertTrue(in_array($row['unit'], ['inr_total', 'inr_annual', 'inr_monthly_per_capita'], true), "unit is an absolute rupee figure for $key — no multiplier/ratio rows (city tier is deliberately not cached here)");
    assertTrue(array_key_exists('is_verified', $row) && is_bool($row['is_verified']), "$key carries an explicit boolean is_verified in the dataset itself");
}

// Reconciled with docs/11 Prompt E-2: the SAME generic driver enum
// client_dependants.cost_driver uses (government/private/overseas), not the
// course-specific split (engineering/medical) an earlier draft of this
// dataset used before the two prompts' work was reconciled.
assertTrue(isset($byKey['education/government'], $byKey['education/private'], $byKey['education/overseas']), 'education uses the reconciled generic driver buckets: government, private, overseas');
assertTrue($byKey['education/private']['low'] > $byKey['education/government']['high'], 'private range sits clearly above government — the driver actually moves the number (docs/11 §3)');
assertTrue($byKey['education/overseas']['low'] > $byKey['education/private']['high'], 'overseas sits above private again — monotonic ordering across drivers');
assertTrue($byKey['education/government']['is_verified'] === true && $byKey['education/private']['is_verified'] === true, 'government/private education ranges are seeded verified — inherited from PersonalisationReference\'s own already-reviewed figures, not reset by the reconciliation');
assertTrue($byKey['education/overseas']['is_verified'] === false, 'overseas has no single Indian regulator source and stays flagged unverified');

assertTrue(isset($byKey['healthcare/ongoing_medical_annual']), 'healthcare asks for the financial consequence (an annual rupee figure), not a diagnosis (docs/11 §5)');

// City tier is deliberately NOT cached here (see ReferenceCosts.php's header
// on the reconciliation) — PersonalisationReference's exact HRA-derived
// formula is the sole source, not a "sourced range" table.
foreach ($byKey as $key => $row) {
    assertTrue(strpos($key, 'city_expense_multiplier') !== 0, "no city_expense_multiplier rows in the dataset ($key found) — city tier stays a pure formula, not a cache");
}

foreach ($dataset as $row) {
    assertTrue($row['source_name'] !== '' && stripos($row['source_name'], 'internet') === false, "source_name is a named authority, not \"the internet\", for {$row['category']}/{$row['subcategory']}");
}

// ─── Part 2: DB-touching functions, real database, rolled back ────────────

$db = getPdo();
$db->beginTransaction();

$db->exec('DELETE FROM reference_costs');

$result = syncReferenceCosts($db);
assertTrue($result['rows_written'] === count($dataset), 'sync writes exactly one row per dataset entry');
assertTrue($result['rows_pruned'] === 0, 'a sync against an empty table prunes nothing');

$countInDb = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countInDb === count($dataset), 'reference_costs holds exactly one row per (category, subcategory)');

$govVerified = (bool) $db->query("SELECT is_verified FROM reference_costs WHERE category = 'education' AND subcategory = 'government'")->fetchColumn();
assertTrue($govVerified, 'a first insert takes is_verified from the dataset row (government seeds verified)');
$overseasVerified = (bool) $db->query("SELECT is_verified FROM reference_costs WHERE category = 'education' AND subcategory = 'overseas'")->fetchColumn();
assertTrue(!$overseasVerified, 'and overseas seeds unverified, per its own dataset row');

// --- a second sync (simulating the next cron run) upserts in place, not a new row ---
$secondResult = syncReferenceCosts($db);
$countAfterSecondSync = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countAfterSecondSync === count($dataset), 'reference_costs upserts in place (a cache, not an append-only history) — row count unchanged on a re-run');
assertTrue($secondResult['rows_pruned'] === 0, 'a re-sync against an unchanged dataset prunes nothing');

// --- once a human verifies a row, a later dataset sync must not silently unflip it ---
$db->exec("UPDATE reference_costs SET is_verified = 1 WHERE category = 'education' AND subcategory = 'overseas'");
syncReferenceCosts($db);
$stillVerified = (bool) $db->query("SELECT is_verified FROM reference_costs WHERE category = 'education' AND subcategory = 'overseas'")->fetchColumn();
assertTrue($stillVerified, 'a re-sync never resets a row a human already verified back to unverified, even though the dataset itself says false');

// --- PRUNE: a row the dataset no longer defines gets deleted on the next sync ---
$db->exec("INSERT INTO reference_costs (category, subcategory, label, unit, low, high, source_name, source_url, as_of_date, is_verified, fetched_at)
           VALUES ('education', 'engineering_government', 'stale course-specific row from before reconciliation', 'inr_total', 1.0, 2.0, 'stale', NULL, '2024-01-01', 0, NOW())");
$countWithStaleRow = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countWithStaleRow === count($dataset) + 1, 'the stale row is really there before the next sync');
$pruneResult = syncReferenceCosts($db);
assertTrue($pruneResult['rows_pruned'] === 1, 'the sync reports exactly the one pruned row');
$staleGone = getReferenceCost($db, 'education', 'engineering_government');
assertTrue($staleGone === null, 'a (category, subcategory) the current dataset no longer defines is deleted, not left as an orphan');
$countAfterPrune = (int) $db->query('SELECT COUNT(*) FROM reference_costs')->fetchColumn();
assertTrue($countAfterPrune === count($dataset), 'row count is back to exactly the dataset size after the prune');

// --- getReferenceCost(): single-row read, the opt-in prompt's source ---
$got = getReferenceCost($db, 'education', 'private');
assertTrue($got !== null, 'getReferenceCost finds an existing (category, subcategory) row');
assertTrue($got['label'] === $byKey['education/private']['label'], 'getReferenceCost returns the right row');

$missing = getReferenceCost($db, 'education', 'nonexistent_driver');
assertTrue($missing === null, 'getReferenceCost returns null for an uncached combination — never fabricates a range');

// --- listReferenceCosts(): category filter ---
$educationRows = listReferenceCosts($db, 'education');
assertTrue(count($educationRows) === 3, 'listReferenceCosts filters correctly to just the education category (the 3 reconciled driver buckets)');
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
