<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-1 — reconcile-by-holding CAS import diff. Pure, no DB —
 * same split as test_mf_nav_sync.php's Part 1 (parseAmfiNavFile()).
 */

require_once __DIR__ . '/../api/lib/PortfolioReconcile.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- normalizeSchemeName() ---------------------------------------------

assertTrue(normalizeSchemeName('HDFC Flexi Cap Fund') === normalizeSchemeName('  hdfc   flexi cap   fund  '), 'whitespace/casing noise normalises to the same key');
assertTrue(normalizeSchemeName('HDFC Flexi Cap Fund - Growth') !== normalizeSchemeName('HDFC Flexi Cap Fund - Direct Growth'), 'genuinely different plan variants are NOT collapsed together — normalisation is conservative on purpose');

// --- portfolioMatchKey() ------------------------------------------------

[$folioKey, $folioType] = portfolioMatchKey('12345678', 'Any Name At All');
assertTrue($folioType === 'folio', 'a present folio number uses the folio match path');
[$folioKey2] = portfolioMatchKey('12345678', 'A Totally Different Name');
assertTrue($folioKey === $folioKey2, 'the folio path ignores the description entirely — the folio alone is the key, robust to name drift between statements');

[$nameKey, $nameType] = portfolioMatchKey(null, 'HDFC Flexi Cap Fund');
assertTrue($nameType === 'name', 'no folio number falls back to the name path');
[$nameKey2] = portfolioMatchKey('', 'HDFC Flexi Cap Fund');
assertTrue($nameKey === $nameKey2, 'an empty-string folio is treated the same as null — falls back to name');

// --- computePortfolioReconciliation(): the core diff --------------------

// Scenario: client has 3 CAS-sourced holdings. A new statement:
//   - updates one (folio match, value changed)
//   - leaves one completely unchanged (folio match, same value+description)
//   - is missing one entirely (should be flagged, not deleted)
//   - adds one brand-new holding
$existing = [
    ['id' => 1, 'description' => 'HDFC Flexi Cap Fund - Growth', 'value' => 100000.0, 'folio_number' => 'F001'],
    ['id' => 2, 'description' => 'ICICI Pru Bluechip Fund', 'value' => 50000.0, 'folio_number' => 'F002'],
    ['id' => 3, 'description' => 'Axis Small Cap Fund', 'value' => 25000.0, 'folio_number' => 'F003'],
];
$incoming = [
    ['description' => 'HDFC Flexi Cap Fund - Growth', 'value' => 112000.0, 'folio_number' => 'F001'],
    ['description' => 'ICICI Pru Bluechip Fund', 'value' => 50000.0, 'folio_number' => 'F002'],
    // Axis Small Cap (F003) is absent from this statement entirely.
    ['description' => 'SBI PSU Fund - Direct Growth', 'value' => 30000.0, 'folio_number' => 'F004'],
];

$diff = computePortfolioReconciliation($existing, $incoming);

assertTrue(count($diff['to_update']) === 1, 'exactly one holding needs updating');
assertTrue($diff['to_update'][0]['id'] === 1, 'the HDFC fund (id 1) is the one that changed value');
assertTrue($diff['to_update'][0]['old_value'] === 100000.0 && $diff['to_update'][0]['new_value'] === 112000.0, 'old and new values are both reported, not just the new one');
assertTrue($diff['to_update'][0]['match_type'] === 'folio', 'the update is reported as a folio match, not a name guess');

assertTrue($diff['unchanged_count'] === 1, 'the ICICI fund (unchanged value+description) is counted as unchanged, not queued as an update');

assertTrue(count($diff['to_add']) === 1, 'exactly one brand-new holding');
assertTrue($diff['to_add'][0]['description'] === 'SBI PSU Fund - Direct Growth' && $diff['to_add'][0]['folio_number'] === 'F004', 'the new holding carries its folio number through');

assertTrue(count($diff['to_flag']) === 1, 'exactly one existing holding is flagged as missing from this statement');
assertTrue($diff['to_flag'][0]['id'] === 3, 'the Axis Small Cap fund (id 3, absent from the new statement) is the one flagged');
assertTrue($diff['to_flag'][0]['value'] === 25000.0, 'the flagged row reports its LAST KNOWN value, not zero or a guess');

// --- A flagged holding is never silently removed by this function itself ---
// (computePortfolioReconciliation only ever REPORTS a diff; deletion is a
// separate, explicit step the caller takes only on a user-confirmed id.)
assertTrue(!in_array(3, array_column($diff['to_update'], 'id'), true), 'the flagged holding never appears in to_update');
assertTrue(!in_array(3, array_map(static fn($a) => $a['description'], $diff['to_add']), true), 'the flagged holding never appears in to_add either — it is reported exactly once, in to_flag');

// --- Name-fallback matching (no folio column in this statement) ---------

$existingNoFolio = [
    ['id' => 10, 'description' => 'Parag Parikh Flexi Cap Fund', 'value' => 75000.0, 'folio_number' => null],
];
$incomingNoFolio = [
    ['description' => '  parag parikh   flexi cap fund  ', 'value' => 82000.0, 'folio_number' => null],
];
$diffNoFolio = computePortfolioReconciliation($existingNoFolio, $incomingNoFolio);
assertTrue(count($diffNoFolio['to_update']) === 1, 'a name-only match still finds the update despite whitespace/casing differences');
assertTrue($diffNoFolio['to_update'][0]['match_type'] === 'name', 'and is correctly reported as a name match, not a folio match — the caller can render this as lower-confidence');

// --- A holding with a folio doesn't accidentally name-collide with one without --
$mixedExisting = [
    ['id' => 20, 'description' => 'Same Fund Name', 'value' => 1000.0, 'folio_number' => 'F999'],
];
$mixedIncoming = [
    // Same description, but NO folio in this statement row — must NOT match
    // the folio-keyed existing row (different match key entirely: folio:F999 vs name:...).
    ['description' => 'Same Fund Name', 'value' => 2000.0, 'folio_number' => null],
];
$mixedDiff = computePortfolioReconciliation($mixedExisting, $mixedIncoming);
assertTrue(count($mixedDiff['to_update']) === 0, 'a folio-keyed existing row is never matched by a same-named incoming row with no folio — different match keys, on purpose');
assertTrue(count($mixedDiff['to_add']) === 1, 'the no-folio incoming row is instead treated as a new holding');
assertTrue(count($mixedDiff['to_flag']) === 1, 'and the folio-keyed existing row is flagged as missing, since nothing in the statement carried its folio');

// --- Empty inputs ---------------------------------------------------------

$emptyDiff = computePortfolioReconciliation([], []);
assertTrue($emptyDiff['to_update'] === [] && $emptyDiff['to_add'] === [] && $emptyDiff['to_flag'] === [] && $emptyDiff['unchanged_count'] === 0, 'no existing rows and no incoming rows -> a completely empty diff');

$allNewDiff = computePortfolioReconciliation([], $incoming);
assertTrue(count($allNewDiff['to_add']) === 3 && $allNewDiff['to_update'] === [] && $allNewDiff['to_flag'] === [], 'a client with no prior CAS-sourced rows treats every incoming row as new');

$allGoneDiff = computePortfolioReconciliation($existing, []);
assertTrue(count($allGoneDiff['to_flag']) === 3 && $allGoneDiff['to_add'] === [] && $allGoneDiff['to_update'] === [], 'an empty new statement flags every existing CAS row as missing, adds nothing, updates nothing');

// --- confirmedPortfolioRemovalIds(): never trusts the client-submitted list --

$confirmed = confirmedPortfolioRemovalIds($diff, [3, 999, 1, 2]);
assertTrue($confirmed === [3], 'only id 3 (the ONE id this diff actually flagged) survives — 999 (never existed), 1 and 2 (matched, not flagged) are all silently dropped, never deleted');

$confirmedEmpty = confirmedPortfolioRemovalIds($diff, []);
assertTrue($confirmedEmpty === [], 'no requested removals -> nothing confirmed, even though something WAS flagged');

$confirmedNoneFlagged = confirmedPortfolioRemovalIds($emptyDiff, [1, 2, 3]);
assertTrue($confirmedNoneFlagged === [], 'a diff that flagged nothing confirms nothing, no matter what the request asks for');

echo "\nAll portfolio-reconcile tests passed.\n";
