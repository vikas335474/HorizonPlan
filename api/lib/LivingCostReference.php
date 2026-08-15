<?php
declare(strict_types=1);

/**
 * Sourced living-cost anchors for the retirement-spend estimate.
 *
 * WHY THIS EXISTS. PlanMath::retirementTarget() needs a monthly-expenses
 * figure, which comes from the person's own cash_flow_items. Someone who has
 * never recorded their expenses therefore gets nothing at all — no anchor, no
 * starting point, no plan. For a self-serve individual with no adviser to
 * supply that judgement, that is a dead end (docs/13).
 *
 * WHY MOSPI HCES AND NOT A COST-OF-LIVING SITE. docs/11 §3 rules out
 * crowd-sourced cost-of-living sites in one line — "Crowd-sourced,
 * unverifiable, ToS-restricted. Do not scrape." Numbeo is the canonical
 * example: its terms prohibit scraping without written permission, and its
 * commercial licence does not permit disseminating its data through a
 * public-facing product without separate consent. MOSPI's Household
 * Consumption Expenditure Survey is the better source on every axis that
 * matters here: official, free, citable, and it covers non-metro India
 * properly, which is most of this product's market.
 *
 * *** THE DANGEROUS PART, READ THIS BEFORE CHANGING ANYTHING BELOW ***
 * MPCE is average per-capita CONSUMPTION across all households in a
 * state — it is NOT "what a comfortable retirement costs", and it is not
 * even close for this product's users. Someone planning retirement with an
 * adviser, or self-serve on a platform like this one, spends well above the
 * population average. Anchoring them to MPCE uncritically would understate
 * their retirement target, which is the single most harmful error this app
 * could make.
 *
 * So this module deliberately:
 *   - NEVER pre-fills the person's expense field. It offers context beside
 *     their own input, never in place of it (docs/11 design principle 2:
 *     distinguish OUR assumption from the person's own figure at a glance).
 *   - labels every figure as a population average, in the copy, not a target.
 *   - scales per-capita to household size, because a per-capita figure shown
 *     against a household budget is off by a factor of three or four and
 *     reads as authoritative while being wrong.
 *
 * Shipped as part of referenceCostDataset() rather than as its own sync tool.
 * That is not stylistic: syncReferenceCosts()'s prune step selects EVERY row
 * in reference_costs and deletes anything its own dataset does not define, so
 * a second tool writing to this table would have its rows silently removed on
 * the next run of the first one. One dataset, one sync, one prune.
 *
 * *** is_verified = 0 on every row. *** These are training-knowledge
 * transcriptions of published HCES 2023-24 figures, not fetched live (no
 * runtime or CLI-session internet access — docs/11 §3). Each row names the
 * fact sheet it came from. A human must check them against the real
 * publication before the UI stops calling them unverified.
 */

/** MOSPI HCES 2023-24 fact sheet — the single source for every row below. */
const HCES_SOURCE_NAME = 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE';
const HCES_SOURCE_URL  = 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf';
/** The survey period these figures describe, not the date they were entered. */
const HCES_AS_OF_DATE  = '2024-12-27';

/**
 * Average monthly per-capita consumption expenditure, in rupees, by
 * (state, sector).
 *
 * DELIBERATELY PARTIAL. Only states whose published figure is actually known
 * here appear. The HCES fact sheet covers all 36 states and UTs; inventing
 * the rest so the dropdown looks complete would be exactly the fabrication
 * CLAUDE.md forbids, and a plausible-looking wrong anchor is worse than an
 * honest fallback. Anything not listed resolves to the all-India row, and the
 * UI says so.
 *
 * To extend: open the fact sheet at HCES_SOURCE_URL, copy the real numbers,
 * and add them here. That is the whole maintenance story.
 *
 * @return array<string, array{label:string, rural:?float, urban:?float}>
 */
function livingCostByState(): array
{
    return [
        'all_india'      => ['label' => 'All India (average)', 'rural' => 4122.0, 'urban' => 6996.0],
        'sikkim'         => ['label' => 'Sikkim',              'rural' => 9377.0, 'urban' => 13927.0],
        'kerala'         => ['label' => 'Kerala',              'rural' => 6611.0, 'urban' => null],
        'punjab'         => ['label' => 'Punjab',              'rural' => 5817.0, 'urban' => null],
        'tamil_nadu'     => ['label' => 'Tamil Nadu',          'rural' => 5701.0, 'urban' => 8165.0],
        'telangana'      => ['label' => 'Telangana',           'rural' => 5435.0, 'urban' => 8978.0],
        'karnataka'      => ['label' => 'Karnataka',           'rural' => null,   'urban' => 8076.0],
        'maharashtra'    => ['label' => 'Maharashtra',         'rural' => null,   'urban' => 7363.0],
        'andhra_pradesh' => ['label' => 'Andhra Pradesh',      'rural' => null,   'urban' => 7182.0],
        'chhattisgarh'   => ['label' => 'Chhattisgarh',        'rural' => 2739.0, 'urban' => 4927.0],
    ];
}

/**
 * The living-cost slice of referenceCostDataset().
 *
 * One row per (state, sector) actually published. low and high are set to the
 * SAME figure, and that is deliberate rather than lazy: HCES publishes a point
 * average at this granularity, not a range. Equal bounds say "no range is
 * published here" — they must never be read as precision, and the UI carries
 * the uncertainty in words instead of pretending to a spread that no source
 * supports.
 *
 * @return list<array<string,mixed>>
 */
function livingCostDataset(): array
{
    $rows = [];
    foreach (livingCostByState() as $key => $state) {
        foreach (['rural', 'urban'] as $sector) {
            if ($state[$sector] === null) {
                continue; // not published here — see livingCostByState()'s note
            }
            $rows[] = [
                'category'    => 'living_cost',
                'subcategory' => "{$key}_{$sector}",
                'label'       => "{$state['label']} — " . ($sector === 'urban' ? 'urban' : 'rural')
                                 . ' (average spend per person, per month)',
                'unit'        => 'inr_monthly_per_capita',
                'low'         => $state[$sector],
                'high'        => $state[$sector],
                'source_name' => HCES_SOURCE_NAME,
                'source_url'  => HCES_SOURCE_URL,
                'as_of_date'  => HCES_AS_OF_DATE,
                // Never true from this file — see the header. A human verifies
                // against the fact sheet and flips this in the database.
                'is_verified' => false,
            ];
        }
    }
    return $rows;
}

/**
 * Resolve the anchor for a (state, sector), falling back to all-India.
 *
 * Pure — takes the already-loaded cache rows so it needs no DB and is fully
 * unit-testable, the same split PortfolioTaxContext.php uses.
 *
 * The fallback is reported, not hidden: `is_fallback` lets the UI say "we
 * don't have Bihar, so this is the all-India figure" rather than presenting a
 * national average as if it were local. A silent fallback here would be a
 * figure that looks personalised and isn't — the exact failure docs/11 §3
 * warns about for city-wise inflation.
 *
 * @param array<string, array{low:float, high:float, is_verified:bool}> $cacheBySubcategory
 * @return array{per_capita:float, is_fallback:bool, is_verified:bool, sector:string}|null
 */
function livingCostAnchor(array $cacheBySubcategory, ?string $stateKey, string $sector): ?array
{
    if ($sector !== 'rural' && $sector !== 'urban') {
        return null;
    }

    // is_fallback means "this is NOT the caller's own state's figure", which
    // includes the case where they never told us a state. Reporting that as a
    // non-fallback would let the UI present a national average as though it
    // were local — the one thing this flag exists to prevent — so the ONLY
    // path to false is an exact hit on a state the caller actually named.
    $ownKey = ($stateKey !== null && $stateKey !== '') ? "{$stateKey}_{$sector}" : null;

    if ($ownKey !== null && isset($cacheBySubcategory[$ownKey])) {
        $row = $cacheBySubcategory[$ownKey];
        return [
            'per_capita'  => (float) $row['low'],
            'is_fallback' => false,
            'is_verified' => (bool) ($row['is_verified'] ?? false),
            'sector'      => $sector,
        ];
    }

    $nationalKey = "all_india_{$sector}";
    if (isset($cacheBySubcategory[$nationalKey])) {
        $row = $cacheBySubcategory[$nationalKey];
        return [
            'per_capita'  => (float) $row['low'],
            'is_fallback' => true,
            'is_verified' => (bool) ($row['is_verified'] ?? false),
            'sector'      => $sector,
        ];
    }

    return null;
}

/**
 * Scale a per-capita anchor to a household.
 *
 * MPCE is PER PERSON. Showing it beside a household budget without this step
 * understates by a factor of the household size, and does so in a way that
 * looks authoritative — the whole reason this function exists rather than the
 * caller multiplying inline and getting it wrong once per surface.
 *
 * $householdSize is people, not dependants: someone with two dependants is a
 * household of three. Clamped to at least 1 so a bad input can never produce
 * a zero or negative monthly figure that would silently flatter a plan.
 *
 * @return array{per_capita:float, household_size:int, monthly_household:float}
 */
function livingCostForHousehold(float $perCapita, int $householdSize): array
{
    $size = max(1, $householdSize);
    return [
        'per_capita'        => round($perCapita, 2),
        'household_size'    => $size,
        'monthly_household' => round($perCapita * $size, 2),
    ];
}
