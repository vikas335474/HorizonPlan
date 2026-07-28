-- P0-3 · Scheduled / emailed plan reviews (docs/10).
-- A per-client cadence for a light "your plan, refreshed" email that links the
-- client back to their own self-service login. One row per client; the advisor
-- sets it. Default 'off' and no backfill — nobody is ever silently enrolled
-- into outbound email (guardrail-style caution: opt-in, never spam). The absence
-- of a row is equivalent to 'off', so existing clients are untouched.
--
-- The daily cron (tools/plan_review_send.php → api/lib/PlanReviewMailer.php)
-- reads this cross-tenant, same pattern as the MF NAV sync; the advisor-facing
-- read/update endpoints stay tenant-scoped through TenantScopedDb.

CREATE TABLE plan_review_schedules (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    client_id    INT UNSIGNED NOT NULL,
    cadence      ENUM('off', 'quarterly', 'annually') NOT NULL DEFAULT 'off',
    -- When the last review email actually went out (or was demo-suppressed).
    -- NULL means "never sent" → due immediately once a non-off cadence is set.
    last_sent_at DATETIME NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_prs_client (client_id),
    KEY idx_prs_cadence (cadence),
    CONSTRAINT fk_prs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_prs_client FOREIGN KEY (client_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
