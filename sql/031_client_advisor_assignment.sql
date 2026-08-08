-- Client → advisor assignment (persona dashboards work).
--
-- Until now every advisor in a firm saw an identical, undifferentiated "Your
-- book": there was no column anywhere recording WHICH advisor a client belongs
-- to, so a jr_advisor had no "my clients" view and a firm_admin had no way to
-- see how work is distributed across the practice.
--
-- This adds that one missing fact. Deliberately narrow:
--
--   * It is an ATTRIBUTION field, not an access-control boundary. Tenant
--     isolation remains the only security boundary (docs/02 §3.1) — every
--     advisor in the firm can still read every client in their tenant, exactly
--     as before. "My clients" is a *filter over the same data*, never a new
--     permission gate. Nothing about verifyAccess()/TenantScopedDb changes.
--   * NULL = unassigned, and that is the default for every existing row. No
--     backfill: an existing tenant sees "Unassigned" for its whole book until
--     an advisor explicitly assigns, rather than the migration silently
--     inventing an owner (CLAUDE.md guardrail: never silently degrade — or in
--     this case, never silently *assert* — an existing tenant's data).
--   * ON DELETE SET NULL so removing an advisor un-assigns their clients
--     rather than blocking the delete or orphaning a dangling id.
--
-- The FK points at users(id) (the advisor's own row). Cross-tenant assignment
-- is prevented in the application layer (client_assign_advisor.php validates
-- both the client and the advisor are in the acting session's tenant via
-- TenantScopedDb), the same way household_assign.php already does — a plain FK
-- cannot express "must be in the same tenant".

ALTER TABLE users
    ADD COLUMN assigned_advisor_id INT UNSIGNED NULL DEFAULT NULL AFTER household_id,
    ADD KEY idx_users_assigned_advisor (assigned_advisor_id),
    ADD CONSTRAINT fk_users_assigned_advisor FOREIGN KEY (assigned_advisor_id)
        REFERENCES users(id) ON DELETE SET NULL;
