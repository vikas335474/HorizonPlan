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

// --- accumulationSeries (docs/07 Session C / docs/06 Section A) ---
// 100,000 start, 8% return, 5,000/month SIP with 10% annual step-up, 3 years.
// By hand: year1 annualSip = 12*5000*1.10^0 = 60,000; balance = 100,000*1.08+60,000 = 168,000
// year2 annualSip = 12*5000*1.10^1 = 66,000; balance = 168,000*1.08+66,000 = 247,440
// year3 annualSip = 12*5000*1.10^2 = 72,600; balance = 247,440*1.08+72,600 = 339,835.2
$acc = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 3);
assertTrue(count($acc) === 4, 'accumulationSeries returns years+1 points');
assertTrue($acc[0] === 100000.0, 'accumulationSeries year 0 is the starting balance, untouched');
assertClose($acc[1], 168000.0, 'accumulationSeries year 1 matches the formula by hand');
assertClose($acc[2], 247440.0, 'accumulationSeries year 2 compounds the stepped-up SIP');
assertClose($acc[3], 339835.2, 'accumulationSeries year 3 matches the formula by hand');

// Zero years to retirement -> just the starting balance, no growth applied.
assertTrue(
    PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 10.0, 0) === [100000.0],
    'accumulationSeries with 0 years returns just the starting balance'
);

// Zero SIP -> pure compounding, no contribution.
$accNoSip = PlanMath::accumulationSeries(100000.0, 8.0, 0.0, 0.0, 2);
assertClose($accNoSip[1], 108000.0, 'accumulationSeries with zero SIP compounds the starting balance alone (year 1)');
assertClose($accNoSip[2], 116640.0, 'accumulationSeries with zero SIP compounds the starting balance alone (year 2)');

// Zero step-up -> flat SIP every year, no increase.
$accFlatSip = PlanMath::accumulationSeries(100000.0, 8.0, 5000.0, 0.0, 2);
assertClose($accFlatSip[1], 168000.0, 'accumulationSeries with 0% step-up year 1 matches the stepped case (no difference yet)');
assertClose($accFlatSip[2], 241440.0, 'accumulationSeries with 0% step-up year 2 uses the same 60,000 SIP again, not a stepped-up one');

// --- lifecycleSeries: accumulation hands its terminal corpus to decumulation ---
// Same 3-year accumulation as above (terminal corpus 339,835.2), then a 5-year
// decumulation at 4% withdrawal / 6% inflation / 7% return.
$life = PlanMath::lifecycleSeries(100000.0, 8.0, 5000.0, 10.0, 3, 4.0, 6.0, 7.0, 5);
assertTrue(count($life) === 9, 'lifecycleSeries concatenates accumulation (4 points) + decumulation (5 points), sharing the boundary year');
assertClose($life[3], 339835.2, 'lifecycleSeries retirement-day point matches accumulationSeries terminal corpus');
// Decumulation year 1 by hand: annualWithdrawal = 339,835.2*0.04*1.06 = 14,409.05;
// balance = 339,835.2*1.07 - 14,409.05 = 363,563.66 - 14,409.05 = 349,154.61 (approx, PlanMath rounds at each step)
$decumulationCheck = PlanMath::steadyReturnSeries(339835.2, 4.0, 6.0, 7.0, 5);
assertClose($life[4], $decumulationCheck[1], 'lifecycleSeries decumulation leg matches steadyReturnSeries computed from the same terminal corpus');
assertClose($life[8], $decumulationCheck[5], 'lifecycleSeries final year matches the standalone decumulation series final year');

// A decumulation horizon of 0 means the combined curve is just the
// accumulation leg — decumulation contributes only its (duplicate) year-0 point.
assertTrue(
    PlanMath::lifecycleSeries(100000.0, 8.0, 5000.0, 10.0, 3, 4.0, 6.0, 7.0, 0) === $acc,
    'lifecycleSeries with a 0-year decumulation horizon equals the accumulation series alone'
);

echo "\nAll accumulation tests passed.\n";
