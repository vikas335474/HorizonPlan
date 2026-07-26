<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/PlanMath.php';

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

// --- corpusMultiple ---
assertClose(PlanMath::corpusMultiple(3.5), 28.57, 'corpusMultiple(3.5%) ≈ 28.57x');
assertClose(PlanMath::corpusMultiple(4.0), 25.0, 'corpusMultiple(4%) = 25x (the US convention, for reference — not the MVP default)');
assertTrue(PlanMath::corpusMultiple(null) === null, 'corpusMultiple(null) returns null, not a divide-by-zero');
assertTrue(PlanMath::corpusMultiple(0.0) === null, 'corpusMultiple(0) returns null, not INF');

// --- steadyReturnSeries ---
$steady = PlanMath::steadyReturnSeries(10000000.0, 3.5, 6.0, 7.0, 30);
assertTrue(count($steady) === 31, 'steadyReturnSeries returns horizon+1 points (year 0 through year N)');
assertTrue($steady[0] === 10000000.0, 'steadyReturnSeries year 0 is the starting corpus, untouched');
// Year 1 by hand: withdrawal = 10,000,000 * 0.035 * 1.06 = 371,000
// balance = 10,000,000 * 1.07 - 371,000 = 10,700,000 - 371,000 = 10,329,000
assertClose($steady[1], 10329000.0, 'steadyReturnSeries year 1 matches the formula by hand', 1.0);

// --- adverseSequenceSeries ---
$adverse = PlanMath::adverseSequenceSeries(10000000.0, 3.5, 6.0, 7.0, 30);
assertTrue(count($adverse) === 31, 'adverseSequenceSeries returns horizon+1 points');
assertTrue($adverse[0] === 10000000.0, 'adverseSequenceSeries year 0 is the starting corpus, untouched');
// Sequence risk should bite: same average return, but bad years land early,
// so the adverse series should end up worse than the steady series by year 30
// for a scenario with a non-trivial withdrawal rate.
assertTrue(end($adverse) < end($steady), 'adverse-sequence ending balance is worse than the steady-return ending balance, same average return');

// --- sanity: a near-zero withdrawal rate should barely dent either series ---
$steadyLowWithdrawal = PlanMath::steadyReturnSeries(10000000.0, 0.01, 6.0, 7.0, 5);
assertTrue($steadyLowWithdrawal[5] > 10000000.0, 'a near-zero withdrawal rate still grows the corpus under a 7% return assumption');

// --- readinessScore (docs/07 Bet 3) ---
// 3.5% wr / 6% infl / 7% return / 30yr: steady never depletes (fraction 1.0),
// adverse depletes at year 23 (fraction 22/30), corpus multiple 28.57x ->
// multipleFactor (28.57-20)/20 = 0.4285. score = .4*1 + .4*(22/30) + .2*.4285 = 78.
assertTrue(PlanMath::readinessScore(3.5, $steady, $adverse) === 78, 'readinessScore blends steady/adverse survival with corpus-multiple band (3.5% wr -> 78)');

// Same series, no withdrawal rate supplied -> weights renormalize to 50/50
// steady/adverse (no multiple bonus/penalty): .5*1 + .5*(22/30) = 87.
assertTrue(PlanMath::readinessScore(null, $steady, $adverse) === 87, 'readinessScore renormalizes to 50/50 steady/adverse when withdrawal rate is unavailable');

// A depleting scenario (8% wr on a 4% return assumption) should score low —
// steady depletes at year 12 (fraction 11/30), adverse at year 10 (9/30),
// corpus multiple 12.5x is below the 20x-40x band so its factor clamps to 0.
$steadyDeplete = PlanMath::steadyReturnSeries(10000000.0, 8.0, 6.0, 4.0, 30);
$adverseDeplete = PlanMath::adverseSequenceSeries(10000000.0, 8.0, 6.0, 4.0, 30);
assertTrue(PlanMath::readinessScore(8.0, $steadyDeplete, $adverseDeplete) === 27, 'readinessScore scores a depleting plan low, with the below-band corpus multiple clamped to 0, not negative');

// A very conservative plan (2.5% wr, the bottom of the Indian-calibrated
// range -> 40x corpus multiple, the top of the clamp) that never depletes
// either series should hit the ceiling: 100.
$steadyStrong = PlanMath::steadyReturnSeries(10000000.0, 2.5, 6.0, 9.0, 30);
$adverseStrong = PlanMath::adverseSequenceSeries(10000000.0, 2.5, 6.0, 9.0, 30);
assertTrue(PlanMath::readinessScore(2.5, $steadyStrong, $adverseStrong) === 100, 'readinessScore reaches 100 when both series fully survive and the corpus multiple is at/above the clamp ceiling');

// A single-point series (horizon 0, just the starting balance) has no
// horizon to measure survival over -> null, not a division by zero.
assertTrue(PlanMath::readinessScore(3.5, [10000000.0], [10000000.0]) === null, 'readinessScore returns null when there is no horizon to measure survival over');

// --- decumulationSeriesForGoal / readinessScoreForGoal (advisor dashboard
// client-health signal — shares the same corpus-composition branch
// goals_projection.php uses, extracted so the two can't drift) ---
[$goalSteady, $goalAdverse] = PlanMath::decumulationSeriesForGoal(10000000.0, 3.5, 6.0, 7.0, 30);
assertTrue($goalSteady === $steady, 'decumulationSeriesForGoal with no corpus composition returns the same steady series as steadyReturnSeries directly');
assertTrue($goalAdverse === $adverse, 'decumulationSeriesForGoal with no corpus composition returns the same adverse series as adverseSequenceSeries directly');

[$goalSteadyTwoBucket, $goalAdverseTwoBucket] = PlanMath::decumulationSeriesForGoal(
    10000000.0, 4.0, 6.0, 7.0, 5, 6000000.0, 4000000.0, 6.0
);
assertTrue(
    $goalSteadyTwoBucket === PlanMath::twoBucketDecumulationSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5),
    'decumulationSeriesForGoal with a full liquid/locked split matches twoBucketDecumulationSeries directly'
);
assertTrue(
    $goalAdverseTwoBucket === PlanMath::twoBucketAdverseSequenceSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5),
    'decumulationSeriesForGoal with a full liquid/locked split matches twoBucketAdverseSequenceSeries directly'
);

// A partial composition (only liquidCorpusAmount set, lockedReturnRate
// missing) must NOT trip the two-bucket branch — same both-or-all-three
// guard as goals_projection.php's own hasCorpusComposition check.
[$goalSteadyPartial] = PlanMath::decumulationSeriesForGoal(10000000.0, 3.5, 6.0, 7.0, 30, 6000000.0, null, null);
assertTrue($goalSteadyPartial === $steady, 'decumulationSeriesForGoal falls back to the single-bucket series when the corpus composition is only partially set');

assertTrue(
    PlanMath::readinessScoreForGoal(10000000.0, 3.5, 6.0, 7.0, 30) === PlanMath::readinessScore(3.5, $steady, $adverse),
    'readinessScoreForGoal without composition matches readinessScore computed from the same standalone series'
);
assertTrue(
    PlanMath::readinessScoreForGoal(10000000.0, 4.0, 6.0, 7.0, 5, 6000000.0, 4000000.0, 6.0)
        === PlanMath::readinessScore(4.0, PlanMath::twoBucketDecumulationSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5), PlanMath::twoBucketAdverseSequenceSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5)),
    'readinessScoreForGoal with composition matches readinessScore computed from the two-bucket series'
);

// --- historicalSequenceSeries (docs/07 Bet 2) ---
// A 3-year mock history: 2000 (+10% equity, 5% inflation), 2001 (-10%, 3%),
// 2002 (+20%, 2%). All values below hand-verified: annualWithdrawal =
// 1,000,000 * 4% = 40,000; withdrawal each year = 40,000 * cumulative
// historical inflation (compounded, not a flat assumed rate).
$mockHistory = [
    ['year' => 2000, 'equity_return_pct' => 10.0, 'cpi_inflation_pct' => 5.0],
    ['year' => 2001, 'equity_return_pct' => -10.0, 'cpi_inflation_pct' => 3.0],
    ['year' => 2002, 'equity_return_pct' => 20.0, 'cpi_inflation_pct' => 2.0],
];

$hist3 = PlanMath::historicalSequenceSeries(1000000.0, 4.0, $mockHistory, 2000, 3);
assertTrue(count($hist3) === 4, 'historicalSequenceSeries returns horizon+1 points for an exact-length replay');
// Year 1: withdrawal = 40,000*1.05 = 42,000; balance = 1,000,000*1.10 - 42,000 = 1,058,000
assertClose($hist3[1], 1058000.0, 'historicalSequenceSeries year 1 matches the formula by hand', 1.0);
// Year 2: cumulative infl 1.05*1.03=1.0815; withdrawal=43,260; balance=1,058,000*0.90-43,260=908,940
assertClose($hist3[2], 908940.0, 'historicalSequenceSeries year 2 uses the actual historical (negative) return and compounded inflation', 1.0);
assertClose($hist3[3], 1046602.8, 'historicalSequenceSeries year 3 matches the formula by hand', 1.0);

// Replaying 5 years from a 3-year history must wrap back to the earliest
// year rather than fall back to a flat assumed rate — years 4-5 replay
// 2000/2001 again, continuing the SAME compounded-inflation trajectory.
$hist5 = PlanMath::historicalSequenceSeries(1000000.0, 4.0, $mockHistory, 2000, 5);
assertTrue(count($hist5) === 6, 'historicalSequenceSeries returns horizon+1 points even when it must wrap');
assertTrue(
    $hist5[3] === $hist3[3],
    'historicalSequenceSeries agrees with the non-wrapped series for the years before the wrap point'
);
assertClose($hist5[4], 1104931.62, 'historicalSequenceSeries wraps to the earliest year (2000 again) and keeps compounding inflation forward, not resetting it', 1.0);
assertClose($hist5[5], 946717.05, 'historicalSequenceSeries wrap-around year 5 (2001 again) matches the formula by hand', 1.0);

// Starting mid-history (2001) begins at that year's index, not year 0 of the array.
$histMidStart = PlanMath::historicalSequenceSeries(1000000.0, 4.0, $mockHistory, 2001, 2);
assertClose($histMidStart[1], 858800.0, 'historicalSequenceSeries starting mid-array begins at the requested year, not index 0');
assertClose($histMidStart[2], 988536.0, 'historicalSequenceSeries starting mid-array continues in year order after that', 1.0);

// A start year that isn't in the supplied history (or an empty history)
// returns just the starting balance — the endpoint is expected to validate
// the year against market_history_years.php before calling this.
assertTrue(
    PlanMath::historicalSequenceSeries(1000000.0, 4.0, $mockHistory, 1999, 3) === [1000000.0],
    'historicalSequenceSeries returns just the starting balance for a year not present in the supplied history'
);
assertTrue(
    PlanMath::historicalSequenceSeries(1000000.0, 4.0, [], 2000, 3) === [1000000.0],
    'historicalSequenceSeries returns just the starting balance for an empty history array'
);

echo "\nAll PlanMath tests passed.\n";
