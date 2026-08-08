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

// --- retirementTarget: "how much will I need, am I on track?" ---------------
// The consumer question the advisor product never had to answer, because an
// advisor arrives with a target already in mind.

// No recorded spend → no target. A guessed cost of living would anchor a real
// decision, so this must stay null rather than invent one.
assertTrue(
    PlanMath::retirementTarget(0.0, 6.0, 3.5, 20, 50000000.0) === null,
    'no recorded monthly spend → NO target, rather than a guessed one'
);
assertTrue(
    PlanMath::retirementTarget(60000.0, 6.0, null, 20, 50000000.0) === null,
    'no withdrawal-rate assumption → no corpus can be named'
);
assertTrue(
    PlanMath::retirementTarget(60000.0, 6.0, 0.0, 20, 50000000.0) === null,
    'a zero withdrawal rate would divide by zero → null, not infinity'
);

// 60k/month today, 6% inflation, 20 years, 3.5% withdrawal.
// Annual today = 720000; inflated = 720000 * 1.06^20 = 2,308,977.
// Needed = that / 0.035 = 65,970,776.
$t = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0);
assertClose($t['annual_spend_at_retirement'], 2308977.0, "today's spend is inflated to the retirement year", 2000.0);
assertClose($t['corpus_needed'], 65970776.0, 'the corpus needed is that spend at the plan\'s own withdrawal rate', 60000.0);
assertClose($t['gap'], 50000000.0 - $t['corpus_needed'], 'the gap is projected minus needed');
assertTrue($t['gap'] < 0, 'a 5cr projection against a 6.6cr need reads as a shortfall');
assertClose($t['covered_pct'], 75.8, 'coverage is reported as a percentage of the target', 0.6);

// Retiring today: no inflation applied at all.
$now = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 0, 30000000.0);
assertClose($now['annual_spend_at_retirement'], 720000.0, 'with zero years to go, spend is not inflated');
assertClose($now['corpus_needed'], 720000.0 / 0.035, 'and the corpus needed is simply this year\'s spend over the rate');

// A funded plan reports a positive gap rather than clamping at the target.
$funded = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 90000000.0);
assertTrue($funded['gap'] > 0, 'a plan ahead of its target reports the surplus, not a capped 100%');
assertTrue($funded['covered_pct'] > 100.0, 'and coverage above 100% is reported honestly');

// --- gapClosingLevers: docs/11 E-1 "never pair a gap with silence" ---------

// A gap that's already closed (or ahead) gets no lever at all — there's
// nothing to close, and a zero-value lever would misread as "hit it exactly".
assertTrue(
    PlanMath::gapClosingLevers($funded, 100000.0, 8.0, 5000.0, 10.0, 20, 60000.0, 6.0, 3.5) === null,
    'a funded target (gap >= 0) gets no lever — nothing to close'
);

// A self-consistent shortfall scenario: the SAME accumulation params
// (100,000 start, 8% return, 5,000/month SIP, 10% step-up, 20 years) both
// produce the projected corpus fed into the target AND get passed to
// gapClosingLevers(), so the levers are checked against the plan they claim
// to describe rather than an arbitrary hardcoded corpus.
$leverAcc = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 20);
$leverBase = (float) end($leverAcc);
$leverTarget = PlanMath::retirementTarget(20000.0, 6.0, 4.0, 20, $leverBase);
assertTrue($leverTarget['gap'] < 0, 'the scenario is set up to have a real shortfall');

$levers = PlanMath::gapClosingLevers($leverTarget, 100000.0, 8.0, 5000.0, 10.0, 20, 20000.0, 6.0, 4.0);
assertTrue($levers !== null, 'a real shortfall always produces a levers array, not null');
assertTrue($levers['extra_monthly_sip'] > 0, 'extra_monthly_sip is a positive rupee amount');

// extra_monthly_sip is solved as a FLAT ordinary annuity (deliberately not
// re-stepped, see PlanMath::gapClosingLevers docblock), so its future value at
// the plan's own accumulation return over the remaining years must land
// exactly on the shortfall it was solved to close — checked with the same
// annuity formula, independent of accumulationSeries() (which would
// incorrectly re-apply the step-up to it).
$r = 0.08;
$n = 20;
$annuityFactor = ((1 + $r) ** $n - 1) / $r;
$extraFv = ($levers['extra_monthly_sip'] * 12.0) * $annuityFactor;
assertClose($extraFv, -$leverTarget['gap'], 'extra_monthly_sip\'s future value closes exactly the shortfall it was solved from', 50.0);

assertTrue($levers['deferral_years'] !== null && $levers['deferral_years'] > 0, 'this shortfall is closeable by deferral within the cap');
$laterAcc = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 20 + $levers['deferral_years']);
$laterTarget = PlanMath::retirementTarget(20000.0, 6.0, 4.0, 20 + $levers['deferral_years'], (float) end($laterAcc));
assertTrue($laterTarget['gap'] >= 0.0, 'deferral_years actually closes the shortfall when the goal runs that many years longer');
if ($levers['deferral_years'] > 1) {
    $oneLessAcc = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 20 + $levers['deferral_years'] - 1);
    $oneLessTarget = PlanMath::retirementTarget(20000.0, 6.0, 4.0, 20 + $levers['deferral_years'] - 1, (float) end($oneLessAcc));
    assertTrue($oneLessTarget['gap'] < 0.0, 'deferral_years is the SMALLEST year count that closes it, not just any that does');
}
assertTrue(
    in_array($levers['smaller_lever'], ['sip', 'deferral'], true),
    'smaller_lever names one of the two solvable levers'
);

// Zero years to retirement (retiring today), WITH a real shortfall — the SIP
// lever has no years left to invest into, so it must come back null rather
// than a divide-by-zero or nonsensical amount. Deferral is still solvable
// independently, since it recomputes accumulationSeries() at a later horizon
// regardless of how many years the goal currently has left.
$todayTarget = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 0, 10000000.0);
assertTrue($todayTarget['gap'] < 0.0, 'retiring today with an under-funded corpus is a real shortfall');
$todayLevers = PlanMath::gapClosingLevers($todayTarget, 10000000.0, 8.0, 5000.0, 10.0, 0, 60000.0, 6.0, 3.5);
assertTrue($todayLevers['extra_monthly_sip'] === null, 'no years left to retirement -> extra_monthly_sip is unsolvable, not a garbage number');
assertTrue($todayLevers['deferral_years'] > 0, 'deferral is still solvable even when the SIP lever is not');
assertTrue($todayLevers['smaller_lever'] === 'deferral', 'with the SIP lever unsolvable, deferral is the only one left to pick');

// --- docs/11 Prompt E-2: the two personalisation refinements on retirementTarget ---

// Both new params default to a no-op, so every call above (none of which
// passed them) is unaffected — checked directly here.
$plainT = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0);
$explicitNoOpT = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0, 0.0, 1.0);
assertClose($plainT['corpus_needed'], $explicitNoOpT['corpus_needed'], 'explicit no-op params reproduce the same result as omitting them');

// Additional monthly expense (an ongoing medical cost) is added to today's
// spend BEFORE inflating — so 60k + 10k = 70k/month is what actually compounds.
$withMedical = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0, 10000.0);
$equivalent = PlanMath::retirementTarget(70000.0, 6.0, 3.5, 20, 50000000.0);
assertClose($withMedical['annual_spend_at_retirement'], $equivalent['annual_spend_at_retirement'], 'an additional monthly expense is simply added to recorded spend before inflating');

// A recorded medical cost alone (zero other expenses) still produces a target
// — the effective monthly figure, not the raw recorded one, gates the null.
$medicalOnly = PlanMath::retirementTarget(0.0, 6.0, 3.5, 20, 50000000.0, 15000.0);
assertTrue($medicalOnly !== null, 'a medical cost alone is enough to produce a target, even with zero recorded cash-flow expenses');

// City-tier multiplier scales ONLY the retirement-year figure, never today's
// recorded spend and never the inflation rate — checked by comparing against
// hand-scaling the no-multiplier result's inflated spend.
$noTier = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0);
$smallerTier = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0, 0.0, 0.85);
assertClose($smallerTier['annual_spend_at_retirement'], $noTier['annual_spend_at_retirement'] * 0.85, 'the city-tier multiplier scales the retirement-year spend by exactly the given factor');
assertClose($smallerTier['corpus_needed'], $noTier['corpus_needed'] * 0.85, 'and therefore the corpus needed scales by the same factor');

// A non-positive multiplier is treated as 1.0 (never a divide-by-zero or a
// negative "need") — a defensive floor, not a real input this app ever sends.
$zeroTier = PlanMath::retirementTarget(60000.0, 6.0, 3.5, 20, 50000000.0, 0.0, 0.0);
assertClose($zeroTier['annual_spend_at_retirement'], $noTier['annual_spend_at_retirement'], 'a zero/invalid multiplier falls back to no adjustment rather than zeroing the target');

// gapClosingLevers threads BOTH new params into its own recompute loop, so the
// deferral search closes against the SAME effective target it was handed —
// not a version that silently drops the refinements.
$refinedTarget = PlanMath::retirementTarget(20000.0, 6.0, 4.0, 20, $leverBase, 5000.0, 1.1);
assertTrue($refinedTarget['gap'] < 0.0, 'the refined scenario (medical cost + a costlier city tier) is still set up as a real shortfall');
$refinedLevers = PlanMath::gapClosingLevers(
    $refinedTarget, 100000.0, 8.0, 5000.0, 10.0, 20, 20000.0, 6.0, 4.0, 40, 5000.0, 1.1
);
assertTrue($refinedLevers !== null, 'a refined shortfall still produces levers');
$refinedLaterAcc = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 20 + $refinedLevers['deferral_years']);
$refinedLaterTarget = PlanMath::retirementTarget(
    20000.0, 6.0, 4.0, 20 + $refinedLevers['deferral_years'], (float) end($refinedLaterAcc), 5000.0, 1.1
);
assertTrue($refinedLaterTarget['gap'] >= 0.0, 'the deferral lever closes the gap when recomputed with the SAME refinements it was solved under');

echo "\nAll goal-progress tests passed.\n";
