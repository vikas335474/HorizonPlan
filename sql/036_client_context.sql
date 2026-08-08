-- docs/11 Prompt E-2 · Progressive personalisation ("make this more accurate").
--
-- The self-serve plan (sql/033) works from day one with no extra input — E-1
-- already reconciled the readiness score against a real spending-based target.
-- This migration adds the storage for the first three OPT-IN refinement
-- questions docs/11 §4 scopes: city tier, per-child education cost driver, and
-- an ongoing medical cost. Every field defaults to unset; there is no backfill
-- for existing tenants, and an unanswered field must never be treated as zero
-- or "not applicable" — see api/lib/PersonalisationReference.php for how each
-- is applied, always as a visible, sourced, editable assumption.
--
-- TWO TABLES, DELIBERATELY SEPARATE, mirroring client_protection (sql/034)'s
-- own split of "protection facts" from the ledgers it reads:
--
--   client_context     — one row per client: city tier + its multiplier
--                         override, and an ongoing monthly medical cost. Same
--                         one-row-per-client shape as client_protection.
--   client_dependants  — one row PER CHILD (current age + cost driver).
--                         client_protection's single-row shape cannot hold a
--                         list, and a person can have more than one child.
--
-- SCOPE DECISIONS ON RECORD (docs/11 §4 explicitly requires this call, and asks
-- for it to be made deliberately rather than defaulted silently):
--   * city_tier / expense_multiplier: HOUSEHOLD-shared, mirroring the reserve/
--     debt precedent in FinancialFoundations (a couple retiring together
--     shares one destination). Applied by reading the ACTING client's own row
--     first and falling back to a household partner's, in application code —
--     no separate "household" column needed here.
--   * monthly_medical_cost: PER-PERSON, mirroring the existing cover fields in
--     client_protection (term_life_cover / health_cover already stay
--     per-person for the same reason: averaging two people's medical costs
--     would misstate both).
--   * client_dependants: household-shared by the same read-time aggregation
--     foundations_read.php already uses for cash_flow_items — a child belongs
--     to the household, not to whichever parent happened to enter the row.

CREATE TABLE client_context (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',

    -- 7th Pay Commission HRA city classification — the sourced, stable proxy
    -- this repo uses for relative cost of living (docs/11 §3: no official
    -- Indian cost-of-living index exists). NULL = not answered.
    city_tier               ENUM('X', 'Y', 'Z') NULL
        COMMENT '7th CPC HRA city classification; NULL = not recorded',

    -- The EFFECTIVE multiplier applied to the retirement-year expense
    -- assumption. NULL when the person has only picked a tier (the tier's own
    -- sourced default is then used, computed in PersonalisationReference.php,
    -- not persisted here so it can never drift out of date with that file).
    -- Set to a non-null value only when the person has overridden the number
    -- directly — that is what makes it "visible and user-overridable" per
    -- docs/11: the UI must show the person's own figure as distinct from our
    -- assumption, and persisting only the override (never the computed
    -- default) is what keeps that distinction honest at read time.
    expense_multiplier       DECIMAL(4,2) NULL
        COMMENT 'user override of the city-tier expense multiplier; NULL = use the tier default',

    -- An ongoing recurring medical cost NOT already reflected in cash_flow_items
    -- (docs/11 §5: the financial CONSEQUENCE, never a diagnosis or condition).
    -- NULL = not recorded, 0 = recorded as none — same not-recorded-vs-zero
    -- distinction client_protection already draws.
    monthly_medical_cost     DECIMAL(12,2) NULL
        COMMENT 'recurring monthly medical cost beyond ordinary cash-flow expenses; NULL = not recorded',

    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_context_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_context_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_context_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    -- One statement per client, same upsert-by-client shape as client_protection.
    UNIQUE KEY uq_context_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per dependant child. cost_driver asks the DRIVER (government seat /
-- private / overseas), never the aspiration (docs/11 §3: "government or
-- private" moves the number ~20x; "doctor or engineer" moves it far less, and
-- re-costing off a changed ambition would be confidently wrong — docs/11 §5).
CREATE TABLE client_dependants (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client — the parent who recorded this row',

    -- Free-text label only ("Older child") so the UI can tell two rows apart —
    -- deliberately NOT the child's actual name, which would be an unnecessary
    -- extra piece of a minor's personal data for a purely arithmetic feature.
    label           VARCHAR(60) NULL,

    current_age     TINYINT UNSIGNED NULL COMMENT 'NULL = not recorded',
    cost_driver     ENUM('government', 'private', 'overseas') NULL
        COMMENT 'the cost DRIVER, not the aspiration (docs/11 sec 3); NULL = not recorded',

    -- Optional link to the education-type goal this child's cost driver should
    -- suggest a target range for. Explicit and nullable — NEVER auto-matched
    -- by title or date proximity, which could silently apply the wrong child's
    -- range to the wrong goal (docs/11 Prompt E-2 decide-then-build note).
    goal_id         INT UNSIGNED NULL COMMENT 'FK to base_plans.id, goal_type=education; NULL = not linked to a goal yet',

    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_dependant_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_dependant_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_dependant_goal FOREIGN KEY (goal_id) REFERENCES base_plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_dependant_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_dependants_client ON client_dependants (tenant_id, client_id);
