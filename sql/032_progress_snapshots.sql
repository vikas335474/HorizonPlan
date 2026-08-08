-- P1-1 · Goal-progress tracking over time (docs/10).
--
-- Until now a plan was a one-time projection: you could see what the goal
-- assumes, never how it has actually behaved. These two tables turn it into an
-- ongoing record — "here is what you had, here is what the plan expected, on
-- each date we looked."
--
-- TWO separate series, deliberately never mixed:
--
--   goal_snapshots              — one row per GOAL per date. corpus_value is
--                                 the goal's own corpus.
--   client_net_worth_snapshots  — one row per CLIENT per date, from the
--                                 portfolio ledger (assets - liabilities).
--
-- They are kept apart because docs/02 §4.1 is explicit that the portfolio is
-- NOT a shared pool any one goal draws from. Summing a client's holdings into
-- a per-goal progress line would invent exactly the link the model denies, so
-- the client-level series is reported as its own thing and never attributed to
-- a goal.
--
-- WHY expected_value IS STORED, not recomputed on read:
-- Each row is a record of what the plan expected AS IT STOOD on that date. If
-- the expected line were recomputed from the goal's current assumptions, an
-- advisor lowering a return assumption would retroactively turn a "behind"
-- history into an "on track" one — the plan edit would rewrite the past. This
-- codebase already prefers immutable records for exactly this reason (see
-- change_log). The cost is one float per row.
--
-- expected_value is NULL when the goal has nothing to project from (a target
-- goal carries no return assumption at all — see PlanMath::targetGoalFunding);
-- those goals record funding_covered_pct instead, so their progress is the
-- coverage trend rather than a drift-from-plan.
--
-- Idempotency: a UNIQUE key on (goal_id, as_of_date) / (client_id, as_of_date)
-- means the monthly cron and a manual "Record now" on the same day converge on
-- one row instead of stacking duplicates — the capture path upserts.
--
-- No backfill. History starts the first time a snapshot is taken; the app
-- never invents a past valuation it did not observe.

CREATE TABLE goal_snapshots (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    goal_id             INT UNSIGNED NOT NULL,
    client_id           INT UNSIGNED NOT NULL COMMENT 'denormalised from base_plans for per-client roll-ups without a join',
    as_of_date          DATE NOT NULL,
    corpus_value        DECIMAL(15,2) NOT NULL COMMENT 'the goal''s own corpus on this date (the "actual")',
    expected_value      DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'what the plan expected on this date, as the plan stood then; NULL when the goal cannot be projected',
    funding_covered_pct DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'target-based goals only: % of the inflated target covered on this date',
    created_by_user_id  INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = written by the scheduled cron, not a person',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_goal_snapshot_day (goal_id, as_of_date),
    KEY idx_gs_tenant (tenant_id),
    KEY idx_gs_client (client_id),
    CONSTRAINT fk_gs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_gs_goal   FOREIGN KEY (goal_id) REFERENCES base_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_gs_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    -- SET NULL, not CASCADE: deleting the advisor who captured a snapshot must
    -- not delete the snapshot. The reading stays true regardless of who took it.
    CONSTRAINT fk_gs_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_net_worth_snapshots (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    client_id          INT UNSIGNED NOT NULL,
    as_of_date         DATE NOT NULL,
    net_worth          DECIMAL(15,2) NOT NULL COMMENT 'assets_total - liabilities_total from the portfolio ledger',
    assets_total       DECIMAL(15,2) NOT NULL,
    liabilities_total  DECIMAL(15,2) NOT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = scheduled cron',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_client_networth_day (client_id, as_of_date),
    KEY idx_cnws_tenant (tenant_id),
    CONSTRAINT fk_cnws_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_cnws_client  FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cnws_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
