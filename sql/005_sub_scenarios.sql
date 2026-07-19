-- sub_scenarios: what-if variants of a base_plan (goal), protected from cascade
-- updates once customized. A single is_overridden flag covers ALL custom_* fields
-- on this row (documented behavior, see docs/02 Section 4.2 — not a bug).
CREATE TABLE sub_scenarios (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_plan_id                INT UNSIGNED NOT NULL,
    tenant_id                   INT UNSIGNED NOT NULL COMMENT 'hard boundary check, duplicated from base_plans for direct tenant-scoped queries',

    custom_inflation             DECIMAL(4,2) NULL,
    custom_withdrawal_rate       DECIMAL(4,2) NULL,
    custom_drawdown_return_rate DECIMAL(4,2) NULL,

    is_overridden                BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'true = protected from parent cascade on ALL custom_* fields above',
    updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_subscenarios_baseplan FOREIGN KEY (base_plan_id) REFERENCES base_plans(id),
    CONSTRAINT fk_subscenarios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    KEY idx_subscenarios_tenant_baseplan (tenant_id, base_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
