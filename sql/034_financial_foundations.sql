-- docs/10 P1-4 · Insurance & liability adequacy — the "financial foundations"
-- check, shared by advisor-managed clients and self-serve individuals.
--
-- WHY THIS EXISTS. Every planning standard works a hierarchy before optimising
-- a long-horizon investment goal: an emergency reserve, then protection
-- (income replacement for dependants, and medical cover), then high-interest
-- debt, and only then the goal corpus. In the advisor product a human supplies
-- that judgment before the plan is built. In the self-serve individual tier
-- (sql/033) there is nobody to supply it — the flow runs from "how old are
-- you" straight to a readiness score, which can read as "in great shape" to
-- someone carrying a 36% credit-card balance with no medical cover.
--
-- This is a PLANNING-side module, explicitly not distribution (docs/10 P1-4).
-- It records what cover a person already has and states it against widely-cited
-- reference points. It does not recommend a product, an insurer, or a sum
-- assured, and nothing here is sold.
--
-- WHAT IS *NOT* STORED HERE, DELIBERATELY:
--   * Monthly expenses and income already live in cash_flow_items (sql/030).
--   * Liquid assets and liabilities already live in client_portfolio_items
--     (sql/018), which already carries the liquid/locked bucket flag.
-- Re-recording either would create a second copy that silently drifts from the
-- first. FinancialFoundations.php reads both and computes on the fly; the only
-- genuinely new facts are the two cover amounts and the dependants count.

CREATE TABLE client_protection (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',

    -- NULL means "not recorded yet", which is a different state from 0 and is
    -- reported differently: an unanswered protection question must never
    -- render as a passed check. 0 is a real and common answer (nobody depends
    -- on this person's income), and it makes the term-life check
    -- NOT APPLICABLE rather than failed — income replacement has nobody to
    -- replace income for. Getting that distinction wrong would nag every
    -- single person without dependants about cover they do not need.
    dependants_count    TINYINT UNSIGNED NULL
        COMMENT 'people financially dependent on this person; NULL = not recorded, 0 = none',

    -- Sum assured already held, not a target. Both NULL until recorded.
    term_life_cover     DECIMAL(15,2) NULL COMMENT 'existing life cover sum assured',
    health_cover        DECIMAL(15,2) NULL COMMENT 'existing health/medical cover sum insured',

    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_protection_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_protection_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_protection_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    -- One statement per client. The upsert endpoint relies on this.
    UNIQUE KEY uq_protection_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A liability's cost. Without it, a loan is just a number that reduces net
-- worth, and the plan cannot say the one arithmetically certain thing worth
-- saying: whether the debt costs more than the plan assumes the corpus will
-- earn. NULL for every existing row and for assets — no backfill, and an
-- unrated liability is reported as unrated, never assumed cheap.
ALTER TABLE client_portfolio_items
    ADD COLUMN interest_rate DECIMAL(5,2) NULL
        COMMENT 'annual % cost of a liability; NULL = not recorded. Meaningless for assets.'
        AFTER value;
