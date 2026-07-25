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

// --- twoBucketDecumulationSeries (docs/05 item 3 / docs/06 corpus composition) ---
// 6,000,000 liquid @7%, 4,000,000 locked @6%, 4% withdrawal, 6% inflation, 5 years.
// Year 1 by hand: annualWithdrawal = 10,000,000*0.04 = 400,000; withdrawal_y1 = 400,000*1.06 = 424,000.
// liquid grows to 6,420,000, locked grows to 4,240,000. liquid covers the withdrawal on its own:
// liquid = 6,420,000 - 424,000 = 5,996,000; locked untouched at 4,240,000. Total = 10,236,000.
$s = PlanMath::twoBucketDecumulationSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5);
assertTrue(count($s) === 6, 'twoBucketDecumulationSeries returns horizon+1 points');
assertTrue($s[0] === 10000000.0, 'twoBucketDecumulationSeries year 0 is the combined starting total, untouched');
assertClose($s[1], 10236000.0, 'twoBucketDecumulationSeries year 1 matches the formula by hand (liquid covers withdrawal alone)');

// A high withdrawal rate that exhausts a SMALL liquid bucket, spilling the
// remainder into locked. 1,000,000 liquid @5%, 9,000,000 locked @6%, 15%
// withdrawal, 0% inflation (flat, for clean hand math), 4 years.
// Y1: liquid grows to 1,050,000, locked grows to 9,540,000; withdrawal = 1,500,000.
//   liquid can't cover it: remainder = 1,500,000-1,050,000=450,000; liquid->0; locked = 9,540,000-450,000=9,090,000.
// Y2: liquid stays 0 (0 compounds to 0); locked grows to 9,090,000*1.06=9,635,400; the ENTIRE
//   1,500,000 withdrawal comes from locked (liquid contributes 0): locked = 9,635,400-1,500,000=8,135,400.
$spillover = PlanMath::twoBucketDecumulationSeries(1000000.0, 9000000.0, 15.0, 0.0, 5.0, 6.0, 4);
assertClose($spillover[1], 9090000.0, 'twoBucketDecumulationSeries spills the withdrawal shortfall into locked once liquid can\'t cover it alone');
assertClose($spillover[2], 8135400.0, 'twoBucketDecumulationSeries keeps drawing from locked once liquid has been fully exhausted (stays at zero, not negative)');

// --- twoBucketAdverseSequenceSeries ---
// Same corpus/rate/withdrawal inputs as the first case above. Each bucket
// gets its own synthetic below/above-average sequence (spread=4, odd horizon
// forces the middle year back to the average): liquid (avg 7%) -> raw
// [3,11,7,11,3] sorted [3,3,7,11,11]; locked (avg 6%) -> raw [2,10,6,10,2]
// sorted [2,2,6,10,10]. Year 1: liquid grows at 3% -> 6,180,000; locked grows
// at 2% -> 4,080,000; withdrawal_y1 = 424,000, covered entirely by liquid:
// liquid = 6,180,000-424,000 = 5,756,000. Total = 5,756,000+4,080,000 = 9,836,000.
$adverse = PlanMath::twoBucketAdverseSequenceSeries(6000000.0, 4000000.0, 4.0, 6.0, 7.0, 6.0, 5);
assertTrue(count($adverse) === 6, 'twoBucketAdverseSequenceSeries returns horizon+1 points');
assertClose($adverse[1], 9836000.0, 'twoBucketAdverseSequenceSeries year 1 matches the per-bucket synthetic sequence by hand');

// Sequence risk should still bite: same average returns per bucket, but the
// adverse ordering front-loads weak years, so it should end up worse than
// the steady two-bucket series by the end of a longer, higher-withdrawal horizon.
$steadyLong = PlanMath::twoBucketDecumulationSeries(6000000.0, 4000000.0, 5.0, 6.0, 7.0, 6.0, 20);
$adverseLong = PlanMath::twoBucketAdverseSequenceSeries(6000000.0, 4000000.0, 5.0, 6.0, 7.0, 6.0, 20);
assertTrue(end($adverseLong) < end($steadyLong), 'two-bucket adverse-sequence ending balance is worse than the steady two-bucket ending balance, same average returns per bucket');

// --- consistency with the existing single-bucket methods ---
// Putting the ENTIRE corpus in the liquid bucket (locked=0) at the liquid
// bucket's rate must reduce to exactly the existing single-bucket series —
// this is the backward-compatibility guarantee: a goal without corpus
// composition set behaves identically to one with locked_corpus_amount=0.
$singleBucket = PlanMath::steadyReturnSeries(10000000.0, 4.0, 6.0, 7.0, 10);
$asTwoBucket = PlanMath::twoBucketDecumulationSeries(10000000.0, 0.0, 4.0, 6.0, 7.0, 6.0, 10);
assertTrue($singleBucket === $asTwoBucket, 'twoBucketDecumulationSeries with an all-liquid, zero-locked split matches steadyReturnSeries exactly');

$singleAdverse = PlanMath::adverseSequenceSeries(10000000.0, 4.0, 6.0, 7.0, 10);
$asTwoBucketAdverse = PlanMath::twoBucketAdverseSequenceSeries(10000000.0, 0.0, 4.0, 6.0, 7.0, 6.0, 10);
assertTrue($singleAdverse === $asTwoBucketAdverse, 'twoBucketAdverseSequenceSeries with an all-liquid, zero-locked split matches adverseSequenceSeries exactly');

echo "\nAll corpus composition tests passed.\n";
