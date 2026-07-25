<?php
declare(strict_types=1);

/**
 * Pure computation only — no DB access. Keeps Section 4.2 (corpus multiple)
 * and Section 4.3 (decumulation projection) arithmetic in one place instead
 * of duplicated across goals_read.php and goals_projection.php.
 */
final class PlanMath
{
    /**
     * Section 4.2: corpus multiple = 1 / withdrawal rate, expressed as a
     * multiple of annual expenses (e.g. 3.5% -> 28.57x). Never stored,
     * computed on every read. Returns null when there's no rate to compute
     * from (e.g. non-retirement goal types, where withdrawal_rate is NULL).
     */
    public static function corpusMultiple(?float $withdrawalRatePercent): ?float
    {
        if ($withdrawalRatePercent === null || $withdrawalRatePercent <= 0.0) {
            return null;
        }
        return round(100.0 / $withdrawalRatePercent, 2);
    }

    /**
     * Section 4.3: year-by-year decumulation balance under a flat, constant
     * assumed return. balance[n] = balance[n-1]*(1+r) - annualWithdrawal*(1+inflation)^n.
     * annualWithdrawal is fixed at the year-1 rupee amount (initial_net_worth *
     * withdrawal_rate) and grows with inflation each subsequent year, per the
     * blueprint's formula — not recomputed from a shrinking balance.
     *
     * @return float[] index 0 = year 0 (starting balance), index n = balance after year n
     */
    public static function steadyReturnSeries(
        float $initialNetWorth,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $drawdownReturnRatePercent,
        int $horizonYears
    ): array {
        $annualWithdrawal = $initialNetWorth * ($withdrawalRatePercent / 100.0);
        $r = $drawdownReturnRatePercent / 100.0;
        $inflation = $inflationRatePercent / 100.0;

        $balances = [$initialNetWorth];
        $balance = $initialNetWorth;

        for ($n = 1; $n <= $horizonYears; $n++) {
            $withdrawalThisYear = $annualWithdrawal * (1 + $inflation) ** $n;
            $balance = $balance * (1 + $r) - $withdrawalThisYear;
            $balances[] = round($balance, 2);
        }

        return $balances;
    }

    /**
     * Section 4.3: sequence-of-returns stress test. Builds a deterministic
     * synthetic sequence of yearly returns whose arithmetic mean equals
     * drawdownReturnRatePercent (a fixed +/- spread alternating by year — not
     * derived from historical volatility data, this is explicitly a simple
     * illustrative reordering per the spec, not a backtest or Monte Carlo
     * draw), then re-sorts it ascending so below-average years land first and
     * above-average years land last. That ordering is the "adverse" case:
     * withdrawing during down years early permanently erodes principal in a
     * way that withdrawing during down years late does not, even though both
     * sequences share the same average return.
     *
     * @return float[] index 0 = year 0 (starting balance), index n = balance after year n
     */
    public static function adverseSequenceSeries(
        float $initialNetWorth,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $drawdownReturnRatePercent,
        int $horizonYears
    ): array {
        $annualWithdrawal = $initialNetWorth * ($withdrawalRatePercent / 100.0);
        $inflation = $inflationRatePercent / 100.0;

        // Fixed illustrative spread around the mean rate — documented as such,
        // not tuned against real market data. Alternates so the raw (pre-sort)
        // sequence's arithmetic mean stays equal to drawdownReturnRatePercent.
        $spreadPoints = 4.0;
        $rawReturns = [];
        for ($n = 1; $n <= $horizonYears; $n++) {
            $rawReturns[] = ($n % 2 === 1)
                ? $drawdownReturnRatePercent - $spreadPoints
                : $drawdownReturnRatePercent + $spreadPoints;
        }
        // If horizon is odd, the unpaired last element biases the mean slightly
        // low; correct it back onto the flat rate so the two series stay
        // comparable at a shared average, per the spec's "share a CAGR" intent.
        if ($horizonYears % 2 === 1 && $horizonYears > 0) {
            $rawReturns[$horizonYears - 1] = $drawdownReturnRatePercent;
        }

        sort($rawReturns); // ascending: worst years first, best years last

        $balances = [$initialNetWorth];
        $balance = $initialNetWorth;

        for ($n = 1; $n <= $horizonYears; $n++) {
            $withdrawalThisYear = $annualWithdrawal * (1 + $inflation) ** $n;
            $r = $rawReturns[$n - 1] / 100.0;
            $balance = $balance * (1 + $r) - $withdrawalThisYear;
            $balances[] = round($balance, 2);
        }

        return $balances;
    }

    /**
     * docs/07 Bet 3: Retirement Readiness Score, 0-100. Deterministic, built
     * entirely from numbers already computed elsewhere in this class — no new
     * inputs, no schema, no DB access (still "pure computation only" per the
     * class docblock). Three components:
     *   - 40%: fraction of the horizon the steady-return series survives
     *     (balance stays >= 0)
     *   - 40%: fraction of the horizon the adverse-sequence series survives
     *   - 20%: corpus multiple vs. the Indian-calibrated band from docs/02
     *     §4.2 (2.5%-4% withdrawal rate -> ~25x-40x corpus multiple),
     *     clamped a touch wider (20x-40x) so a rate slightly outside the
     *     suggested range still yields a defined score rather than a
     *     cliff-edge. Omitted (weights renormalized to 50/50) when the
     *     withdrawal rate isn't set.
     *
     * Weights are named constants here, not a tenant-configurable table — no
     * tenant has asked for that yet (docs/07 §5: no speculative per-tenant
     * configurability before a tenant asks). This is a transparency score for
     * illustration, not a recommendation — the "how is this calculated?" copy
     * in the UI should state the formula, not just the number.
     */
    public static function readinessScore(?float $withdrawalRatePercent, array $steadySeries, array $adverseSeries): ?int
    {
        if (count($steadySeries) < 2 || count($adverseSeries) < 2) {
            return null; // no horizon to measure survival over
        }

        $steadyFraction = self::survivalFraction($steadySeries);
        $adverseFraction = self::survivalFraction($adverseSeries);

        $multiple = self::corpusMultiple($withdrawalRatePercent);
        if ($multiple !== null) {
            $multipleFactor = max(0.0, min(1.0, ($multiple - 20.0) / (40.0 - 20.0)));
            $score = ($steadyFraction * 0.4) + ($adverseFraction * 0.4) + ($multipleFactor * 0.2);
        } else {
            $score = ($steadyFraction * 0.5) + ($adverseFraction * 0.5);
        }

        return (int) round($score * 100);
    }

    /**
     * Fraction of years 1..N (index 0 is the starting balance, excluded)
     * that stayed non-negative. Depletes at the first negative year -> the
     * fraction of years survived before that point. Never depletes -> 1.0.
     */
    private static function survivalFraction(array $series): float
    {
        $horizonYears = count($series) - 1;
        if ($horizonYears <= 0) {
            return 1.0;
        }
        for ($n = 1; $n <= $horizonYears; $n++) {
            if ($series[$n] < 0) {
                return ($n - 1) / $horizonYears;
            }
        }
        return 1.0;
    }
}
