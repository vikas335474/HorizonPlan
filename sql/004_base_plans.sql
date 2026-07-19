-- base_plans = goals. A client has one row per goal (retirement, education, etc.),
-- not one row total — see docs/02 Section 4.1.
CREATE TABLE base_plans (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    client_id               INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',

    -- Section 4.1: multi-goal fields
    goal_type               ENUM('retirement', 'education', 'home_purchase', 'other') NOT NULL,
    goal_label               VARCHAR(255) NOT NULL COMMENT 'advisor-set display name',
    target_amount           DECIMAL(15,2) NULL COMMENT 'optional — not every goal has a fixed target',
    target_date             DATE NULL,

    -- Original blueprint fields
    initial_net_worth       DECIMAL(15,2) NOT NULL COMMENT 'this goal''s own starting corpus, not a shared household pool',
    inflation_rate          DECIMAL(4,2) NOT NULL,

    -- Section 4.2: withdrawal rate (retirement-type goals only, nullable for others)
    withdrawal_rate          DECIMAL(4,2) NULL COMMENT 'e.g. 3.50 = 3.5%. Default suggested 3.5, not the US 4%/25x convention.',

    -- Section 4.3: decumulation projection (retirement-type goals only)
    drawdown_return_rate    DECIMAL(4,2) NULL COMMENT 'post-retirement expected return. Distinct from a future accumulation_return_rate — never merge these.',
    projection_horizon_years INT UNSIGNED NOT NULL DEFAULT 30,

    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_baseplans_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_baseplans_client FOREIGN KEY (client_id) REFERENCES users(id),
    KEY idx_baseplans_tenant_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
