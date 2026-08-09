<?php
declare(strict_types=1);

/**
 * The sourced tax-treatment reference (docs/12 Prompt D-2). Same
 * three-layer split, and the same table+cron precedent, as
 * api/lib/ReferenceCosts.php (docs/11 Prompt E-3):
 *   1. taxReferenceDataset() — pure, no DB/network. The curated dataset
 *      itself: what tax treatment applies to each instrument category
 *      (docs/12 §2 principle 1 — facts, never advice; principle 2 — sourced
 *      and flagged until verified).
 *   2. syncTaxReference() — DB-touching, upserts the dataset into
 *      tax_reference (sql/039), then prunes any cached row the dataset no
 *      longer defines. Does NOT manage its own transaction — same
 *      convention as syncReferenceCosts(): the caller
 *      (tools/tax_reference_sync.php) owns the transaction boundary.
 *   3. getTaxReference() / listTaxReference() — plain reads, used by
 *      api/lib/PortfolioTaxContext.php (the per-holding orchestrator) and
 *      a future direct read endpoint if one is ever needed.
 *
 * *** Every row here is is_verified = 0. *** Indian capital-gains and
 * income-tax rules changed substantially in the 2023 and 2024 Union
 * Budgets and change again with essentially every budget after that. This
 * dataset is a good-faith, training-knowledge summary, not fetched from a
 * live citable source this session (no runtime or CLI-session internet
 * access — same constraint as docs/11 §3). A human must verify every row
 * against the current Finance Act / CBDT guidance before a real client
 * meeting leans on the exact rate or threshold, and the UI keeps showing
 * "illustrative — verify against current rules" until that happens. This
 * is NOT investment or tax advice, and never computes a filing figure.
 *
 * THE mutual_fund SPLIT. category='mutual_fund' has THREE subcategory rows
 * (equity/debt/hybrid), not one — a generic "mutual fund" tax note would be
 * actively wrong for whichever regime it isn't. Equity-oriented funds keep
 * a concessional LTCG rate; debt-oriented funds have been taxed entirely at
 * slab rate with no LTCG concept since 1 April 2023 — completely different
 * regimes. See client_portfolio_items.fund_type (sql/039) and
 * PortfolioTaxContext.php's resolveTaxSubcategories() for how an unset
 * fund_type is handled (all three shown, never a guess).
 *
 * @return list<array{category:string,subcategory:string,label:string,holding_period_months:?int,short_term_note:?string,long_term_note:?string,exemption_note:?string,other_note:?string,source_name:string,source_url:?string,as_of_date:string,is_verified:bool}>
 */
function taxReferenceDataset(): array
{
    $section111A112A = 'Income Tax Act, 1961, Sections 111A/112A, as amended by Finance (No. 2) Act 2024 (illustrative summary — verify against the current Finance Act / CBDT guidance)';
    $rateChangeDate = '2024-07-23'; // the Budget 2024 date these listed-equity-style rates took effect

    return [
        // ── Mutual funds: three genuinely different regimes ──────────────
        [
            'category' => 'mutual_fund', 'subcategory' => 'equity',
            'label' => 'Equity-oriented mutual fund',
            'holding_period_months' => 12,
            'short_term_note' => 'Held 12 months or less: short-term capital gains (STCG), taxed at 20%.',
            'long_term_note' => 'Held more than 12 months: long-term capital gains (LTCG), taxed at 12.5%, without indexation.',
            'exemption_note' => 'LTCG up to ₹1,25,000 in a financial year is exempt — this limit is combined across ALL your equity/equity-fund LTCG for the year, not per holding.',
            'other_note' => null,
            'source_name' => $section111A112A, 'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],
        [
            'category' => 'mutual_fund', 'subcategory' => 'debt',
            'label' => 'Debt-oriented mutual fund',
            'holding_period_months' => null,
            'short_term_note' => 'For units acquired on or after 1 April 2023: the entire gain, however long held, is added to your income and taxed at your slab rate — there is no separate short/long-term split or LTCG rate any more.',
            'long_term_note' => null,
            'exemption_note' => null,
            'other_note' => 'Units bought BEFORE 1 April 2023 may still qualify for the older indexed LTCG treatment (20% with indexation, held over 36 months) — check your specific units\' purchase date against this cutoff.',
            'source_name' => 'Income Tax Act, 1961, as amended by Finance Act 2023 (illustrative summary — verify against current rules, especially the 1 April 2023 cutoff for your specific units)',
            'source_url' => null, 'as_of_date' => '2023-04-01', 'is_verified' => false,
        ],
        [
            'category' => 'mutual_fund', 'subcategory' => 'hybrid',
            'label' => 'Hybrid mutual fund',
            'holding_period_months' => null,
            'short_term_note' => 'Depends on the fund\'s actual equity allocation: funds with 65% or more in equity are taxed like equity funds; below that, like debt funds (see those two rows).',
            'long_term_note' => null,
            'exemption_note' => null,
            'other_note' => 'Check your fund\'s category (e.g. "aggressive hybrid" vs "conservative hybrid" vs "balanced advantage") and its actual equity allocation — this app does not know your specific fund\'s allocation.',
            'source_name' => 'Income Tax Act, 1961 (illustrative summary — the equity-oriented threshold and its application to hybrid categories has shifted across recent Finance Acts; verify against current rules)',
            'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── Listed equity shares — same regime as equity mutual funds ────
        [
            'category' => 'stocks', 'subcategory' => 'default',
            'label' => 'Listed equity shares',
            'holding_period_months' => 12,
            'short_term_note' => 'Held 12 months or less: STCG taxed at 20%.',
            'long_term_note' => 'Held more than 12 months: LTCG taxed at 12.5%, without indexation.',
            'exemption_note' => 'LTCG up to ₹1,25,000 in a financial year is exempt — combined across all your equity/equity-fund LTCG for the year, not per holding.',
            'other_note' => null,
            'source_name' => $section111A112A, 'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── Bonds — genuinely varied by type, heavily hedged on purpose ──
        [
            'category' => 'bonds', 'subcategory' => 'default',
            'label' => 'Bonds / debentures (listed)',
            'holding_period_months' => 12,
            'short_term_note' => 'For LISTED bonds/debentures held 12 months or less: gain taxed at your slab rate.',
            'long_term_note' => 'For LISTED bonds/debentures held more than 12 months: LTCG typically taxed at 12.5%, without indexation.',
            'exemption_note' => null,
            'other_note' => 'Bond taxation varies a lot by type — listed vs. unlisted, government vs. corporate, tax-free vs. taxable — and Sovereign Gold Bonds have their own separate rule entirely (see "Gold"). Unlisted bonds generally follow a different, often less favourable regime. Treat this as a starting point, not a final answer for your specific bond.',
            'source_name' => 'Income Tax Act, 1961 (illustrative summary — bond taxation is instrument-specific; verify against current rules for your exact bond)',
            'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── REITs/InvITs — capital gains like listed equity, distributions differ ──
        [
            'category' => 'reits', 'subcategory' => 'default',
            'label' => 'REITs / InvITs',
            'holding_period_months' => 12,
            'short_term_note' => 'On sale, held 12 months or less: STCG taxed at 20% (same listed-security treatment as stocks).',
            'long_term_note' => 'On sale, held more than 12 months: LTCG taxed at 12.5%, without indexation.',
            'exemption_note' => 'The ₹1,25,000 LTCG exemption applies here too, combined with your other equity/equity-fund LTCG for the year.',
            'other_note' => 'Distributions from a REIT/InvIT (separate from any gain on sale) are typically a mix of interest, dividend, and capital-repayment components, each taxed differently — interest is usually taxed at your slab rate, dividend may be exempt depending on the underlying SPV\'s own tax status, and the capital-repayment portion is generally not taxable. Check your REIT\'s own distribution statement for the breakdown.',
            'source_name' => $section111A112A, 'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── Gold — recent holding-period simplification, SGB exception ──
        [
            'category' => 'gold', 'subcategory' => 'default',
            'label' => 'Gold (physical, ETF, or gold fund)',
            'holding_period_months' => 24,
            'short_term_note' => 'Held 24 months or less: STCG taxed at your slab rate.',
            'long_term_note' => 'Held more than 24 months: LTCG taxed at 12.5%, without indexation.',
            'exemption_note' => null,
            'other_note' => 'Sovereign Gold Bonds (SGBs) held to full maturity (8 years) from the government are exempt from capital-gains tax entirely — a distinctly more favourable rule than physical gold, gold ETFs, or gold mutual funds.',
            'source_name' => 'Income Tax Act, 1961, as amended by Finance (No. 2) Act 2024 (illustrative summary — the 24-month line and indexation removal are recent changes; verify against current rules)',
            'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── Real estate — the transitional-choice rule, heavily time-sensitive ──
        [
            'category' => 'real_estate', 'subcategory' => 'default',
            'label' => 'Real estate (land, flat, other property)',
            'holding_period_months' => 24,
            'short_term_note' => 'Held 24 months or less: STCG taxed at your slab rate.',
            'long_term_note' => 'Held more than 24 months AND acquired before 23 July 2024: you may choose between 12.5% without indexation OR 20% with indexation, whichever gives the lower tax. Property acquired on or after 23 July 2024: LTCG is 12.5% without indexation only.',
            'exemption_note' => 'Sections 54/54F/54EC allow reinvestment-based exemptions on real-estate LTCG (e.g. buying another residential property, or specific capital-gains bonds) — not automatic; it needs deliberate reinvestment within the specified time window.',
            'other_note' => null,
            'source_name' => 'Income Tax Act, 1961, Sections 54/54F/54EC + the transitional rule from Finance (No. 2) Act 2024 (illustrative summary — this transitional choice is time-sensitive and easy to get wrong; verify against current rules, and consider a chartered accountant before filing)',
            'source_url' => null, 'as_of_date' => $rateChangeDate, 'is_verified' => false,
        ],

        // ── Interest-income-style instruments: no ST/LT split, own notes ──
        [
            'category' => 'savings', 'subcategory' => 'default',
            'label' => 'Savings account',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => '₹10,000/year of savings interest is deductible under Section 80TTA (₹50,000/year under Section 80TTB instead, covering all bank interest, if you\'re a senior citizen).',
            'other_note' => 'Savings-account interest is not a capital gain — it\'s taxed as income, at your slab rate, every year it\'s credited.',
            'source_name' => 'Income Tax Act, 1961, Sections 80TTA/80TTB (illustrative summary — verify against current rules)',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
        [
            'category' => 'fd', 'subcategory' => 'default',
            'label' => 'Fixed deposit',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => null,
            'other_note' => 'FD interest is taxed at your slab rate each year it accrues, not only when the FD matures. Banks deduct TDS at 10% once your total interest from that bank exceeds ₹40,000/year (₹50,000/year for senior citizens) — you may still owe more, or get a refund, depending on your actual slab.',
            'source_name' => 'Income Tax Act, 1961 (illustrative summary — verify against current rules)',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
        [
            'category' => 'cash', 'subcategory' => 'default',
            'label' => 'Cash',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => null,
            'other_note' => 'Cash itself has no tax treatment — it is not an income-generating or capital asset.',
            'source_name' => 'N/A', 'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
        [
            'category' => 'ppf', 'subcategory' => 'default',
            'label' => 'PPF (Public Provident Fund)',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => 'Contributions qualify for a Section 80C deduction, subject to the overall ₹1,50,000 80C cap shared with other instruments.',
            'other_note' => 'PPF is EEE (Exempt-Exempt-Exempt): contributions are 80C-deductible, the interest earned is fully exempt, and the maturity amount is fully exempt. No capital-gains concept applies.',
            'source_name' => 'Income Tax Act, 1961, Section 80C + Section 10(11) (illustrative summary — verify against current rules)',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
        [
            'category' => 'epf', 'subcategory' => 'default',
            'label' => 'EPF (Employees\' Provident Fund)',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => 'Contributions qualify for the standard 80C deduction, subject to the overall ₹1,50,000 cap shared with other instruments.',
            'other_note' => 'Generally EEE if withdrawn after 5 years of continuous service — interest and maturity are exempt. Withdrawing before 5 years\' continuous service makes the withdrawal taxable. Interest earned on your OWN contributions above ₹2,50,000 in a financial year is itself taxable (a rule since 2021).',
            'source_name' => 'Income Tax Act, 1961, Section 10(12) + Finance Act 2021 (illustrative summary — verify against current rules)',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
        [
            'category' => 'nps', 'subcategory' => 'default',
            'label' => 'NPS (National Pension System)',
            'holding_period_months' => null,
            'short_term_note' => null, 'long_term_note' => null,
            'exemption_note' => 'Contributions qualify for the standard 80C deduction (shared cap) plus an additional ₹50,000 under Section 80CCD(1B).',
            'other_note' => 'At maturity (age 60), up to 60% of the corpus can be withdrawn as a lump sum, fully tax-exempt. The remaining 40%+ must go toward an annuity, and the annuity income received later is taxed at your slab rate each year it\'s paid.',
            'source_name' => 'Income Tax Act, 1961, Sections 80CCD/10(12A) (illustrative summary — verify against current rules)',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],
    ];
}

/**
 * Upserts the curated dataset into tax_reference, then PRUNES any cached
 * row the dataset no longer defines — same reasoning and pattern as
 * syncReferenceCosts(). Does NOT begin/commit its own transaction; the
 * caller (tools/tax_reference_sync.php) owns that boundary. is_verified is
 * set from the dataset ONLY on a first insert — a re-sync never resets an
 * already-verified row.
 *
 * @return array{rows_written:int,rows_pruned:int}
 */
function syncTaxReference(PDO $db): array
{
    $dataset = taxReferenceDataset();

    $upsert = $db->prepare(
        'INSERT INTO tax_reference
            (category, subcategory, label, holding_period_months, short_term_note, long_term_note, exemption_note, other_note, source_name, source_url, as_of_date, is_verified, fetched_at)
         VALUES
            (:category, :subcategory, :label, :holding_period_months, :short_term_note, :long_term_note, :exemption_note, :other_note, :source_name, :source_url, :as_of_date, :is_verified, :fetched_at)
         ON DUPLICATE KEY UPDATE
            label                  = VALUES(label),
            holding_period_months  = VALUES(holding_period_months),
            short_term_note        = VALUES(short_term_note),
            long_term_note         = VALUES(long_term_note),
            exemption_note         = VALUES(exemption_note),
            other_note             = VALUES(other_note),
            source_name            = VALUES(source_name),
            source_url             = VALUES(source_url),
            as_of_date             = VALUES(as_of_date),
            fetched_at             = VALUES(fetched_at)'
    );

    $now = date('Y-m-d H:i:s');
    $keep = [];
    foreach ($dataset as $row) {
        $upsert->execute([
            ':category' => $row['category'],
            ':subcategory' => $row['subcategory'],
            ':label' => $row['label'],
            ':holding_period_months' => $row['holding_period_months'],
            ':short_term_note' => $row['short_term_note'],
            ':long_term_note' => $row['long_term_note'],
            ':exemption_note' => $row['exemption_note'],
            ':other_note' => $row['other_note'],
            ':source_name' => $row['source_name'],
            ':source_url' => $row['source_url'],
            ':as_of_date' => $row['as_of_date'],
            ':is_verified' => $row['is_verified'] ? 1 : 0,
            ':fetched_at' => $now,
        ]);
        $keep[] = $row['category'] . '|' . $row['subcategory'];
    }

    $pruned = 0;
    $existing = $db->query('SELECT id, category, subcategory FROM tax_reference')->fetchAll();
    $deleteStmt = $db->prepare('DELETE FROM tax_reference WHERE id = :id');
    foreach ($existing as $row) {
        if (!in_array($row['category'] . '|' . $row['subcategory'], $keep, true)) {
            $deleteStmt->execute([':id' => $row['id']]);
            $pruned++;
        }
    }

    return ['rows_written' => count($dataset), 'rows_pruned' => $pruned];
}

/**
 * Single-row read. Returns null if the (category, subcategory) isn't
 * cached yet (the sync has never run, or this category isn't covered) —
 * the caller must treat that as "nothing to show," never fabricate a note.
 *
 * @return ?array<string,mixed>
 */
function getTaxReference(PDO $db, string $category, string $subcategory): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM tax_reference WHERE category = :category AND subcategory = :subcategory'
    );
    $stmt->execute([':category' => $category, ':subcategory' => $subcategory]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * All cached rows, optionally filtered to one category.
 *
 * @return list<array<string,mixed>>
 */
function listTaxReference(PDO $db, ?string $category = null): array
{
    if ($category !== null) {
        $stmt = $db->prepare('SELECT * FROM tax_reference WHERE category = :category ORDER BY subcategory ASC');
        $stmt->execute([':category' => $category]);
    } else {
        $stmt = $db->query('SELECT * FROM tax_reference ORDER BY category ASC, subcategory ASC');
    }
    return $stmt->fetchAll();
}

/**
 * Shapes one DB row for API output — typed fields, is_verified as a real
 * bool, holding_period_months as a real int-or-null.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function formatTaxReferenceRow(array $row): array
{
    return [
        'category'               => $row['category'],
        'subcategory'            => $row['subcategory'],
        'label'                  => $row['label'],
        'holding_period_months'  => $row['holding_period_months'] !== null ? (int) $row['holding_period_months'] : null,
        'short_term_note'        => $row['short_term_note'],
        'long_term_note'         => $row['long_term_note'],
        'exemption_note'         => $row['exemption_note'],
        'other_note'             => $row['other_note'],
        'source_name'            => $row['source_name'],
        'source_url'             => $row['source_url'],
        'as_of_date'             => $row['as_of_date'],
        'is_verified'            => (bool) $row['is_verified'],
    ];
}
