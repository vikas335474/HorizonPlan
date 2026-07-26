-- Daily MF NAV price-sync cron (docs "session 2" of the mock-data/subdomain/
-- MF-price/XIRR follow-up plan recorded in CLAUDE.md). Scope, per direct user
-- instruction: only track NAV for schemes that actually appear in a client's
-- portfolio — never ingest AMFI's full scheme universe into this database.

-- Two new columns on an existing client_portfolio_items row let it opt into
-- NAV tracking. Both nullable: a manually-entered value or a CAS-imported row
-- (api/client_portfolio_import.php, which only ever captures {description,
-- value}, no scheme code) is completely unaffected and keeps behaving exactly
-- as before this migration. Only a row with BOTH fields set participates in
-- the sync — enforced in application code (client_portfolio_create.php /
-- client_portfolio_update.php), same "both-or-neither" precedent already used
-- for liquid/locked corpus composition on base_plans (sql/018).
ALTER TABLE client_portfolio_items
    ADD COLUMN amfi_scheme_code VARCHAR(20) NULL
        COMMENT 'AMFI scheme code, e.g. 119551. Only meaningful when category=mutual_fund; NULL means this row is not NAV-tracked.'
        AFTER category,
    ADD COLUMN units_held DECIMAL(18,4) NULL
        COMMENT 'Units held for this scheme. Paired with amfi_scheme_code — both or neither.'
        AFTER amfi_scheme_code;

-- Global reference data, one row per scheme actually held by any client on
-- the platform — deliberately outside TenantScopedDb's ALLOWED_TABLES, same
-- category as market_history/login_attempts (no tenant_id column: a scheme's
-- NAV is not tenant-specific). A CACHE, not a history table, on purpose: the
-- daily cron upserts in place rather than appending a new row per day, since
-- this session's scope is "keep the price current," not "build the valuation
-- history a future XIRR/performance session will need" (that is a deliberate,
-- separately-scoped decision for that later session, not assumed here).
CREATE TABLE mf_nav_cache (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    amfi_scheme_code VARCHAR(20) NOT NULL,
    scheme_name      VARCHAR(255) NOT NULL,
    nav_value        DECIMAL(14,4) NOT NULL,
    nav_date         DATE NOT NULL COMMENT 'the date AMFI published this NAV for',
    fetched_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when our own cron actually pulled it — the real freshness signal, since nav_date can lag on a market holiday',

    UNIQUE KEY uq_mfnavcache_scheme_code (amfi_scheme_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
