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

echo "\nAll PlanMath tests passed.\n";
