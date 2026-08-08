<?php
declare(strict_types=1);

// P1-1 · Pure tests for the goal-progress arithmetic:
// PlanMath::expectedCorpusAfter() (the "what the plan expected" line) and
// PlanMath::progressStatus() (turning actual-vs-expected into a label).
//
// Both are asserted against the series the goal already projects with, so a
// regression in one shows up here rather than silently shifting every
// advisor's drift readings.

require_once __DIR__ . '/../api/lib/PlanMath.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

function assertClose(float $a, float $b, string $label, float $tolerance = 0.51): void
{
    assertTrue(abs($a - $b) < $tolerance, "$label (got $a, expected ~$b)");
}

// --- expectedCorpusAfter: not projectable ----------------------------------
assertTrue(
    PlanMath::expectedCorpusAfter(3.0, 1000000.0, null, 6.0, 8.0, 30) === null,
    'no withdrawal rate → null (a target-based goal has no expected curve at all)'
);
assertTrue(
    PlanMath::expectedCorpusAfter(3.0, 1000000.0, 4.0, 6.0, null, 30) === null,
    'no drawdown return → null'
);
assertTrue(
    PlanMath::expectedCorpusAfter(-1.0, 1000000.0, 4.0, 6.0, 8.0, 30) === null,
    'a negative elapsed time has no expectation'
);

// --- expectedCorpusAfter: decumulation-only goal ----------------------------
// It must read points straight off the steady decumulation series the goal
// already projects with — not a second, subtly different curve.
$steady = PlanMath::steadyReturnSeries(10000000.0, 4.0, 6.0, 8.0, 30);
assertClose(
    PlanMath::expectedCorpusAfter(0.0, 10000000.0, 4.0, 6.0, 8.0, 30),
    $steady[0],
    'year 0 expectation is the starting corpus'
);
assertClose(
    PlanMath::expectedCorpusAfter(5.0, 10000000.0, 4.0, 6.0, 8.0, 30),
    $steady[5],
    'a whole-year expectation matches that year on the steady series exactly'
);
// Mid-year interpolation sits strictly between its two annual neighbours.
$mid = PlanMath::expectedCorpusAfter(5.5, 10000000.0, 4.0, 6.0, 8.0, 30);
$lo = min($steady[5], $steady[6]);
$hi = max($steady[5], $steady[6]);
assertTrue($mid > $lo && $mid < $hi, 'a mid-year expectation interpolates strictly between its two annual neighbours');
assertClose($mid, ($steady[5] + $steady[6]) / 2, 'the half-year point is the midpoint of the two neighbours (linear interpolation)');

// Past the modelled horizon it clamps rather than extrapolating.
assertClose(
    PlanMath::expectedCorpusAfter(99.0, 10000000.0, 4.0, 6.0, 8.0, 30),
    $steady[30],
    'beyond the horizon the expectation clamps to the last modelled point, it does not extrapolate'
);

// --- expectedCorpusAfter: accumulating goal --------------------------------
// A goal still saving must follow the LIFECYCLE curve (accumulation into
// drawdown), not the decumulation-only one — otherwise every pre-retirement
// client would read as wildly "ahead" of a curve that assumes they're
// already spending down.
$life = PlanMath::lifecycleSeries(1000000.0, 10.0, 20000.0, 5.0, 20, 4.0, 6.0, 8.0, 30);
assertClose(
    PlanMath::expectedCorpusAfter(5.0, 1000000.0, 4.0, 6.0, 8.0, 30, 40, 60, 10.0, 20000.0, 5.0),
    $life[5],
    'an accumulating goal reads off the lifecycle series'
);
$accumulating = PlanMath::expectedCorpusAfter(5.0, 1000000.0, 4.0, 6.0, 8.0, 30, 40, 60, 10.0, 20000.0, 5.0);
$decumulating = PlanMath::expectedCorpusAfter(5.0, 1000000.0, 4.0, 6.0, 8.0, 30);
assertTrue(
    $accumulating > $decumulating,
    'a still-saving goal expects MORE at year 5 than the same goal modelled as already drawing down'
);

// An inverted age range (retirement before current age) is not "accumulating"
// and must fall back to the decumulation curve rather than producing nonsense.
assertClose(
    PlanMath::expectedCorpusAfter(5.0, 10000000.0, 4.0, 6.0, 8.0, 30, 65, 60, 10.0, 20000.0, 5.0),
    $steady[5],
    'an inverted age range falls back to the decumulation curve, not a negative accumulation run'
);

// --- progressStatus ---------------------------------------------------------
assertTrue(PlanMath::progressStatus(100.0, null) === null, 'no expected value → null status (renders "not tracked", never a false "on track")');

$s = PlanMath::progressStatus(1000000.0, 1000000.0);
assertTrue($s['status'] === 'on_track', 'exactly on the line is on_track');
assertClose($s['drift'], 0.0, 'no drift when actual equals expected');

// The ±5% band is a dead zone: inside it, nothing is called.
assertTrue(PlanMath::progressStatus(1040000.0, 1000000.0)['status'] === 'on_track', '+4% is still on_track — inside the tolerance band');
assertTrue(PlanMath::progressStatus(960000.0, 1000000.0)['status'] === 'on_track', '-4% is still on_track — inside the tolerance band');
assertTrue(PlanMath::progressStatus(1060000.0, 1000000.0)['status'] === 'ahead', '+6% clears the band and reads ahead');
assertTrue(PlanMath::progressStatus(940000.0, 1000000.0)['status'] === 'behind', '-6% clears the band and reads behind');

$b = PlanMath::progressStatus(900000.0, 1000000.0);
assertClose($b['drift'], -100000.0, 'drift is reported in rupees');
assertClose($b['drift_pct'], -10.0, 'drift_pct is relative to the expectation');

// A custom tolerance widens/narrows the dead zone.
assertTrue(PlanMath::progressStatus(940000.0, 1000000.0, 10.0)['status'] === 'on_track', 'a wider tolerance absorbs the same -6% drift');

// A fully-drawn-down expectation has no percentage to divide by.
$z = PlanMath::progressStatus(50000.0, 0.0);
assertTrue($z['status'] === 'on_track', 'money left against a zero expectation is on_track, not a division by zero');
assertClose($z['drift'], 50000.0, 'the rupee drift is still reported against a zero expectation');
$zn = PlanMath::progressStatus(-1000.0, 0.0);
assertTrue($zn['status'] === 'behind', 'being under a zero expectation reads behind');

// --- an empty goal has no readiness ----------------------------------------
// Reachable now that initial_net_worth accepts 0. Withdrawing a percentage of
// nothing depletes nothing, so the raw survival maths scores a zero-corpus
// goal near the top — the single most misleading number this app could show
// someone who has not started saving.
assertTrue(
    PlanMath::readinessScoreForGoal(0.0, 3.5, 6.0, 9.0, 30) === null,
    'a zero-corpus goal returns NO readiness score, not a flattering one'
);
assertTrue(
    PlanMath::readinessScoreForGoal(10000000.0, 3.5, 6.0, 9.0, 30) !== null,
    'a funded goal still scores normally'
);

echo "\nAll goal-progress tests passed.\n";
