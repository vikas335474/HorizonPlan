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
}
