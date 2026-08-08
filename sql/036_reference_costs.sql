-- docs/11 Prompt E-3: the sourced reference-cost library ("shall I look this
-- up for you?"). Global, read-only reference data — same category as
-- market_history (sql/015) and mf_nav_cache (sql/027): not owned by any firm,
-- so deliberately outside TenantScopedDb's ALLOWED_TABLES.
--
-- WHY A TABLE AND NOT JUST CONSTANTS IN CODE: a future verification pass (a
-- human checking a range against AICTE/NMC/MOSPI/7th CPC and flipping
-- is_verified) must not require a deploy, same reasoning as market_history's
-- is_verified flag. The periodic CLI sync (tools/reference_costs_sync.php)
-- upserts a curated dataset from api/lib/ReferenceCosts.php into this table;
-- the app only ever reads the table, never the constant array directly, so
-- an eventual live-sourced version of the sync can replace the dataset
-- without touching any reader.
--
-- *** is_verified = 0 on every seeded row below, same disclosure as
-- market_history's own seed: these ranges are training-knowledge
-- approximations of the kind of figure AICTE/NMC/MOSPI/7th CPC publish, not
-- pulled from a live citable source in this session (no runtime or
-- CLI-session internet access — see docs/11 §3's "no runtime internet
-- access" constraint and CLAUDE.md's outbound-proxy note). Each row's
-- source_name says which authority it approximates. Flip is_verified only
-- after checking a real one.
CREATE TABLE reference_costs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- 'education' | 'healthcare' | 'city_expense_multiplier' — the three
    -- families scoped in docs/11 §4 E-2. New categories are additive; no
    -- enum, so a future category never needs a migration to add.
    category        VARCHAR(40) NOT NULL,

    -- The cost DRIVER, not the aspiration (docs/11 §3: "government or
    -- private?" moves the number ~20x; "doctor or engineer?" barely moves
    -- it). e.g. engineering_government, engineering_private,
    -- medical_government, medical_private, ongoing_medical_annual,
    -- city_tier_x, city_tier_y, city_tier_z.
    subcategory     VARCHAR(60) NOT NULL,

    label           VARCHAR(160) NOT NULL COMMENT 'human-readable, shown verbatim in the opt-in prompt',

    -- 'inr_total' (one-off, e.g. a full degree), 'inr_annual' (recurring
    -- cost), or 'multiplier' (city-tier expense multiplier — dimensionless,
    -- applied to an expense figure, never to the inflation rate — docs/11 §3).
    unit            VARCHAR(20) NOT NULL,

    low             DECIMAL(14,2) NOT NULL,
    high            DECIMAL(14,2) NOT NULL,

    source_name     VARCHAR(255) NOT NULL COMMENT 'named, citable authority — never "the internet"',
    source_url      VARCHAR(500) NULL,
    as_of_date      DATE NOT NULL,

    is_verified     TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'mirrors market_history.is_verified — 0 until a human confirms this row against the named source',

    fetched_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'when the sync last wrote this row — the freshness signal, same role as mf_nav_cache.fetched_at',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One row per (category, subcategory) — the sync upserts in place, a
    -- cache like mf_nav_cache, not an append-only history.
    UNIQUE KEY uq_reference_costs_category_subcategory (category, subcategory)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
