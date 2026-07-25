-- docs/05 item 3 / docs/06 "Corpus composition" (conditional, only if Phase 1
-- usage confirms real friction — it now has, directly, from advisor feedback):
-- break a single initial_net_worth number into liquid (market-linked) vs
-- locked (EPF/NPS/PPF-style, restricted-access) buckets, since these behave
-- very differently in real Indian retirement planning (docs/05 item 3).

-- Client-level portfolio ledger — a factual snapshot of what the client
-- already owns, independent of any one goal (a client's total assets are not
-- automatically any one goal's corpus — docs/02 4.1's "not a shared household
-- pool" principle extends here too: this is reference data an advisor can
-- pull from when setting a goal's own liquid/locked corpus split, not an
-- automatic binding).
CREATE TABLE client_portfolio_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',

    item_kind       ENUM('asset', 'liability') NOT NULL,
    -- Only meaningful for assets — NULL for liabilities. Liquid = market-linked,
    -- readily accessible (mutual funds, stocks, FDs, savings). Locked =
    -- restricted-access, e.g. EPF/NPS/PPF (docs/05 item 3's exact framing).
    -- This is a sequencing simplification, not a regulatory authority on any
    -- specific instrument's actual lock-in/maturity rules — see PlanMath.php.
    bucket          ENUM('liquid', 'locked') NULL,
    category        VARCHAR(50) NOT NULL COMMENT 'e.g. mutual_fund, stocks, fd, savings, ppf, epf, nps, real_estate, gold, home_loan, personal_loan, other',
    description     VARCHAR(255) NULL,
    value           DECIMAL(15,2) NOT NULL,

    created_by_user_id INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_clientportfolio_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_clientportfolio_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_clientportfolio_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    KEY idx_clientportfolio_tenant_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Goal-level corpus composition — how much of THIS goal's own initial_net_worth
-- is liquid vs locked. All nullable: when unset, a goal behaves exactly as
-- before this migration (single-bucket decumulation from initial_net_worth).
-- When both are set, they must sum to initial_net_worth (validated in
-- goals_create.php/goals_update.php, not at the DB layer).
ALTER TABLE base_plans
    ADD COLUMN liquid_corpus_amount DECIMAL(15,2) NULL
        COMMENT 'portion of initial_net_worth in liquid/market-linked assets; NULL = not decomposed, treat whole corpus as liquid'
        AFTER sip_step_up_rate,
    ADD COLUMN locked_corpus_amount DECIMAL(15,2) NULL
        COMMENT 'portion of initial_net_worth in locked/restricted-access assets (EPF/NPS/PPF-style)'
        AFTER liquid_corpus_amount,
    ADD COLUMN locked_return_rate DECIMAL(4,2) NULL
        COMMENT 'expected return on the locked bucket during decumulation. Distinct from drawdown_return_rate (the liquid bucket''s rate) — never merge these, same guardrail as accumulation_return_rate.'
        AFTER locked_corpus_amount;

-- locked_return_rate is a rate assumption like drawdown_return_rate, so it
-- joins the existing is_overridden cascade the same way. liquid_corpus_amount/
-- locked_corpus_amount do NOT get override columns here, same precedent as
-- initial_net_worth itself: a sub-scenario varies assumptions, not the
-- goal's starting amount.
ALTER TABLE sub_scenarios
    ADD COLUMN custom_locked_return_rate DECIMAL(4,2) NULL AFTER custom_sip_step_up_rate;
