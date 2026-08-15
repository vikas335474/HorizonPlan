-- docs/13 I-5 · product_events — the first telemetry in this codebase.
--
-- WHY THIS EXISTS. Nothing today records where a person drops out of the
-- self-serve wizard, whether anyone opens a goal after onboarding, or which
-- refinement questions earn their place. docs/13's own P1 backlog is a list of
-- hypotheses; this table is what turns them into measured facts before anyone
-- spends a session acting on them.
--
-- *** THE PRIVACY RULE, WHICH IS THE WHOLE DESIGN ***
-- Record what a person DID in the product. Never what they ARE, and never a
-- figure. Step numbers, screen names, which lever was previewed — yes. A rupee
-- amount, an age, a goal label, a city tier — NO, not ever, not "just this
-- once for a funnel metric".
--
-- Two reasons, and the second is the load-bearing one:
--   1. Every financial value already lives in a properly tenant-scoped table.
--      A copy here would be a second source of truth that nothing keeps in
--      sync, and that no deletion path knows to clear.
--   2. This product's entire competitive position (docs/13 §3) is that it
--      plans without selling. An event log full of financial detail is exactly
--      the asset that makes a company start selling. Not collecting it is a
--      design decision, not an oversight.
--
-- The rule is enforced by tests/test_product_events.php, which asserts against
-- a real payload that no numeric financial value survives into event_context —
-- so this stays true by test, not by good intentions.
--
-- Anything that genuinely needs a rupee value is a query against the real
-- tables under tenant scope. That is a different job and belongs elsewhere.

CREATE TABLE product_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Tenant-scoped like everything else; this table is in TenantScopedDb's
    -- ALLOWED_TABLES so every read and write goes through the same helper.
    tenant_id       INT UNSIGNED NOT NULL,

    -- Who. ON DELETE CASCADE deliberately: when a person's account goes, their
    -- behavioural trail goes with it, with no teardown ordering for anyone to
    -- forget (contrast client_protection's plain FKs — see DEVELOPER_GUIDE §6's
    -- "adding a table that FKs to users costs more than the migration").
    user_id         INT UNSIGNED NOT NULL,

    -- A CLOSED SET, not free text. A typo'd event name is a silently missing
    -- funnel step, and free text is how a rupee value eventually ends up in
    -- here as part of a name. ProductEvents.php holds the same list and
    -- rejects anything else before it reaches the database.
    event_name      VARCHAR(48) NOT NULL,

    -- Small JSON. Allowed keys are whitelisted in ProductEvents.php:
    -- step index, screen name, lever type, question id. NEVER a figure.
    -- NULL is the normal case — most events need no context at all.
    event_context   JSON NULL,

    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_product_events_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_events_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- The funnel query is "events of this name, in this tenant, over a date
    -- range"; the retention query is "this user's events in order".
    KEY idx_product_events_tenant_name_time (tenant_id, event_name, occurred_at),
    KEY idx_product_events_user_time (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
