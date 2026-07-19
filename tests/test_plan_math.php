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

echo "\nAll PlanMath tests passed.\n";
