<?php
declare(strict_types=1);

// getReferenceCost() — the cache-first read educationRangeForDriver() uses
// below. Required here rather than left to callers (the usual convention in
// this codebase — see FinancialFoundations.php/CashFlowSummary.php, required
// by whichever endpoint needs both) because it is now a hard dependency of a
// PersonalisationReference method, not an optional pairing: a caller passing
// a $db without also having required ReferenceCosts.php would otherwise hit
// an undefined-function fatal instead of a clean fallback.
require_once __DIR__ . '/ReferenceCosts.php';

// docs/11 Prompt E-2 · The sourced reference figures the progressive
// personalisation queue offers — city-tier expense multipliers and
// per-driver education cost ranges.
//
// RECONCILED WITH docs/11 Prompt E-3's `reference_costs` (sql/037,
// api/lib/ReferenceCosts.php) — read that file's header for the full
// account. Summary: EDUCATION_COST_RANGES below is now the FALLBACK, not
// the source of truth — educationRangeForDriver() reads reference_costs
// first when a $db is passed, and only falls back to these constants when
// the cache has no row yet (the sync cron has never run) or no $db was
// given (keeps this class usable with zero DB dependency, same as before,
// for the pure unit tests in tests/test_personalisation_reference.php).
// CITY TIER deliberately stays a pure code-constant derivation, never
// backed by reference_costs — see ReferenceCosts.php's header for why an
// exact formula from a published percentage doesn't belong in a "sourced
// range" cache table.
//
// EVERY FIGURE HERE FOLLOWS docs/11's design principles:
//   - a RANGE, never a point estimate (principle 4)
//   - named source and as-of date
//   - always editable, never a silent default (principle 2 — 'not_recorded'
//     is a first-class state; nothing here is applied without the person
//     having deliberately answered the question that unlocks it)
//
// PROVENANCE, city tier:
// No official Indian city cost-of-living index exists (docs/11 §3). The 7th
// Pay Commission's HRA classification (X/Y/Z cities) is the closest
// government-published, stable, citable proxy for relative cost of living —
// it is literally what the HRA percentage is meant to compensate for. HRA
// rates (post the 50%-DA revision): X=30%, Y=20%, Z=10% of basic pay. This
// file normalises those three rates to a multiplier with X=1.00 as the
// baseline: multiplier = (100 + hra%) / (100 + hra%_of_X). That is a
// DERIVATION this codebase makes from a cited government figure, not itself
// an official cost-of-living index — labelled as such everywhere it renders.
// CLAUDE.md / docs/11 §3's non-obvious rule: this multiplier adjusts an
// EXPENSE assumption only, never the inflation rate.
final class PersonalisationReference
{
    public const CITY_TIER_SOURCE_NAME = '7th Pay Commission HRA city classification (X/Y/Z)';
    public const CITY_TIER_SOURCE_AS_OF = '2024 (post 50% DA revision)';

    /** HRA % of basic pay per city tier, as published — the cited figures this multiplier derives from. */
    private const CITY_TIER_HRA_PERCENT = [
        'X' => 30.0,
        'Y' => 20.0,
        'Z' => 10.0,
    ];

    public const CITY_TIER_LABELS = [
        'X' => 'Metro (X) — e.g. Mumbai, Delhi, Bengaluru, Chennai',
        'Y' => 'Large city (Y) — e.g. tier-2 state capitals, large towns',
        'Z' => 'Smaller town (Z) — everywhere else',
    ];

    /** Reasonable bounds for a user-supplied override — a typo guard, not an opinion. */
    public const MULTIPLIER_MIN = 0.30;
    public const MULTIPLIER_MAX = 2.00;

    /**
     * The DEFAULT multiplier for a city tier, derived from the cited HRA
     * rates above, normalised so tier X = 1.00.
     */
    public static function defaultMultiplierForTier(string $tier): ?float
    {
        if (!array_key_exists($tier, self::CITY_TIER_HRA_PERCENT)) {
            return null;
        }
        $baseline = 100.0 + self::CITY_TIER_HRA_PERCENT['X'];
        $thisTier = 100.0 + self::CITY_TIER_HRA_PERCENT[$tier];
        return round($thisTier / $baseline, 2);
    }

    /**
     * Resolve the EFFECTIVE expense multiplier to apply to a retirement-year
     * expense assumption, from whatever the person has recorded.
     *
     * Never invents a value: with no tier and no override, returns a neutral
     * 1.0 with is_applied=false, so callers can render "no adjustment" rather
     * than a false assumption.
     *
     * @return array{multiplier: float, is_applied: bool, is_override: bool, city_tier: ?string, source_name: ?string, source_as_of: ?string}
     */
    public static function cityTierMultiplier(?string $tier, ?float $override): array
    {
        if ($override !== null) {
            return [
                'multiplier'    => $override,
                'is_applied'    => true,
                'is_override'   => true,
                'city_tier'     => $tier,
                'source_name'   => null, // the person's own figure, not a cited default
                'source_as_of'  => null,
            ];
        }

        $default = $tier !== null ? self::defaultMultiplierForTier($tier) : null;
        if ($default === null) {
            return [
                'multiplier'    => 1.0,
                'is_applied'    => false,
                'is_override'   => false,
                'city_tier'     => null,
                'source_name'   => null,
                'source_as_of'  => null,
            ];
        }

        return [
            'multiplier'    => $default,
            'is_applied'    => true,
            'is_override'   => false,
            'city_tier'     => $tier,
            'source_name'   => self::CITY_TIER_SOURCE_NAME,
            'source_as_of'  => self::CITY_TIER_SOURCE_AS_OF,
        ];
    }

    // --- Education cost ranges, by cost DRIVER (not aspiration) -----------
    //
    // Deliberately generic across course types rather than split further by
    // "medical" / "engineering" / etc. — this prompt asks only the driver
    // (docs/11 §4 Prompt E-2 scope), not the course, so the range is framed as
    // a broad illustrative bucket across common professional/higher-education
    // courses, sourced from AICTE/NMC/state-counselling fee-disclosure
    // patterns (docs/11 §3). It is intentionally wide — a wide honestly-labelled
    // range beats a narrow confidently-wrong one. The "overseas" bucket has no
    // single Indian regulator source and is flagged as such.
    private const EDUCATION_COST_RANGES = [
        'government' => [
            'low' => 300000.0,
            'high' => 1000000.0,
            'source_name' => 'AICTE / NMC / state-counselling government-seat fee disclosures',
            'source_as_of' => '2025',
            'is_verified' => true,
        ],
        'private' => [
            'low' => 1500000.0,
            'high' => 4000000.0,
            'source_name' => 'AICTE / state-counselling private-seat fee disclosures',
            'source_as_of' => '2025',
            'is_verified' => true,
        ],
        'overseas' => [
            'low' => 5000000.0,
            'high' => 12000000.0,
            'source_name' => 'Illustrative overseas course + living-cost range — no single Indian regulator source',
            'source_as_of' => '2025',
            'is_verified' => false,
        ],
    ];

    public const EDUCATION_DRIVER_LABELS = [
        'government' => 'Government seat',
        'private' => 'Private institute',
        'overseas' => 'Overseas',
    ];

    /**
     * Cache-first: when $db is given, prefers reference_costs (docs/11
     * Prompt E-3) — the actual reconciled source of truth as of the merge
     * documented at the top of this file — and only falls back to
     * EDUCATION_COST_RANGES above when that cache has no row yet for this
     * driver (the sync cron has never run) or $db is null (every existing
     * caller that doesn't pass one keeps working exactly as before).
     * $driver is still validated against EDUCATION_COST_RANGES either way —
     * that const remains the single definition of "which drivers exist",
     * even when its VALUES aren't what gets returned.
     *
     * @return array{low: float, high: float, source_name: string, source_as_of: string, is_verified: bool}|null
     */
    public static function educationRangeForDriver(?string $driver, ?PDO $db = null): ?array
    {
        if ($driver === null || !array_key_exists($driver, self::EDUCATION_COST_RANGES)) {
            return null;
        }

        if ($db !== null) {
            $cached = getReferenceCost($db, 'education', $driver);
            if ($cached !== null) {
                return [
                    'low' => (float) $cached['low'],
                    'high' => (float) $cached['high'],
                    'source_name' => $cached['source_name'],
                    'source_as_of' => $cached['as_of_date'],
                    'is_verified' => (bool) $cached['is_verified'],
                ];
            }
        }

        return self::EDUCATION_COST_RANGES[$driver];
    }
}
