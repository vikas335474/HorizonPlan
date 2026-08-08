<?php
declare(strict_types=1);

// docs/10 P1-4 · Pure tests for FinancialFoundations.
//
// The rules under test are the ones most likely to do real harm if they drift:
// an unrecorded answer must never read as a passed check, having no dependants
// must never read as a protection failure, and an empty cash-flow statement
// must never read as infinite emergency cover.

require_once __DIR__ . '/../api/lib/FinancialFoundations.php';

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

// --- emergency reserve ------------------------------------------------------
$e = FinancialFoundations::emergencyReserve(50000.0, 400000.0);
assertClose($e['months'], 8.0, '8 months of cover is computed from liquid / monthly expenses');
assertTrue($e['status'] === 'ok', '8 months clears the 6-month comfortable mark');

assertTrue(
    FinancialFoundations::emergencyReserve(50000.0, 200000.0)['status'] === 'partial',
    '4 months sits between the 3-month floor and the 6-month mark → partial'
);
assertTrue(
    FinancialFoundations::emergencyReserve(50000.0, 50000.0)['status'] === 'short',
    '1 month is below the floor → short'
);
assertTrue(
    FinancialFoundations::emergencyReserve(50000.0, 0.0)['status'] === 'short',
    'nothing set aside is short, not "not recorded" — the expenses ARE known'
);

// The flattering-wrong-answer guard: no expenses recorded means no denominator.
$noExpenses = FinancialFoundations::emergencyReserve(0.0, 400000.0);
assertTrue($noExpenses['status'] === 'not_recorded', 'no recorded expenses → not_recorded, never a division by zero');
assertTrue($noExpenses['months'] === null, 'no expenses → no month count at all, rather than infinity');

// --- life cover -------------------------------------------------------------
// The single most important branch: no dependants is NOT a failure.
$noDeps = FinancialFoundations::lifeCover(0, null, 1200000.0);
assertTrue($noDeps['status'] === 'not_applicable', 'zero dependants → not_applicable (nobody depends on this income)');
assertTrue(
    FinancialFoundations::lifeCover(null, null, 1200000.0)['status'] === 'not_recorded',
    'unrecorded dependants → not_recorded, which is NOT the same as zero'
);

$covered = FinancialFoundations::lifeCover(2, 15000000.0, 1200000.0);
assertClose($covered['multiple'], 12.5, 'cover is reported as a multiple of annual income');
assertTrue($covered['status'] === 'ok', '12.5x clears the 10x floor');

assertTrue(
    FinancialFoundations::lifeCover(2, 3000000.0, 1200000.0)['status'] === 'partial',
    '2.5x is cover, but under the 10x floor → partial'
);
assertTrue(
    FinancialFoundations::lifeCover(2, 0.0, 1200000.0)['status'] === 'short',
    'dependants with zero cover → short'
);
assertTrue(
    FinancialFoundations::lifeCover(2, 15000000.0, 0.0)['status'] === 'not_recorded',
    'cover with no income to size it against is unsized, not silently passed'
);
assertTrue(
    FinancialFoundations::lifeCover(2, null, 1200000.0)['status'] === 'not_recorded',
    'dependants recorded but cover unrecorded → not_recorded'
);

// --- health cover -----------------------------------------------------------
assertTrue(FinancialFoundations::healthCover(null)['status'] === 'not_recorded', 'unrecorded health cover → not_recorded');
assertTrue(FinancialFoundations::healthCover(0.0)['status'] === 'short', 'no health cover → short');
assertTrue(FinancialFoundations::healthCover(300000.0)['status'] === 'partial', 'below the reference floor → partial');
assertTrue(FinancialFoundations::healthCover(500000.0)['status'] === 'ok', 'at the reference floor → ok');
assertTrue(FinancialFoundations::healthCover(1000000.0)['status'] === 'ok', 'above the floor → ok');

// --- costly debt ------------------------------------------------------------
// The two ways a liability list can be empty, and why they differ.
assertTrue(
    FinancialFoundations::costlyDebt([], 11.0, true)['status'] === 'ok',
    'a recorded ledger with no liabilities → ok (this person genuinely owes nothing)'
);
assertTrue(
    FinancialFoundations::costlyDebt([], 11.0, false)['status'] === 'not_recorded',
    'an entirely untouched ledger → not_recorded, never a green "no expensive debt" on a blank slate'
);

$cheap = FinancialFoundations::costlyDebt([['label' => 'Home loan', 'value' => 4000000.0, 'interest_rate' => 8.5]], 11.0);
assertTrue($cheap['status'] === 'ok', 'a loan cheaper than the assumed return is not flagged');
assertTrue($cheap['costly'] === [], 'and it does not appear in the costly list');

$card = FinancialFoundations::costlyDebt([['label' => 'Credit card', 'value' => 180000.0, 'interest_rate' => 36.0]], 11.0);
assertTrue($card['status'] === 'short', 'a loan costing more than the plan assumes to earn is flagged');
assertClose($card['costly'][0]['gap'], 25.0, 'the gap is reported in percentage points');
assertClose($card['total_costly'], 180000.0, 'the flagged balance is totalled');

// An unrated liability must never be assumed cheap — that hides the exact case
// this check exists to catch.
$unrated = FinancialFoundations::costlyDebt([['label' => 'Personal loan', 'value' => 500000.0, 'interest_rate' => null]], 11.0);
assertTrue($unrated['status'] === 'not_recorded', 'a liability with no rate recorded reports as unrated, not as ok');
assertTrue($unrated['unrated'] === 1, 'and the unrated count is surfaced');

// A costly loan outranks an unrated one — a known problem beats an open question.
$mixed = FinancialFoundations::costlyDebt([
    ['label' => 'Card', 'value' => 100000.0, 'interest_rate' => 36.0],
    ['label' => 'Other', 'value' => 100000.0, 'interest_rate' => null],
], 11.0);
assertTrue($mixed['status'] === 'short', 'a known costly loan takes precedence over an unrated one');
assertTrue($mixed['unrated'] === 1, 'the unrated count is still reported alongside');

// With no return assumption there is nothing to compare against.
$noAssumption = FinancialFoundations::costlyDebt([['label' => 'Card', 'value' => 100000.0, 'interest_rate' => 36.0]], null);
assertTrue($noAssumption['status'] === 'not_recorded', 'no return assumption → the comparison is unavailable, not passed');
assertTrue($noAssumption['costly'] === [], 'and nothing is called costly without something to call it costly against');

// --- summary counts ---------------------------------------------------------
// A completely blank statement. Three checks are open; the debt check is not.
// That asymmetry is deliberate and worth stating: cover distinguishes "not
// recorded" (NULL) from "none" (0), because there is a field to answer. Debt
// has no such field — an absent liability row IS the representation of having
// no loan, so an empty list is a genuine "no debt" rather than an unanswered
// question. Treating it as open would make the check unclearable by anyone who
// simply has no debt.
$blank = FinancialFoundations::summary(0.0, 0.0, 0.0, null, null, null, [], null, false);
assertTrue($blank['open'] === 4, 'a brand-new account leaves ALL FOUR checks open — nothing is passed by default');
assertTrue($blank['unmet'] === 0, 'and reports NO shortfalls — an unanswered question is not a failure');
assertTrue($blank['ok'] === 0, 'and nothing passes on an empty account');

// The same blank protection, but against a ledger the person HAS filled in and
// which genuinely contains no debt.
$noDebt = FinancialFoundations::summary(0.0, 0.0, 0.0, null, null, null, [], 11.0, true);
assertTrue($noDebt['ok'] === 1, 'a recorded ledger with no loans does pass the debt check');

// A fully-sound statement with no dependants: not_applicable counts as neither.
$sound = FinancialFoundations::summary(50000.0, 400000.0, 1200000.0, 0, null, 1000000.0, [], 11.0);
assertTrue($sound['unmet'] === 0, 'a sound statement has no shortfalls');
assertTrue($sound['open'] === 0, 'and no open questions');
assertTrue($sound['ok'] === 3, 'not_applicable life cover counts toward none of the three totals');

// A genuinely exposed statement.
$exposed = FinancialFoundations::summary(
    50000.0, 50000.0, 1200000.0, 2, 0.0, 0.0,
    [['label' => 'Card', 'value' => 200000.0, 'interest_rate' => 36.0]],
    11.0
);
assertTrue($exposed['unmet'] === 4, 'thin reserve + no life cover + no health cover + costly debt = 4 shortfalls');
assertTrue($exposed['ok'] === 0, 'and nothing passing');

echo "\nAll financial-foundations tests passed.\n";
