<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-2 — per-holding tax context. Pure, no DB — see
 * api/lib/PortfolioTaxContext.php's header for the deliberate "illustrative
 * unrealised gain, never a headroom/filing figure" scope boundary this
 * suite is checking is actually honored.
 */

require_once __DIR__ . '/../api/lib/PortfolioTaxContext.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- monthsHeld() ---------------------------------------------------------

assertTrue(monthsHeld(null) === null, 'no acquisition date -> null, never a guess');
assertTrue(monthsHeld('') === null, 'an empty-string date -> null');
assertTrue(monthsHeld('not-a-date') === null, 'an unparseable date -> null, not a crash');

assertTrue(monthsHeld('2024-01-15', '2025-01-15') === 12, 'exactly one year apart -> 12 months');
assertTrue(monthsHeld('2024-01-15', '2025-01-14') === 11, 'one day short of a year -> 11 months, rounded DOWN not up');
assertTrue(monthsHeld('2024-01-15', '2026-01-20') === 24, 'two years and a few days -> 24 whole months');
assertTrue(monthsHeld('2026-06-01', '2025-01-01') === null, 'an acquisition date in the FUTURE relative to as-of is not a real holding period');
assertTrue(monthsHeld('2025-01-01', '2025-01-01') === 0, 'acquired today -> 0 months held, not null');

// --- classifyHoldingPeriod() -----------------------------------------------

assertTrue(classifyHoldingPeriod(null, 12) === null, 'unknown months held -> null classification');
assertTrue(classifyHoldingPeriod(12, null) === null, 'no threshold at all (e.g. savings/FD/PPF) -> null classification, never a guessed short/long label');
assertTrue(classifyHoldingPeriod(12, 12) === 'short_term', 'held EXACTLY at the threshold counts as short-term — matches the "N months or less" wording in every treatment note');
assertTrue(classifyHoldingPeriod(13, 12) === 'long_term', 'one month past the threshold -> long-term');
assertTrue(classifyHoldingPeriod(0, 12) === 'short_term', 'held 0 months -> short-term');
assertTrue(classifyHoldingPeriod(24, 24) === 'short_term', 'the 24-month gold/real-estate threshold behaves the same way at the boundary');

// --- illustrativeGain() -----------------------------------------------------

assertTrue(illustrativeGain(null, 100000.0) === null, 'no acquisition value recorded -> null, NEVER a fabricated zero gain');

$gain = illustrativeGain(100000.0, 150000.0);
assertTrue($gain['amount'] === 50000.0, 'a straightforward gain is computed correctly');
assertTrue($gain['pct'] === 50.0, 'and its percentage');

$loss = illustrativeGain(100000.0, 80000.0);
assertTrue($loss['amount'] === -20000.0, 'a loss is a negative amount, not clamped to zero');
assertTrue($loss['pct'] === -20.0, 'and a negative percentage');

$flat = illustrativeGain(100000.0, 100000.0);
assertTrue($flat['amount'] === 0.0, 'no change at all -> exactly zero, which IS a real recorded fact here (unlike the null-acquisition case above)');

$zeroCost = illustrativeGain(0.0, 5000.0);
assertTrue($zeroCost['amount'] === 5000.0 && $zeroCost['pct'] === null, 'an acquisition value of exactly 0 avoids a division-by-zero crash — pct is null, amount is still reported');

// --- resolveTaxSubcategories() ---------------------------------------------

assertTrue(resolveTaxSubcategories('stocks', null) === ['default'], 'any non-mutual_fund category always resolves to the single default subcategory');
assertTrue(resolveTaxSubcategories('mutual_fund', 'equity') === ['equity'], 'a recorded fund_type resolves to exactly that one regime');
assertTrue(resolveTaxSubcategories('mutual_fund', 'debt') === ['debt'], 'debt likewise');
assertTrue(resolveTaxSubcategories('mutual_fund', null) === ['equity', 'debt', 'hybrid'], 'NO recorded fund_type resolves to all three regimes — never a guess at which one applies');

// --- buildPortfolioTaxContext(): the full orchestration --------------------

// A small synthetic tax_reference map, shaped like formatTaxReferenceRow()'s output.
$taxMap = [
    'mutual_fund|equity' => ['category' => 'mutual_fund', 'subcategory' => 'equity', 'label' => 'Equity fund', 'holding_period_months' => 12, 'short_term_note' => 'ST equity', 'long_term_note' => 'LT equity', 'exemption_note' => null, 'other_note' => null, 'source_name' => 'Src', 'source_url' => null, 'as_of_date' => '2024-07-23', 'is_verified' => false],
    'mutual_fund|debt' => ['category' => 'mutual_fund', 'subcategory' => 'debt', 'label' => 'Debt fund', 'holding_period_months' => null, 'short_term_note' => 'Slab always', 'long_term_note' => null, 'exemption_note' => null, 'other_note' => null, 'source_name' => 'Src', 'source_url' => null, 'as_of_date' => '2023-04-01', 'is_verified' => false],
    'mutual_fund|hybrid' => ['category' => 'mutual_fund', 'subcategory' => 'hybrid', 'label' => 'Hybrid fund', 'holding_period_months' => null, 'short_term_note' => 'Depends', 'long_term_note' => null, 'exemption_note' => null, 'other_note' => null, 'source_name' => 'Src', 'source_url' => null, 'as_of_date' => '2024-07-23', 'is_verified' => false],
    'stocks|default' => ['category' => 'stocks', 'subcategory' => 'default', 'label' => 'Listed shares', 'holding_period_months' => 12, 'short_term_note' => 'ST', 'long_term_note' => 'LT', 'exemption_note' => 'Exemption', 'other_note' => null, 'source_name' => 'Src', 'source_url' => null, 'as_of_date' => '2024-07-23', 'is_verified' => false],
];

// Category with no tax_reference row at all (e.g. 'other_asset') — never applicable.
$noneItem = ['category' => 'other_asset', 'fund_type' => null, 'value' => 1000.0, 'acquisition_value' => null, 'acquisition_date' => null];
$noneCtx = buildPortfolioTaxContext($noneItem, $taxMap);
assertTrue($noneCtx['applicable'] === false, 'a category with no cached tax_reference row is simply not applicable — never a fabricated note');
assertTrue($noneCtx['treatments'] === [], 'and carries zero treatments');

// A resolved, single-regime mutual fund with full cost basis — the whole flow.
$equityItem = ['category' => 'mutual_fund', 'fund_type' => 'equity', 'value' => 150000.0, 'acquisition_value' => 100000.0, 'acquisition_date' => '2024-01-01'];
$equityCtx = buildPortfolioTaxContext($equityItem, $taxMap, '2025-06-01');
assertTrue($equityCtx['applicable'] === true, 'a resolved equity fund is applicable');
assertTrue($equityCtx['needs_fund_type'] === false, 'and does not need a fund_type prompt — it already has one');
assertTrue(count($equityCtx['treatments']) === 1 && $equityCtx['treatments'][0]['subcategory'] === 'equity', 'exactly the one matching regime is returned');
assertTrue($equityCtx['months_held'] === 17, 'months held is computed from the acquisition date (2024-01-01 to 2025-06-01)');
assertTrue($equityCtx['holding_period_classification'] === 'long_term', 'past the 12-month line -> long-term, WITH a single resolved regime to classify against');
assertTrue($equityCtx['gain']['amount'] === 50000.0, 'the illustrative gain is computed since acquisition_value was recorded');

// An UNRESOLVED mutual fund (no fund_type) — the "needs_fund_type" / "show all three" path.
$unresolvedItem = ['category' => 'mutual_fund', 'fund_type' => null, 'value' => 80000.0, 'acquisition_value' => 60000.0, 'acquisition_date' => '2023-01-01'];
$unresolvedCtx = buildPortfolioTaxContext($unresolvedItem, $taxMap, '2025-06-01');
assertTrue($unresolvedCtx['needs_fund_type'] === true, 'an unset fund_type is flagged so the UI can prompt for it');
assertTrue(count($unresolvedCtx['treatments']) === 3, 'all three regimes are returned side by side');
assertTrue($unresolvedCtx['holding_period_classification'] === null, 'with three candidate regimes in play, holding-period classification is deliberately left null rather than guessing which threshold applies');
assertTrue($unresolvedCtx['gain']['amount'] === 20000.0, 'the illustrative gain is still shown — it does not depend on knowing the fund_type at all');

// A holding with NO cost basis recorded at all.
$noCostBasisItem = ['category' => 'stocks', 'fund_type' => null, 'value' => 50000.0, 'acquisition_value' => null, 'acquisition_date' => null];
$noCostBasisCtx = buildPortfolioTaxContext($noCostBasisItem, $taxMap, '2025-06-01');
assertTrue($noCostBasisCtx['applicable'] === true, 'the treatment note itself does not require cost basis');
assertTrue($noCostBasisCtx['gain'] === null, 'but the gain is null — never fabricated from a missing acquisition value');
assertTrue($noCostBasisCtx['months_held'] === null, 'and months held is null too, with no acquisition date recorded');

echo "\nAll portfolio-tax-context tests passed.\n";
