-- P0-1 · Household / family aggregate planning (docs/10).
-- A household groups existing client records within a firm so an advisor can
-- see a family's combined picture. Deliberately a GROUPING, not a shared pool:
-- the aggregate is the *sum* of members' independently-modelled goals (docs/02
-- §4.1 stays intact — one member's corpus is never pooled into another's goal).
--
-- Tenant-scoped and never cross-tenant: a household belongs to one firm, and
-- household_assign.php validates both the client and the household are in the
-- acting advisor's tenant before linking. household_id defaults NULL with no
-- backfill — no client is in a household until an advisor explicitly assigns
-- them (guardrail: opt-in, existing clients untouched).

CREATE TABLE households (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(191) NOT NULL COMMENT 'e.g. "Sharma family"',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_hh_tenant (tenant_id),
    CONSTRAINT fk_hh_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A client belongs to at most one household. ON DELETE SET NULL so removing a
-- household simply un-groups its members rather than blocking on the FK.
ALTER TABLE users
    ADD COLUMN household_id INT UNSIGNED NULL DEFAULT NULL AFTER role,
    ADD KEY idx_users_household (household_id),
    ADD CONSTRAINT fk_users_household FOREIGN KEY (household_id)
        REFERENCES households(id) ON DELETE SET NULL;
