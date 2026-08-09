<?php
declare(strict_types=1);

// Pure tests for PlanMath::targetGoalFunding() — the funding signal for
// target-based goals (education / home_purchase / other), which carry a
// target_amount + target_date but never a return rate or SIP.
//
// The point of these tests is as much about what the helper REFUSES to do
// (invent growth) as what it computes: every expected value below is
// reproducible by hand from the inputs alone.

require_once __DIR__ . '/../api/lib/PlanMath.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

function assertClose(float $a, float $b, string $label, float $tolerance = 0.5): void
{
    assertTrue(abs($a - $b) < $tolerance, "$label (got $a, expected ~$b)");
}

// --- Not a target-based goal: null in, null out -----------------------------
assertTrue(
    PlanMath::targetGoalFunding(null, '2034-08-08', 300000.0, 6.0, '2026-08-08') === null,
    'no target_amount → null (retirement goals use the Readiness Score instead)'
);
assertTrue(
    PlanMath::targetGoalFunding(2000000.0, null, 300000.0, 6.0, '2026-08-08') === null,
    'no target_date → null (nothing to inflate toward)'
);
assertTrue(
    PlanMath::targetGoalFunding(0.0, '2034-08-08', 300000.0, 6.0, '2026-08-08') === null,
    'a zero target is treated as unset, not as an instantly-funded goal'
);

// --- The headline case ------------------------------------------------------
// ₹20,00,000 target, 8 years out, 6% inflation, ₹3,00,000 saved so far.
// Inflated target by hand: 2,000,000 * 1.06^8 = 2,000,000 * 1.593848 = 3,187,696.
// Covered: 300,000 / 3,187,696 = 9.41%.  Shortfall: 3,187,696 - 300,000 = 2,887,696.
$f = PlanMath::targetGoalFunding(2000000.0, '2034-08-08', 300000.0, 6.0, '2026-08-08');
assertTrue($f !== null, 'a target_amount + target_date goal returns a funding block');
assertClose($f['years_remaining'], 8.0, 'years_remaining is the gap to the target date', 0.02);
assertClose($f['inflated_target'], 3187696.0, 'target is inflated to target-date rupees at the goal\'s own rate', 50.0);
assertClose($f['covered_pct'], 9.4, 'covered_pct is today\'s corpus over the INFLATED cost', 0.2);
assertClose($f['shortfall'], 2887696.0, 'shortfall is the inflated cost minus what is saved today', 50.0);
assertTrue($f['is_met'] === false, 'docs/12 D-3: 9.4% covered is clearly not met');

// --- No growth is ever assumed ---------------------------------------------
// Same goal, same corpus, only the horizon doubles. If the helper were
// secretly compounding the corpus, coverage would rise with time. It must
// FALL instead, because only the target inflates.
$near = PlanMath::targetGoalFunding(2000000.0, '2030-08-08', 300000.0, 6.0, '2026-08-08');
$far  = PlanMath::targetGoalFunding(2000000.0, '2046-08-08', 300000.0, 6.0, '2026-08-08');
assertTrue(
    $far['covered_pct'] < $near['covered_pct'],
    'a longer horizon LOWERS coverage — the corpus is never silently grown, only the target inflates'
);
assertTrue(
    $far['inflated_target'] > $near['inflated_target'],
    'a longer horizon raises the inflated cost'
);

// --- Zero inflation: the target is simply the target ------------------------
$z = PlanMath::targetGoalFunding(1000000.0, '2036-08-08', 250000.0, 0.0, '2026-08-08');
assertClose($z['inflated_target'], 1000000.0, 'with 0% inflation the cost is unchanged');
assertClose($z['covered_pct'], 25.0, 'coverage is a plain ratio when nothing inflates');

// --- Fully funded clamps at 100%, never above --------------------------------
$full = PlanMath::targetGoalFunding(1000000.0, '2027-08-08', 5000000.0, 6.0, '2026-08-08');
assertClose($full['covered_pct'], 100.0, 'an over-funded goal reports 100%, not 470%');
assertTrue($full['shortfall'] === 0.0, 'an over-funded goal has no shortfall (never a negative one)');
assertTrue($full['is_met'] === true, 'docs/12 D-3: an over-funded goal is met');

// --- is_met at the exact boundary, and just under it ------------------------
// Corpus exactly equal to the inflated target (0% inflation removes any
// rounding ambiguity from the exponent).
$exact = PlanMath::targetGoalFunding(1000000.0, '2030-08-08', 1000000.0, 0.0, '2026-08-08');
assertTrue($exact['is_met'] === true, 'exactly at the target counts as met (>=, not strictly >)');

$justUnder = PlanMath::targetGoalFunding(1000000.0, '2030-08-08', 999999.0, 0.0, '2026-08-08');
assertTrue($justUnder['is_met'] === false, 'one rupee under the target is not met');
assertClose($justUnder['covered_pct'], 100.0, 'covered_pct still rounds/clamps to 100.0 for display even though is_met is false — is_met reads the UNCLAMPED ratio, not the display figure');

// --- A target date in the past --------------------------------------------
// "Due now" — the horizon floors at 0 so the target is never DEFLATED back
// below its stated amount by a negative exponent.
$past = PlanMath::targetGoalFunding(1000000.0, '2020-01-01', 400000.0, 6.0, '2026-08-08');
assertTrue($past['years_remaining'] === 0.0, 'an overdue target floors the horizon at 0, not negative');
assertClose($past['inflated_target'], 1000000.0, 'an overdue target is not deflated below its stated amount');
assertClose($past['covered_pct'], 40.0, 'coverage on an overdue goal is the plain ratio');

echo "\nAll target-goal funding tests passed.\n";
