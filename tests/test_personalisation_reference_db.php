<?php
declare(strict_types=1);

/**
 * The reconciliation between docs/11 Prompt E-2 (PersonalisationReference.php)
 * and Prompt E-3 (reference_costs / ReferenceCosts.php) — specifically,
 * educationRangeForDriver()'s cache-first read. Kept separate from the pure
 * tests/test_personalisation_reference.php (no DB there, by design, same
 * split as MfNavSync.php/test_mf_nav_sync.php) since this is the one DB-
 * touching path PersonalisationReference now has.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/PersonalisationReference.php'; // pulls in ReferenceCosts.php itself

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();
$db->beginTransaction();
$db->exec('DELETE FROM reference_costs');

// --- No $db passed at all: unchanged behavior, the hardcoded fallback --------
$noDb = PersonalisationReference::educationRangeForDriver('government');
assertTrue($noDb !== null, 'with no $db argument, the fallback constant still answers (every pre-reconciliation caller keeps working)');

// --- $db passed, but the cache is empty (cron never run): falls back cleanly ---
$emptyCache = PersonalisationReference::educationRangeForDriver('government', $db);
assertTrue($emptyCache !== null, 'an empty reference_costs cache still falls back to the constant, never returns null for a real driver');
assertTrue($emptyCache['low'] === $noDb['low'] && $emptyCache['high'] === $noDb['high'], 'the fallback figures match exactly whether or not $db was passed, while the cache is empty');

// --- An unknown driver is still rejected outright, cache or no cache ---------
assertTrue(PersonalisationReference::educationRangeForDriver('doctor', $db) === null, 'an aspiration string, not a driver, still returns null even with a $db — the enum gate runs before any cache lookup');
assertTrue(PersonalisationReference::educationRangeForDriver(null, $db) === null, 'no driver -> no range, with or without $db');

// --- Populate the cache with a value that deliberately DIFFERS from the ------
//     fallback constant, to prove the cache actually wins when present.
$db->exec("INSERT INTO reference_costs (category, subcategory, label, unit, low, high, source_name, source_url, as_of_date, is_verified, fetched_at)
           VALUES ('education', 'government', 'freshly-verified government range', 'inr_total', 350000.0, 950000.0, 'A human just checked this against AICTE', NULL, '2026-01-01', 1, NOW())");

$cached = PersonalisationReference::educationRangeForDriver('government', $db);
assertTrue($cached !== null, 'a populated cache row is found');
assertTrue($cached['low'] === 350000.0 && $cached['high'] === 950000.0, 'the CACHED figures win over the hardcoded fallback once reference_costs has a row for this driver');
assertTrue($cached['source_name'] === 'A human just checked this against AICTE', 'the cached row\'s own source_name is returned, not the fallback\'s');
assertTrue($cached['is_verified'] === true, 'the cached row\'s own is_verified is returned as a real bool');

// A driver NOT in the cache still falls back correctly, even while a sibling
// driver IS cached — proves the lookup is per-driver, not all-or-nothing.
$stillFallback = PersonalisationReference::educationRangeForDriver('private', $db);
assertTrue($stillFallback['low'] === 1500000.0, 'a driver with no cache row of its own still uses the fallback constant, unaffected by a sibling driver being cached');

// --- Passing $db=null again after the cache is populated: cache is ignored ---
$ignoresCache = PersonalisationReference::educationRangeForDriver('government');
assertTrue($ignoresCache['low'] === $noDb['low'], 'omitting $db always uses the fallback constant, even once the cache holds a different figure — a deliberate opt-in, not automatic');

$db->rollBack();

echo "\nAll personalisation-reference/reference-costs reconciliation tests passed.\n";
