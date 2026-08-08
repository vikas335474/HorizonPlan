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
     * Funding status for a TARGET-BASED goal (education / home_purchase /
     * other) — the goal types that carry a target_amount + target_date but,
     * by schema design, never carry a return rate or a SIP (goals_create.php
     * nulls accumulation_return_rate / monthly_sip_amount for every
     * non-retirement goal). Retirement goals have the Readiness Score; these
     * had no progress signal at all until this helper existed.
     *
     * What it deliberately does NOT do: project growth. With no return
     * assumption anywhere on the row, any "you'll have ₹X by then" figure
     * would be an invented rate presented as a plan — exactly the kind of
     * opinion CLAUDE.md's guardrail says to leave to the firm. So this
     * answers only the two questions that ARE honestly answerable from the
     * data on hand:
     *
     *   1. What will this actually cost by then? — target_amount inflated to
     *      the target date at the goal's own inflation_rate (a goal set at
     *      "₹20L for college in 2034" really needs ₹31L in 2034 rupees).
     *   2. What does today's corpus cover of that? — initial_net_worth as a
     *      percentage of the inflated cost, with NO growth assumed. This is a
     *      deliberate floor, not a forecast: the real figure can only be
     *      better once the money is invested, and understating is the safe
     *      direction for a client-facing number.
     *
     * Returns null when the goal isn't target-based (no target_amount or no
     * target_date), so callers can simply omit the block — same convention as
     * corpusMultiple() returning null for a non-retirement goal.
     *
     * @param float|null  $targetAmount   target in TODAY's rupees
     * @param string|null $targetDate     'Y-m-d'
     * @param float       $currentCorpus  base_plans.initial_net_worth
     * @param float       $inflationPct   the goal's own inflation_rate
     * @param string|null $asOf           'Y-m-d' override for testing; defaults to today
     * @return array{years_remaining: float, inflated_target: float, covered_pct: float, shortfall: float}|null
     */
    public static function targetGoalFunding(
        ?float $targetAmount,
        ?string $targetDate,
        float $currentCorpus,
        float $inflationPct,
        ?string $asOf = null
    ): ?array {
        if ($targetAmount === null || $targetAmount <= 0.0 || $targetDate === null || $targetDate === '') {
            return null;
        }

        $now = strtotime($asOf ?? date('Y-m-d'));
        $then = strtotime($targetDate);
        if ($now === false || $then === false) {
            return null;
        }

        // Fractional years, floored at 0 — a target date already in the past
        // means "due now", not a negative horizon that would deflate the target.
        $yearsRemaining = max(0.0, ($then - $now) / (365.25 * 24 * 60 * 60));

        $inflatedTarget = $targetAmount * pow(1 + ($inflationPct / 100.0), $yearsRemaining);
        // Guard against a zero/absurd inflated target producing a division by zero.
        $coveredPct = $inflatedTarget > 0.0
            ? min(100.0, max(0.0, ($currentCorpus / $inflatedTarget) * 100.0))
            : 0.0;

        return [
            'years_remaining' => round($yearsRemaining, 2),
            'inflated_target' => round($inflatedTarget, 2),
            'covered_pct'     => round($coveredPct, 1),
            'shortfall'       => round(max(0.0, $inflatedTarget - $currentCorpus), 2),
        ];
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
        // not tuned against real market data (see adverseReturnSequence()'s
        // own docblock for the full construction).
        $rawReturns = self::adverseReturnSequence($drawdownReturnRatePercent, $horizonYears);

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
     * The corpus-composition branch shared by goals_projection.php (full
     * series, for its chart) and readinessScoreForGoal() below (score only,
     * for list-view "at a glance" signals) — picks the two-bucket methods
     * when a goal has actually decomposed its corpus (all three of
     * liquidCorpusAmount/lockedCorpusAmount/lockedReturnRatePercent set),
     * otherwise the original single-bucket methods. Extracted so the two
     * call sites can't silently drift on which condition triggers which
     * series, same reasoning as the adverseReturnSequence() extraction below.
     *
     * @return array{0: float[], 1: float[]} [steadySeries, adverseSeries]
     */
    public static function decumulationSeriesForGoal(
        float $initialNetWorth,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $drawdownReturnRatePercent,
        int $horizonYears,
        ?float $liquidCorpusAmount = null,
        ?float $lockedCorpusAmount = null,
        ?float $lockedReturnRatePercent = null
    ): array {
        if ($liquidCorpusAmount !== null && $lockedCorpusAmount !== null && $lockedReturnRatePercent !== null) {
            return [
                self::twoBucketDecumulationSeries($liquidCorpusAmount, $lockedCorpusAmount, $withdrawalRatePercent, $inflationRatePercent, $drawdownReturnRatePercent, $lockedReturnRatePercent, $horizonYears),
                self::twoBucketAdverseSequenceSeries($liquidCorpusAmount, $lockedCorpusAmount, $withdrawalRatePercent, $inflationRatePercent, $drawdownReturnRatePercent, $lockedReturnRatePercent, $horizonYears),
            ];
        }

        return [
            self::steadyReturnSeries($initialNetWorth, $withdrawalRatePercent, $inflationRatePercent, $drawdownReturnRatePercent, $horizonYears),
            self::adverseSequenceSeries($initialNetWorth, $withdrawalRatePercent, $inflationRatePercent, $drawdownReturnRatePercent, $horizonYears),
        ];
    }

    /**
     * Readiness score for a goal's own (non-overridden) values — a thin
     * wrapper over decumulationSeriesForGoal() + readinessScore() for callers
     * that only need the number, not the series (goals_list.php and
     * clients_list.php's at-a-glance signals; goals_projection.php needs the
     * series too, for its chart, so it calls decumulationSeriesForGoal()
     * directly instead of this). Sub-scenario overrides are a caller concern —
     * this always scores the goal's own baseline values.
     */
    public static function readinessScoreForGoal(
        float $initialNetWorth,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $drawdownReturnRatePercent,
        int $horizonYears,
        ?float $liquidCorpusAmount = null,
        ?float $lockedCorpusAmount = null,
        ?float $lockedReturnRatePercent = null
    ): ?int {
        [$steady, $adverse] = self::decumulationSeriesForGoal(
            $initialNetWorth, $withdrawalRatePercent, $inflationRatePercent, $drawdownReturnRatePercent, $horizonYears,
            $liquidCorpusAmount, $lockedCorpusAmount, $lockedReturnRatePercent
        );
        return self::readinessScore($withdrawalRatePercent, $steady, $adverse);
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

    /**
     * docs/07 Bet 2: historical sequence replay — "what if you retired in
     * [year]?". Same iterative withdrawal formula as steadyReturnSeries, but
     * each year's return and inflation come from a real historical record
     * instead of a flat assumption, and the withdrawal inflates by the
     * ACTUAL compounded historical CPI for the years elapsed rather than a
     * single assumed rate raised to a power.
     *
     * If the requested horizon runs past the end of the available history
     * (e.g. replaying 30 years starting from a recent year), this wraps back
     * to the earliest available year rather than falling back to an assumed
     * flat rate mid-series — documented behavior, not a bug: every year in
     * the output is still a real historical year, just cycling through the
     * ones on record once they run out.
     *
     * @param array<int,array{year:int,equity_return_pct:float,cpi_inflation_pct:float}> $history
     *   ALL available years, any order — this method locates $startYear
     *   within it (after sorting ascending) and wraps as needed, so the
     *   caller doesn't have to pre-slice or pre-sort.
     * @return float[] index 0 = year 0 (starting balance), index n = balance after year n.
     *   Returns just [$initialNetWorth] if $startYear isn't present in $history —
     *   callers should validate the year against available years before calling
     *   this, e.g. via the years returned by market_history_years.php.
     */
    public static function historicalSequenceSeries(
        float $initialNetWorth,
        float $withdrawalRatePercent,
        array $history,
        int $startYear,
        int $horizonYears
    ): array {
        usort($history, static fn(array $a, array $b) => $a['year'] <=> $b['year']);

        $startIndex = null;
        foreach ($history as $i => $row) {
            if ((int) $row['year'] === $startYear) {
                $startIndex = $i;
                break;
            }
        }

        $count = count($history);
        if ($startIndex === null || $count === 0) {
            return [$initialNetWorth];
        }

        $annualWithdrawal = $initialNetWorth * ($withdrawalRatePercent / 100.0);
        $balances = [$initialNetWorth];
        $balance = $initialNetWorth;
        $cumulativeInflation = 1.0;

        for ($n = 1; $n <= $horizonYears; $n++) {
            $row = $history[($startIndex + $n - 1) % $count]; // wrap around once real history is exhausted
            $cumulativeInflation *= (1 + ((float) $row['cpi_inflation_pct']) / 100.0);
            $withdrawalThisYear = $annualWithdrawal * $cumulativeInflation;
            $balance = $balance * (1 + ((float) $row['equity_return_pct']) / 100.0) - $withdrawalThisYear;
            $balances[] = round($balance, 2);
        }

        return $balances;
    }

    /**
     * docs/07 Session C / docs/06 Section A: accumulation-phase (pre-retirement
     * saving years) balance, year by year. balance[n] = balance[n-1]*(1+r) +
     * annualSip[n], where annualSip[n] = 12*monthlySipAmount*(1+stepUp)^(n-1)
     * — the base monthly amount runs unstepped through year 1, then increases
     * once per full year thereafter (standard Indian step-up SIP convention,
     * matching Investwell Mint's calculator per docs/05).
     *
     * accumulationReturnRatePercent is guardrail-distinct from
     * drawdownReturnRatePercent (docs/05 item 1, docs/06 guardrail 1) — never
     * merge these into one rate, even though the arithmetic shape is similar.
     *
     * @return float[] index 0 = starting balance (year 0), index n = balance after year n
     */
    public static function accumulationSeries(
        float $initialNetWorth,
        float $accumulationReturnRatePercent,
        float $monthlySipAmount,
        float $sipStepUpRatePercent,
        int $yearsToRetirement
    ): array {
        $r = $accumulationReturnRatePercent / 100.0;
        $stepUp = $sipStepUpRatePercent / 100.0;

        $balances = [$initialNetWorth];
        $balance = $initialNetWorth;

        for ($n = 1; $n <= $yearsToRetirement; $n++) {
            $annualSip = 12 * $monthlySipAmount * (1 + $stepUp) ** ($n - 1);
            $balance = $balance * (1 + $r) + $annualSip;
            $balances[] = round($balance, 2);
        }

        return $balances;
    }

    /**
     * docs/07 Session C / docs/06 Section A: one continuous curve spanning
     * the saving years and the withdrawal years — accumulationSeries()'s
     * terminal corpus becomes decumulation's starting balance. The two
     * series are concatenated with the boundary year (retirement day)
     * de-duplicated: accumulationSeries()'s last point and
     * steadyReturnSeries()'s year-0 point are the same balance, so only one
     * copy survives in the combined array.
     *
     * @return float[] index 0 = starting balance, index yearsToRetirement =
     *   retirement-day corpus, subsequent indices = decumulation years
     */
    public static function lifecycleSeries(
        float $initialNetWorth,
        float $accumulationReturnRatePercent,
        float $monthlySipAmount,
        float $sipStepUpRatePercent,
        int $yearsToRetirement,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $drawdownReturnRatePercent,
        int $decumulationHorizonYears
    ): array {
        $accumulation = self::accumulationSeries(
            $initialNetWorth,
            $accumulationReturnRatePercent,
            $monthlySipAmount,
            $sipStepUpRatePercent,
            $yearsToRetirement
        );
        $terminalCorpus = end($accumulation);

        $decumulation = self::steadyReturnSeries(
            $terminalCorpus,
            $withdrawalRatePercent,
            $inflationRatePercent,
            $drawdownReturnRatePercent,
            $decumulationHorizonYears
        );

        return array_merge($accumulation, array_slice($decumulation, 1));
    }

    /**
     * docs/05 item 3 / docs/06 "Corpus composition": decumulation across two
     * buckets with different return characteristics — liquid (market-linked,
     * readily accessible) and locked (EPF/NPS/PPF-style, restricted-access).
     * Each bucket compounds at its OWN return rate every year; the year's
     * withdrawal (same fixed-at-year-1, inflation-grown convention as
     * steadyReturnSeries) is drawn from the liquid bucket first, spilling
     * into the locked bucket only once liquid is exhausted. Once liquid hits
     * zero it stays there (0 compounds to 0); locked can go negative to
     * signal full depletion, same convention survivalFraction() already reads.
     *
     * This is a sequencing SIMPLIFICATION — "spend liquid first" — not a
     * model of any specific instrument's actual lock-in/maturity rules
     * (NPS/EPF/PPF withdrawal restrictions vary by instrument and age and are
     * not encoded here). Matches this class's existing posture: transparent,
     * deterministic arithmetic: never a hardcoded regulatory authority.
     *
     * @return float[] index 0 = starting TOTAL balance (liquid+locked), index
     *   n = combined balance after year n — same shape as steadyReturnSeries,
     *   so readinessScore()/the chart need no changes to consume it.
     */
    public static function twoBucketDecumulationSeries(
        float $liquidCorpus,
        float $lockedCorpus,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $liquidReturnRatePercent,
        float $lockedReturnRatePercent,
        int $horizonYears
    ): array {
        $annualWithdrawal = ($liquidCorpus + $lockedCorpus) * ($withdrawalRatePercent / 100.0);
        $inflation = $inflationRatePercent / 100.0;
        $liquidRate = $liquidReturnRatePercent / 100.0;
        $lockedRate = $lockedReturnRatePercent / 100.0;

        $liquid = $liquidCorpus;
        $locked = $lockedCorpus;
        $balances = [$liquid + $locked];

        for ($n = 1; $n <= $horizonYears; $n++) {
            $liquid *= (1 + $liquidRate);
            $locked *= (1 + $lockedRate);

            $withdrawalThisYear = $annualWithdrawal * (1 + $inflation) ** $n;
            if ($liquid >= $withdrawalThisYear) {
                $liquid -= $withdrawalThisYear;
            } else {
                $locked -= ($withdrawalThisYear - $liquid);
                $liquid = 0.0;
            }

            $balances[] = round($liquid + $locked, 2);
        }

        return $balances;
    }

    /**
     * Adverse-sequence counterpart to twoBucketDecumulationSeries() — same
     * "liquid first, then locked" withdrawal order, but each bucket gets its
     * OWN synthetic below/above-average return sequence (same construction
     * as adverseSequenceSeries: a fixed +/- spread around that bucket's own
     * average rate, sorted so weak years land early), since the two buckets
     * don't share a single average return to begin with.
     *
     * @return float[] same shape as twoBucketDecumulationSeries()
     */
    public static function twoBucketAdverseSequenceSeries(
        float $liquidCorpus,
        float $lockedCorpus,
        float $withdrawalRatePercent,
        float $inflationRatePercent,
        float $liquidReturnRatePercent,
        float $lockedReturnRatePercent,
        int $horizonYears
    ): array {
        $annualWithdrawal = ($liquidCorpus + $lockedCorpus) * ($withdrawalRatePercent / 100.0);
        $inflation = $inflationRatePercent / 100.0;

        $liquidReturns = self::adverseReturnSequence($liquidReturnRatePercent, $horizonYears);
        $lockedReturns = self::adverseReturnSequence($lockedReturnRatePercent, $horizonYears);

        $liquid = $liquidCorpus;
        $locked = $lockedCorpus;
        $balances = [$liquid + $locked];

        for ($n = 1; $n <= $horizonYears; $n++) {
            $liquid *= (1 + $liquidReturns[$n - 1] / 100.0);
            $locked *= (1 + $lockedReturns[$n - 1] / 100.0);

            $withdrawalThisYear = $annualWithdrawal * (1 + $inflation) ** $n;
            if ($liquid >= $withdrawalThisYear) {
                $liquid -= $withdrawalThisYear;
            } else {
                $locked -= ($withdrawalThisYear - $liquid);
                $liquid = 0.0;
            }

            $balances[] = round($liquid + $locked, 2);
        }

        return $balances;
    }

    /**
     * Builds a deterministic synthetic sequence of yearly returns whose
     * arithmetic mean equals $averageReturnRatePercent (a fixed +/- spread
     * alternating by year — not derived from historical volatility data,
     * this is explicitly a simple illustrative reordering per the spec, not
     * a backtest or Monte Carlo draw), then sorts it ascending so
     * below-average years land first. Used by adverseSequenceSeries() and
     * twoBucketAdverseSequenceSeries() (which needs it twice, once per
     * bucket, since the two buckets don't share a single average return).
     *
     * @return float[] length $horizonYears, ascending (worst years first)
     */
    private static function adverseReturnSequence(float $averageReturnRatePercent, int $horizonYears): array
    {
        $spreadPoints = 4.0;
        $rawReturns = [];
        for ($n = 1; $n <= $horizonYears; $n++) {
            $rawReturns[] = ($n % 2 === 1)
                ? $averageReturnRatePercent - $spreadPoints
                : $averageReturnRatePercent + $spreadPoints;
        }
        if ($horizonYears % 2 === 1 && $horizonYears > 0) {
            $rawReturns[$horizonYears - 1] = $averageReturnRatePercent;
        }
        sort($rawReturns);
        return $rawReturns;
    }
}
