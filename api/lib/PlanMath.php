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
     * P1-1 · What the plan expects a goal's corpus to be after $yearsElapsed
     * years — the "expected" line a progress snapshot is measured against.
     *
     * This is not new arithmetic: it picks the series the goal already
     * projects with and reads the point off it.
     *
     *   * A goal with accumulation set (ages + an accumulation return) is
     *     still saving, so its expected path is lifecycleSeries() — the
     *     accumulation run-up flowing into drawdown, the same continuous
     *     curve goals_projection.php returns.
     *   * A decumulation-only goal is already spending down, so its expected
     *     path is the steady decumulation series.
     *   * Anything that cannot be projected at all (a target-based goal,
     *     which carries no return assumption) returns null — those measure
     *     progress by funding coverage instead, never by an invented curve.
     *
     * Fractional years are linearly interpolated between the two surrounding
     * annual points. The underlying series are annual, so a mid-year snapshot
     * has no exact point of its own; a straight line between neighbours is
     * the honest reading of an annual model rather than a pretence of
     * monthly precision.
     *
     * @return float|null expected corpus, or null when the goal can't be projected
     */
    public static function expectedCorpusAfter(
        float $yearsElapsed,
        float $initialNetWorth,
        ?float $withdrawalRatePercent,
        float $inflationPercent,
        ?float $drawdownReturnPercent,
        int $horizonYears,
        ?int $currentAge = null,
        ?int $retirementAge = null,
        ?float $accumulationReturnPercent = null,
        ?float $monthlySipAmount = null,
        ?float $sipStepUpPercent = null
    ): ?float {
        if ($withdrawalRatePercent === null || $drawdownReturnPercent === null) {
            return null; // not projectable — see the docblock above
        }
        if ($yearsElapsed < 0.0) {
            return null;
        }

        $isAccumulating = $currentAge !== null
            && $retirementAge !== null
            && $accumulationReturnPercent !== null
            && $retirementAge > $currentAge;

        if ($isAccumulating) {
            $series = self::lifecycleSeries(
                $initialNetWorth,
                $accumulationReturnPercent,
                $monthlySipAmount ?? 0.0,
                $sipStepUpPercent ?? 0.0,
                $retirementAge - $currentAge,
                $withdrawalRatePercent,
                $inflationPercent,
                $drawdownReturnPercent,
                $horizonYears
            );
        } else {
            $series = self::steadyReturnSeries(
                $initialNetWorth,
                $withdrawalRatePercent,
                $inflationPercent,
                $drawdownReturnPercent,
                $horizonYears
            );
        }

        if ($series === []) {
            return null;
        }

        $lastIndex = count($series) - 1;
        // Past the end of the modelled horizon there is no expectation left to
        // compare against — clamp to the final modelled point rather than
        // extrapolating a curve the plan never described.
        if ($yearsElapsed >= $lastIndex) {
            return (float) $series[$lastIndex];
        }

        $lower = (int) floor($yearsElapsed);
        $upper = $lower + 1;
        $fraction = $yearsElapsed - $lower;

        return (float) $series[$lower] + (((float) $series[$upper] - (float) $series[$lower]) * $fraction);
    }

    /**
     * P1-1 · Classify actual-vs-expected into a status an advisor can scan.
     *
     * The ±$tolerancePercent band (default 5%) is a deliberate DEAD ZONE, not
     * a precision claim: the underlying model is annual and assumption-driven,
     * so calling a goal "behind" because it is 0.4% under the curve would be
     * noise dressed as signal. Only a drift big enough to be worth a
     * conversation changes the label.
     *
     * Returns null when there is nothing to compare against (no expected
     * value), so callers render "not tracked" rather than a false "on track".
     *
     * @return array{status: string, drift: float, drift_pct: float}|null
     *         status is one of 'ahead' | 'on_track' | 'behind'
     */
    public static function progressStatus(
        float $actual,
        ?float $expected,
        float $tolerancePercent = 5.0
    ): ?array {
        if ($expected === null) {
            return null;
        }

        $drift = $actual - $expected;

        // A zero/negative expectation (a plan modelled to be fully drawn down
        // by now) has no meaningful percentage to divide by — report the
        // rupee drift and treat anything at or above the line as on track.
        if ($expected <= 0.0) {
            return [
                'status'    => $drift >= 0.0 ? 'on_track' : 'behind',
                'drift'     => round($drift, 2),
                'drift_pct' => 0.0,
            ];
        }

        $driftPct = ($drift / $expected) * 100.0;

        if ($driftPct > $tolerancePercent) {
            $status = 'ahead';
        } elseif ($driftPct < -$tolerancePercent) {
            $status = 'behind';
        } else {
            $status = 'on_track';
        }

        return [
            'status'    => $status,
            'drift'     => round($drift, 2),
            'drift_pct' => round($driftPct, 1),
        ];
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
        // An empty goal has no readiness to report. Withdrawing a percentage
        // of nothing is nothing, so a zero corpus "survives" every year
        // untouched and scores near the top — telling someone who has not
        // started saving that they are in excellent shape. That is the most
        // misleading output this function could produce, so it is refused
        // outright: null renders as "not scored yet", which is the truth.
        //
        // Reachable since initial_net_worth accepts 0 (a goal you have not
        // started saving for is a real state — see
        // GoalFieldValidation::validateNonNegativeAmount).
        if ($initialNetWorth <= 0.0) {
            return null;
        }

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
    /**
     * "How much will I need, and am I on track for it?" — the two numbers a
     * person planning for themselves actually asks for, which nothing in this
     * app previously computed.
     *
     * The advisor product never needed this because an advisor arrives with a
     * target already in mind and enters a corpus to model. Someone planning
     * alone has no target: they know what they spend today, and need the app to
     * turn that into a number for a year that is decades away.
     *
     * THE ARITHMETIC, all of it visible so nothing is smuggled in:
     *   1. today's annual spend, inflated to the retirement year;
     *   2. the corpus that sustains it at the plan's own withdrawal rate
     *      (spend ÷ rate — the same corpus-multiple mechanic as
     *      corpusMultiple(), docs/02 §4.2);
     *   3. what the plan is actually projected to reach by then, read off the
     *      lifecycle series the goal already projects with — not a second,
     *      subtly different curve.
     *
     * THE ASSUMPTION THIS MAKES, and callers MUST show it rather than bury it:
     * that the person will spend in retirement roughly what they spend now,
     * adjusted for inflation. That is the standard planning starting point and
     * it is also frequently wrong for an individual (a paid-off home, grown
     * children, higher medical costs). Presenting the output as a fact rather
     * than as the consequence of a stated assumption would be the dishonest
     * version of this feature.
     *
     * Returns NULL when the inputs to state it honestly are missing —
     * specifically when no monthly expense figure has been recorded. There is
     * no defensible way to invent someone's cost of living, and a guessed
     * target is worse than no target: it would anchor a real decision.
     *
     * docs/11 Prompt E-2 adds two OPTIONAL, purely additive refinements — both
     * default to a no-op so every existing caller (and every goal where the
     * person hasn't answered these questions) sees byte-for-byte the same
     * result as before:
     *
     *   $additionalMonthlyExpense — an ongoing medical cost not already in the
     *   person's cash-flow statement (docs/11 §5: the financial CONSEQUENCE,
     *   never a condition). Added to today's spend before inflating, exactly
     *   like any other recorded monthly expense would be.
     *
     *   $cityTierMultiplier — scales the RETIREMENT-YEAR spend only, never
     *   today's recorded figure and never the inflation rate (docs/11 §3's
     *   non-obvious rule). Represents "I expect to be living somewhere
     *   cheaper/costlier by then" — e.g. relocating to a smaller town in
     *   retirement — which is a real and common scenario this app had no way
     *   to reflect otherwise.
     *
     * @param float $monthlyExpensesToday total recorded monthly spend, 0 if unknown
     * @param float $additionalMonthlyExpense docs/11 E-2: recorded ongoing medical cost, added before inflating. 0.0 = no-op.
     * @param float $cityTierMultiplier docs/11 E-2: scales the retirement-year spend only. 1.0 = no-op.
     * @return array{annual_spend_at_retirement:float,corpus_needed:float,projected_corpus:float,gap:float,covered_pct:float,years_to_retirement:int}|null
     */
    public static function retirementTarget(
        float $monthlyExpensesToday,
        float $inflationRatePercent,
        ?float $withdrawalRatePercent,
        int $yearsToRetirement,
        float $projectedCorpusAtRetirement,
        float $additionalMonthlyExpense = 0.0,
        float $cityTierMultiplier = 1.0
    ): ?array {
        $effectiveMonthlyExpenses = $monthlyExpensesToday + max(0.0, $additionalMonthlyExpense);
        if ($effectiveMonthlyExpenses <= 0.0 || $yearsToRetirement < 0) {
            return null;
        }
        if ($withdrawalRatePercent === null || $withdrawalRatePercent <= 0.0) {
            return null; // no sustainable-withdrawal assumption → no corpus to name
        }

        $multiplier = $cityTierMultiplier > 0.0 ? $cityTierMultiplier : 1.0;
        $inflation = max(0.0, $inflationRatePercent) / 100.0;
        $annualSpendAtRetirement = $effectiveMonthlyExpenses * 12.0 * ((1.0 + $inflation) ** $yearsToRetirement) * $multiplier;
        $corpusNeeded = $annualSpendAtRetirement / ($withdrawalRatePercent / 100.0);

        $projected = max(0.0, $projectedCorpusAtRetirement);

        return [
            'annual_spend_at_retirement' => round($annualSpendAtRetirement, 2),
            'corpus_needed'              => round($corpusNeeded, 2),
            'projected_corpus'           => round($projected, 2),
            'gap'                        => round($projected - $corpusNeeded, 2),
            'covered_pct'                => $corpusNeeded > 0.0 ? round(($projected / $corpusNeeded) * 100.0, 1) : 0.0,
            'years_to_retirement'        => $yearsToRetirement,
        ];
    }

    /**
     * docs/11 Prompt E-1: "never pair a gap with silence." Given a
     * retirementTarget() result that shows a shortfall, name the smallest
     * concrete lever that closes it — never render the gap on its own.
     *
     * Two independent levers, both solved with the same accumulation
     * arithmetic the goal already projects with (accumulationSeries()):
     *
     *   (i)  EXTRA MONTHLY SIP — the flat additional monthly amount that,
     *        invested for the remaining years to retirement at the plan's
     *        own accumulation return, closes the shortfall by itself. Held
     *        FLAT (no step-up) deliberately: the goal's existing SIP already
     *        carries its own step-up, and stacking a second stepped series on
     *        top would make the "how much more" number depend on a modelling
     *        choice instead of being the one clean unknown it should be.
     *        Standard future-value-of-an-ordinary-annuity solve:
     *        shortfall = extraAnnualSip * ((1+r)^n - 1) / r.
     *
     *   (ii) DEFERRAL YEARS — how many additional years of working (and
     *        saving, at the SAME sip/step-up) closes it. This is NOT solved
     *        with a formula — deferring moves both sides of the target (the
     *        corpus needed inflates further out, the projected corpus gets
     *        more years to compound and step up), so this recomputes
     *        retirementTarget() at each additional year, using the exact same
     *        two methods the caller already used, until the gap closes or
     *        $maxDeferralYears is reached. Returns null for this lever if it
     *        doesn't close within that cap — waiting alone isn't a realistic
     *        lever for every shortfall, and null says so rather than a
     *        misleadingly large number.
     *
     * "Show whichever is smaller first" (docs/11) can't compare rupees to
     * years directly, so each lever is also expressed relative to what the
     * goal already has — extra SIP as a fraction of the current SIP, deferral
     * as a fraction of the years already planned. Whichever asks for the
     * smaller relative change is named 'smaller_lever'; a lever that could not
     * be solved is never picked.
     *
     * @param array $target retirementTarget()'s result — must be non-null.
     *   Callers should not call this when $target['gap'] >= 0: there is
     *   nothing to close, and this returns null in that case rather than a
     *   zero-value lever that would read as an assumption a person hit their
     *   number exactly. Refuses to render a distinguished shape either way.
     * @param float $additionalMonthlyExpense docs/11 E-2: forwarded unchanged into
     *   every recomputed retirementTarget() so the deferral search closes
     *   against the SAME effective target the caller is showing, not a
     *   version that quietly drops the medical-cost/city-tier refinements.
     * @param float $cityTierMultiplier docs/11 E-2: see $additionalMonthlyExpense.
     */
    public static function gapClosingLevers(
        array $target,
        float $initialNetWorth,
        float $accumulationReturnRatePercent,
        float $monthlySipAmount,
        float $sipStepUpRatePercent,
        int $yearsToRetirement,
        float $monthlyExpensesToday,
        float $inflationRatePercent,
        float $withdrawalRatePercent,
        int $maxDeferralYears = 40,
        float $additionalMonthlyExpense = 0.0,
        float $cityTierMultiplier = 1.0
    ): ?array {
        $gap = (float) ($target['gap'] ?? 0.0);
        if ($gap >= 0.0) {
            return null; // already on track — no lever to name
        }
        $shortfall = -$gap;

        // (i) Extra monthly SIP, solved as a flat ordinary annuity against the
        // remaining years to retirement. Not solvable when there are no years
        // left to save into (retiring this year or already past it).
        $extraMonthlySip = null;
        if ($yearsToRetirement > 0) {
            $r = $accumulationReturnRatePercent / 100.0;
            $n = $yearsToRetirement;
            $annuityFactor = $r > 0.0 ? ((1.0 + $r) ** $n - 1.0) / $r : (float) $n;
            if ($annuityFactor > 0.0) {
                $extraAnnualSip = $shortfall / $annuityFactor;
                $extraMonthlySip = round($extraAnnualSip / 12.0, 2);
            }
        }

        // (ii) Deferral years — recompute the same target, one year further
        // out at a time, using the goal's own accumulation series and its own
        // withdrawal rate, until the gap closes.
        $deferralYears = null;
        for ($d = 1; $d <= $maxDeferralYears; $d++) {
            $laterYears = $yearsToRetirement + $d;
            $laterAccumulation = self::accumulationSeries(
                $initialNetWorth, $accumulationReturnRatePercent, $monthlySipAmount, $sipStepUpRatePercent, $laterYears
            );
            $laterProjected = (float) end($laterAccumulation);
            $laterTarget = self::retirementTarget(
                $monthlyExpensesToday, $inflationRatePercent, $withdrawalRatePercent, $laterYears, $laterProjected,
                $additionalMonthlyExpense, $cityTierMultiplier
            );
            if ($laterTarget !== null && $laterTarget['gap'] >= 0.0) {
                $deferralYears = $d;
                break;
            }
        }

        // Relative size of each ask, for "show whichever is smaller first".
        // A lever that couldn't be solved is never the smaller one.
        $relativeSip = ($extraMonthlySip !== null && $monthlySipAmount > 0.0)
            ? $extraMonthlySip / $monthlySipAmount
            : ($extraMonthlySip !== null ? INF : null);
        $relativeDeferral = ($deferralYears !== null && $yearsToRetirement > 0)
            ? $deferralYears / $yearsToRetirement
            : ($deferralYears !== null ? INF : null);

        $smallerLever = null;
        if ($relativeSip !== null && $relativeDeferral !== null) {
            $smallerLever = $relativeSip <= $relativeDeferral ? 'sip' : 'deferral';
        } elseif ($relativeSip !== null) {
            $smallerLever = 'sip';
        } elseif ($relativeDeferral !== null) {
            $smallerLever = 'deferral';
        }

        return [
            'extra_monthly_sip' => $extraMonthlySip,
            'deferral_years'    => $deferralYears,
            'smaller_lever'     => $smallerLever,
        ];
    }

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
