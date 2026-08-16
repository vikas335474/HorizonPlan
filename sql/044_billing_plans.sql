-- Billing / subscription plan-tier concept for HorizonPlan itself (docs/09
-- Session 8, docs/10's own reference to it). Before this migration: zero
-- billing/subscription code or schema existed — every tenant had identical,
-- unlimited capability, and there was nothing to demo a monetization story
-- with.
--
-- DECISION RECORD (made with the user via AskUserQuestion before this file
-- was written, per CLAUDE.md's "decide-then-build" convention — this is
-- exactly the item that convention calls out as applying to most strongly):
--   * Scope: DATA MODEL + ENFORCEMENT ONLY. No payment processor (Razorpay/
--     Stripe), no invoicing, no checkout, no dunning. docs/09 Session 8
--     explicitly bars real payment processing from this session regardless of
--     pricing model chosen — that is a distinct, later scope (real money,
--     real compliance surface).
--   * Pricing model: per-seat (per advisor), 3 tiers — Starter / Growth /
--     Unlimited. Clients and goals are NOT gated by this session; only
--     advisor-seat count is (the cheapest, clearest lever, and what
--     docs/09 Session 8 itself illustrates first).
--   * The self-serve INDIVIDUAL tier (tenants.kind = 'personal', sql/033) is
--     explicitly OUT OF SCOPE for this billing model — it stays free, with no
--     plan_id at all. Only firm-kind tenants (the B2B2C advisory-firm side)
--     are billed. tenants.plan_id is therefore nullable and NEVER set for a
--     personal-kind tenant.
--
-- SEAT LIMITS AND PRICE BELOW ARE ILLUSTRATIVE DEFAULTS, NOT A FINAL PRICING
-- DECISION. price_inr_monthly is explicitly informational-only (docs/09
-- Session 8: "price ... do not build real payment processing in this
-- session") — nothing reads it to charge anyone. The catalog is fixed by this
-- migration, not editable through the app in this session (mechanism, not
-- opinion, per CLAUDE.md's guardrail-style-caution convention) — a human can
-- adjust the three rows directly before this ever reaches a paying customer.
--
-- BACKFILL: every tenant that existed before this migration must not be
-- silently degraded (CLAUDE.md non-negotiable rule + docs/09 Session 8's own
-- explicit requirement). Every pre-existing firm-kind tenant is backfilled to
-- the Unlimited plan below — never Starter — so no currently-onboarded firm
-- wakes up capped. New firm-kind tenants created AFTER this migration
-- (self-serve trial signup, or a super_admin's "+ New firm") default to
-- Starter instead — see TrialSignup.php / tenants_create.php — so the gating
-- this session builds is actually reachable to demo, rather than every path
-- landing on Unlimited by default forever.

CREATE TABLE subscription_plans (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(30) NOT NULL UNIQUE COMMENT 'starter | growth | unlimited — stable key, referenced by app code',
    name               VARCHAR(100) NOT NULL,
    max_advisors       INT UNSIGNED NULL COMMENT 'NULL = unlimited advisor seats',
    price_inr_monthly  INT UNSIGNED NULL COMMENT 'informational only — no payment processing reads this (docs/09 Session 8)',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO subscription_plans (code, name, max_advisors, price_inr_monthly) VALUES
    ('starter',   'Starter',   3,    2999),
    ('growth',    'Growth',    10,   7999),
    ('unlimited', 'Unlimited', NULL, NULL);

ALTER TABLE tenants
    ADD COLUMN plan_id INT UNSIGNED NULL DEFAULT NULL AFTER kind,
    ADD KEY idx_tenants_plan (plan_id),
    ADD CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id)
        REFERENCES subscription_plans(id) ON DELETE SET NULL;

-- Explicit backfill — firm-kind only, personal-kind stays NULL by design (see
-- decision record above). This is the one-time "never silently degrade an
-- existing tenant" step; every firm-kind tenant that predates billing gets
-- the top tier, not the entry tier.
UPDATE tenants t
    JOIN subscription_plans p ON p.code = 'unlimited'
    SET t.plan_id = p.id
    WHERE t.kind = 'firm';

SELECT '044_billing_plans  (subscription_plans + tenants.plan_id, firm-kind tenants backfilled to Unlimited)' AS migration, 'applied' AS state;
