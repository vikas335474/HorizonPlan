-- Two-earner households in the self-serve tier — a couple planning together
-- with no advisor.
--
-- ALMOST NOTHING IS NEEDED HERE, and that is the point worth recording. The
-- machinery already exists and is reused unchanged:
--
--   * `households` + `users.household_id` (sql/029) already group people
--     WITHIN one tenant, and migration 029's own header states the principle
--     this feature depends on: a household is a GROUPING, NOT A SHARED POOL —
--     the aggregate is the sum of members' independently-modelled goals, and
--     docs/02 §4.1 stays intact. That is exactly right for a couple: two
--     earners have different ages, horizons and risk tolerances, so the sum of
--     two honest plans beats one merged pot that models neither person.
--   * `users` already supports many rows per tenant, so a partner is simply a
--     second user with role='client' in the same personal tenant.
--   * password_resets.purpose='invite' (sql/025) + api/lib/InviteTokens.php
--     already issue and redeem magic-link invites, and /accept-invite already
--     renders the landing page.
--   * The write gate (api/lib/SelfService.php) already forces a personal
--     user's writes to their OWN client_id. See the note below.
--
-- THE ONE THING THAT CHANGES IN THE SECURITY REASONING. SelfService.php was
-- written for a "tenant of one", and argued that forcing writes to the
-- session's own id was defence in depth "even though a personal tenant
-- contains nobody else's data". With a partner in the tenant that premise no
-- longer holds, so that check stops being redundant and becomes the thing
-- actually stopping one partner editing the other's plan. The comment there
-- has been corrected to say so. No code change was required — the check was
-- already right, for a reason that has now become the real reason.
--
-- WHAT IS ADDED: a name.
--
-- users has only ever had `email`, and the whole app identifies people by it.
-- That is tolerable for one person looking at their own plan and poor for a
-- household view, where "arun@example.com" and "priya@example.com" have to
-- stand in for two people side by side. /start-free already collects a full
-- name and, until now, could only store it on tenants.company_name — a
-- person's name on the tenant record, which was always slightly wrong and gets
-- more wrong once a tenant holds two people.
--
-- Nullable with NO backfill, per the standing guardrail: every existing user
-- keeps rendering by email exactly as today, and nothing requires this field.

ALTER TABLE users
    ADD COLUMN display_name VARCHAR(191) NULL DEFAULT NULL
        COMMENT 'optional human name; falls back to email everywhere when NULL'
        AFTER email;
