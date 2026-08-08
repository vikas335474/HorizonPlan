<?php
declare(strict_types=1);

/**
 * docs/11 Prompt E-2 — the sourced reference figures (city-tier expense
 * multipliers, per-driver education cost ranges). Pure, no database.
 */

require_once __DIR__ . '/../api/lib/PersonalisationReference.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

function assertClose(float $a, float $b, string $label, float $tolerance = 0.01): void
{
    assertTrue(abs($a - $b) < $tolerance, "$label (got $a, expected ~$b)");
}

// --- city tier multipliers ---------------------------------------------

assertClose(PersonalisationReference::defaultMultiplierForTier('X'), 1.0, 'tier X is the 1.00 baseline');
assertTrue(PersonalisationReference::defaultMultiplierForTier('Y') < 1.0, 'tier Y is cheaper than the metro baseline');
assertTrue(PersonalisationReference::defaultMultiplierForTier('Z') < PersonalisationReference::defaultMultiplierForTier('Y'), 'tier Z is cheaper again than tier Y — the ordering is monotonic');
assertTrue(PersonalisationReference::defaultMultiplierForTier('Q') === null, 'an unknown tier has no default');

// No tier, no override -> neutral 1.0, explicitly NOT applied.
$none = PersonalisationReference::cityTierMultiplier(null, null);
assertClose($none['multiplier'], 1.0, 'nothing recorded -> a neutral multiplier');
assertTrue($none['is_applied'] === false, 'and it is flagged as not applied, not as "confirmed neutral"');

// Tier only -> the sourced default, flagged as an assumption (not an override).
$tierOnly = PersonalisationReference::cityTierMultiplier('Z', null);
assertClose($tierOnly['multiplier'], PersonalisationReference::defaultMultiplierForTier('Z'), 'a tier alone uses its sourced default');
assertTrue($tierOnly['is_applied'] === true && $tierOnly['is_override'] === false, 'a tier-only figure is applied but marked as OUR assumption, not the user\'s own');
assertTrue($tierOnly['source_name'] !== null, 'a sourced default always carries a citation');

// An explicit override always wins, even alongside a tier, and carries no
// source citation — it is now the PERSON's own figure, not ours.
$override = PersonalisationReference::cityTierMultiplier('Z', 0.77);
assertClose($override['multiplier'], 0.77, 'an explicit override wins over the tier default');
assertTrue($override['is_override'] === true, 'and is flagged as the user\'s own figure');
assertTrue($override['source_name'] === null, 'a user override carries no source citation — it is not our assumption');

// --- education cost ranges ---------------------------------------------

foreach (['government', 'private', 'overseas'] as $driver) {
    $range = PersonalisationReference::educationRangeForDriver($driver);
    assertTrue($range !== null, "a range exists for driver '$driver'");
    assertTrue($range['low'] > 0 && $range['high'] > $range['low'], "$driver: low < high, both positive");
    assertTrue($range['source_name'] !== '', "$driver: carries a source name");
}

// The driver, not the aspiration, is what moves the number — government and
// private ranges must differ substantially (the whole point of asking the
// driver rather than the dream, docs/11 §3).
$gov = PersonalisationReference::educationRangeForDriver('government');
$priv = PersonalisationReference::educationRangeForDriver('private');
assertTrue($priv['low'] > $gov['high'], 'private-driver range sits meaningfully above government-driver range');

// Overseas has no single regulator source and must be flagged unverified —
// the same is_verified distinction market_history draws for unsourced years.
$overseas = PersonalisationReference::educationRangeForDriver('overseas');
assertTrue($overseas['is_verified'] === false, 'the overseas range is flagged unverified — no single Indian regulator source');
assertTrue($gov['is_verified'] === true && $priv['is_verified'] === true, 'government/private ranges are sourced from cited fee-disclosure patterns');

assertTrue(PersonalisationReference::educationRangeForDriver(null) === null, 'no driver -> no range, never a guess');
assertTrue(PersonalisationReference::educationRangeForDriver('doctor') === null, 'an aspiration string, not a driver, returns null rather than a wrong guess');

echo "\nAll personalisation-reference tests passed.\n";
