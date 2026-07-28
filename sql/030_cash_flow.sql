-- docs/10 "Cash-flow module" (new backlog entry): a unified client cash-flow
-- view — income (inflows) + expenses (outflows) + monthly surplus in one place.
--
-- This fills a real gap. HorizonPlan models saving only per-goal
-- (base_plans.monthly_sip_amount / sip_step_up_rate, the accumulation phase) and
-- spending only implicitly (base_plans.withdrawal_rate, decumulation). The
-- portfolio ledger (client_portfolio_items, sql/018) is a *balance sheet*
-- (item_kind = asset|liability, net worth) — it has no notion of a recurring
-- income or expense flow. So there was nowhere to see
-- "salary + other income − living expenses − EMIs = monthly surplus", nor to
-- tie that surplus to the SIPs a plan assumes.
--
-- Deliberately a factual statement the advisor (or the firm) supplies — the app
-- never fabricates an income or expense figure. Tenant-scoped and per-client on
-- every row; the household roll-up (CashFlowSummary.php) is the *sum* of members'
-- own statements, the same "grouping, not a shared pool" principle households
-- (sql/029) and the portfolio ledger already follow (docs/02 §4.1).
--
-- Default state is empty: no backfill, no row is created for any existing
-- tenant/client on migration (guardrail — never silently degrade an existing
-- tenant; the feature simply shows "nothing recorded yet" until an advisor adds
-- a line item).

CREATE TABLE cash_flow_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',

    kind            ENUM('income', 'expense') NOT NULL,
    label           VARCHAR(191) NOT NULL COMMENT 'advisor-supplied, e.g. "Salary", "Rent", "Home loan EMI"',
    -- The amount for one occurrence of this line at its own cadence. A monthly
    -- item stores the monthly figure; an annual item stores the annual figure
    -- (bonus, annual insurance premium, school fees). CashFlowSummary.php
    -- normalises everything to a monthly number on read — annual ÷ 12 — so the
    -- surplus is always a like-for-like monthly figure.
    amount          DECIMAL(15,2) NOT NULL,
    cadence         ENUM('monthly', 'annual') NOT NULL DEFAULT 'monthly',
    category        VARCHAR(50) NOT NULL COMMENT 'e.g. salary, business, rental, other_income, housing, food, utilities, emi, insurance, education, other_expense',
    -- Soft on/off so an advisor can park a line (e.g. an EMI that ends soon, a
    -- seasonal income) without deleting it and losing the record. Inactive rows
    -- are returned but excluded from the summed income/expense/surplus totals.
    is_active       TINYINT(1) NOT NULL DEFAULT 1,

    created_by_user_id INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_cashflow_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_cashflow_client  FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_cashflow_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    KEY idx_cashflow_tenant_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
