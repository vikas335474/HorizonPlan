<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-2 · Per-holding tax context — orchestrates
 * TaxReference.php's cached treatment notes with a specific portfolio
 * item's own fund_type/acquisition_value/acquisition_date into one shaped
 * answer for the UI. Pure — no DB, no PDO anywhere in this file — the
 * caller (client_portfolio_list.php) preloads the whole tax_reference table
 * once via listTaxReference() into a small map and passes it in, so this
 * logic is fully unit-testable with a synthetic map and no database.
 *
 * THE HONEST BOUNDARY OF "FACTS ONLY" HERE (docs/12 §1/§5 — read before
 * changing this file). This app has no transaction/sale ledger, only a
 * snapshot of what's currently held. So it can compute:
 *   - the TREATMENT that would apply if this holding were sold/withdrawn
 *     today (from TaxReference.php's cached notes)
 *   - whether, as of today, it would count as short- or long-term (from
 *     acquisition_date alone — needs no cost basis)
 *   - an ILLUSTRATIVE UNREALISED gain/loss (current value − acquisition
 *     value), IF acquisition_value was recorded
 * It CANNOT compute "how much LTCG exemption headroom you have left this
 * year" — that requires knowing every realised gain from every sale this
 * financial year, which nothing in this schema tracks. docs/12's own D-2
 * prompt text uses the word "headroom"; this implementation deliberately
 * stops at "illustrative gain on what's still held" instead, because a
 * fabricated headroom number would be worse than no number at all
 * (principle 3: never fabricate a figure). Say so in the UI, don't imply it.
 */

/**
 * Whole months between $acquisitionDate and $asOfDate (default: today),
 * rounded DOWN to the last completed month — the conservative choice for a
 * short/long-term line, since "11 months and 29 days" should not read as
 * 12. Returns null for an unset or unparseable date.
 */
function monthsHeld(?string $acquisitionDate, ?string $asOfDate = null): ?int
{
    if ($acquisitionDate === null || trim($acquisitionDate) === '') {
        return null;
    }
    try {
        $acquired = new DateTime($acquisitionDate);
        $asOf = $asOfDate !== null ? new DateTime($asOfDate) : new DateTime();
    } catch (Exception $e) {
        return null;
    }
    if ($acquired > $asOf) {
        return null; // an acquisition date in the future is not a real holding period
    }
    $diff = $acquired->diff($asOf);
    return $diff->y * 12 + $diff->m;
}

/**
 * 'short_term' | 'long_term' | null. Null when either input is unknown, or
 * when the instrument has no short/long split at all (a savings account,
 * an FD, PPF/EPF/NPS — $thresholdMonths is null for those rows, see
 * TaxReference.php). At EXACTLY the threshold, still short-term — every
 * treatment note here is phrased "held N months or less" / "more than N
 * months", so this must match that wording exactly.
 */
function classifyHoldingPeriod(?int $monthsHeld, ?int $thresholdMonths): ?string
{
    if ($monthsHeld === null || $thresholdMonths === null) {
        return null;
    }
    return $monthsHeld > $thresholdMonths ? 'long_term' : 'short_term';
}

/**
 * The illustrative UNREALISED gain/loss on a still-held position — never a
 * realised, filing-grade figure (see this file's header). Returns null
 * when no acquisition_value was recorded — never a fabricated zero.
 *
 * @return ?array{amount: float, pct: ?float}
 */
function illustrativeGain(?float $acquisitionValue, float $currentValue): ?array
{
    if ($acquisitionValue === null) {
        return null;
    }
    $amount = $currentValue - $acquisitionValue;
    $pct = $acquisitionValue > 0.0 ? round(($amount / $acquisitionValue) * 100, 2) : null;
    return ['amount' => round($amount, 2), 'pct' => $pct];
}

/**
 * Which tax_reference subcategories apply to this holding. Every category
 * except mutual_fund has exactly one ('default'). A mutual_fund with a
 * recorded fund_type resolves to exactly that one regime; with NO fund_type
 * recorded, resolves to ALL THREE — equity, debt, hybrid — so the caller
 * shows every regime side by side rather than silently guessing which one
 * applies (the decide-then-build call on docs/12 Prompt D-2).
 *
 * @return list<string>
 */
function resolveTaxSubcategories(string $category, ?string $fundType): array
{
    if ($category === 'mutual_fund') {
        return $fundType !== null ? [$fundType] : ['equity', 'debt', 'hybrid'];
    }
    return ['default'];
}

/**
 * The full per-holding tax context, built from an already-loaded
 * tax_reference map (see this file's header for why it's a map, not a DB
 * call) and one portfolio item's own fields.
 *
 * @param array{category:string,fund_type:?string,value:float,acquisition_value:?float,acquisition_date:?string} $item
 * @param array<string,array<string,mixed>> $taxReferenceByKey keyed "category|subcategory" -> formatTaxReferenceRow() output
 * @return array{
 *   applicable: bool,
 *   needs_fund_type: bool,
 *   treatments: list<array<string,mixed>>,
 *   months_held: ?int,
 *   holding_period_classification: ?string,
 *   gain: ?array{amount:float,pct:?float}
 * }
 */
function buildPortfolioTaxContext(array $item, array $taxReferenceByKey, ?string $asOfDate = null): array
{
    $category = $item['category'];
    $fundType = $item['fund_type'] ?? null;

    $subcategories = resolveTaxSubcategories($category, $fundType);
    $treatments = [];
    foreach ($subcategories as $sc) {
        $key = $category . '|' . $sc;
        if (isset($taxReferenceByKey[$key])) {
            $treatments[] = $taxReferenceByKey[$key];
        }
    }

    $monthsHeldValue = monthsHeld($item['acquisition_date'] ?? null, $asOfDate);

    // Holding-period classification only renders when exactly ONE treatment
    // is in play (a resolved fund_type, or a category with just one regime)
    // — with three candidate regimes shown side by side, "short vs long
    // term" would be ambiguous without knowing which regime actually applies.
    $classification = null;
    if (count($treatments) === 1 && $treatments[0]['holding_period_months'] !== null) {
        $classification = classifyHoldingPeriod($monthsHeldValue, $treatments[0]['holding_period_months']);
    }

    return [
        'applicable'                     => count($treatments) > 0,
        'needs_fund_type'                => $category === 'mutual_fund' && $fundType === null,
        'treatments'                     => $treatments,
        'months_held'                    => $monthsHeldValue,
        'holding_period_classification'  => $classification,
        'gain'                           => illustrativeGain($item['acquisition_value'] ?? null, (float) $item['value']),
    ];
}
