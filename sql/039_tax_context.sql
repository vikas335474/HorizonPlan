-- docs/12 Prompt D-2 · Per-instrument tax-treatment context (facts only).
-- Two independent additions, both nullable, no backfill:
--   (a) tax_reference — a sourced cache of tax TREATMENT per instrument,
--       the same shape/precedent as reference_costs (sql/037): a table +
--       periodic CLI cron rather than a code constant, because tax rates
--       move every Union Budget and a human should be able to correct one
--       or flip is_verified without a deploy.
--   (b) cost-basis fields on client_portfolio_items — what unlocks an
--       ILLUSTRATIVE gain/loss and holding-period fact for a specific
--       holding. Deliberately independent of each other (a person may know
--       the date but not remember the exact price, or vice versa) — no
--       both-or-neither constraint like the NAV-tracking fields have,
--       because each is independently useful on its own here.
--
-- WHAT THIS DOES NOT ADD: a capital-gains statement, a filing figure, or
-- any "how much LTCG exemption you have left this year" number. This app
-- has no transaction/sale ledger — only a snapshot of what's currently
-- held — so it structurally cannot know what you've already realised and
-- used up this financial year. It can only tell you the UNREALISED gain on
-- a still-held position, and what the treatment WOULD be if sold today.
-- That's the honest boundary of "facts only" here — see
-- api/lib/PortfolioTaxContext.php.

-- fund_type: only meaningful when category='mutual_fund'. Equity funds keep
-- a concessional LTCG rate; debt funds have been taxed entirely at slab
-- rate with no LTCG concept since 1 April 2023 — two completely different
-- regimes a generic "mutual fund" category cannot distinguish. NULL means
-- "not specified" — the tax-context UI shows all three regimes side by
-- side rather than guessing, per the decide-then-build call on this prompt.
ALTER TABLE client_portfolio_items
    ADD COLUMN fund_type ENUM('equity', 'debt', 'hybrid') NULL
        COMMENT 'Only meaningful when category=mutual_fund. NULL = not specified; tax context then shows all three regimes rather than guessing.'
        AFTER category;

-- Cost basis. NULL for every existing row (no backfill — nobody recorded
-- this before it existed) and for any new row until the person supplies
-- it. Meaningless for liabilities, same posture as bucket/interest_rate
-- being the mirror-image nulled-out fields for the other item_kind.
ALTER TABLE client_portfolio_items
    ADD COLUMN acquisition_value DECIMAL(15,2) NULL
        COMMENT 'What was paid for this holding. NULL = not recorded — never assume 0, never assume today''s value.'
        AFTER value,
    ADD COLUMN acquisition_date DATE NULL
        COMMENT 'When this holding was acquired. NULL = not recorded. Used only to classify short vs long term if the instrument''s tax_reference row has a holding_period_months threshold.'
        AFTER acquisition_value;

-- Global, read-only reference data — not owned by any firm, so deliberately
-- outside TenantScopedDb's ALLOWED_TABLES, same category as market_history
-- (sql/015) and reference_costs (sql/037).
--
-- *** is_verified = 0 on every seeded row, same disclosure as market_history
-- and reference_costs' own seeds. *** Indian capital-gains and income-tax
-- rules changed substantially in the 2023 and 2024 Union Budgets (debt-fund
-- indexation removal, the equity LTCG rate/exemption change, gold/property
-- holding-period and indexation changes) and change again with essentially
-- every budget after that. Every row here is a good-faith, training-
-- knowledge summary, NOT fetched from a live citable source this session
-- (no runtime or CLI-session internet access, same constraint as docs/11
-- §3) — a human must verify each row against the current Finance Act /
-- CBDT guidance before a real client meeting leans on the exact rate or
-- threshold, and the UI must keep showing "illustrative — verify against
-- current rules" until that happens.
CREATE TABLE tax_reference (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Matches client_portfolio_items.category. 'default' subcategory for
    -- every category except mutual_fund, which splits into equity/debt/hybrid
    -- (matching the fund_type enum above) since that split is the whole
    -- point of this table existing.
    category                VARCHAR(40) NOT NULL,
    subcategory             VARCHAR(40) NOT NULL,

    label                   VARCHAR(160) NOT NULL COMMENT 'human-readable, shown verbatim in the tax-context panel',

    -- The short/long-term dividing line, in months, for capital-gains-style
    -- instruments (12 for listed equity/equity funds/REITs/most listed
    -- bonds, 24 for gold/real estate). NULL for interest-income-style
    -- instruments (savings, FD, PPF, EPF, NPS, cash) where the short/long
    -- split doesn't apply the same way — those get their own note instead.
    holding_period_months   SMALLINT UNSIGNED NULL,

    short_term_note         TEXT NULL COMMENT 'What applies if sold/withdrawn before the holding_period_months line. NULL where the concept does not apply.',
    long_term_note          TEXT NULL COMMENT 'What applies if sold/withdrawn after the holding_period_months line. NULL where the concept does not apply.',
    exemption_note          TEXT NULL COMMENT 'Any annual exemption/deduction threshold (e.g. the equity LTCG exemption, 80TTA/80TTB). NULL where none applies.',
    other_note              TEXT NULL COMMENT 'Anything else worth stating as a fact — EEE status, annuity rules, distribution-component breakdowns, transitional provisions.',

    -- Wider than reference_costs' equivalent column on purpose: a tax
    -- citation routinely needs to name a specific section AND the amending
    -- Act AND a caveat about a transitional rule in one string (e.g. the
    -- real_estate row's), which a Finance Act citation can run past 255
    -- characters for.
    source_name             VARCHAR(500) NOT NULL COMMENT 'named, citable authority — never "the internet"',
    source_url              VARCHAR(500) NULL,
    as_of_date              DATE NOT NULL,

    is_verified             TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'mirrors reference_costs/market_history — 0 until a human confirms this row against the named source, and against the CURRENT Finance Act (tax rules move every budget)',

    fetched_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'when the sync last wrote this row — same freshness role as reference_costs.fetched_at',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One row per (category, subcategory) — the sync upserts in place and
    -- prunes anything the current dataset no longer defines, same as
    -- reference_costs (sql/037).
    UNIQUE KEY uq_tax_reference_category_subcategory (category, subcategory)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
