-- Self-serve trial signup (see api/signup.php). Distinguishes a tenant
-- created by a visitor filling out the public signup form from one an admin
-- created via tenants_create.php — advisory_mode stays Super Admin-only and
-- gated on a real RIA review either way (docs/02 Section 3.6 is completely
-- unaffected by this column: a trial-signup tenant is always forced into
-- 'distribution' mode at creation, same as this column's own default
-- reasoning). This is bookkeeping/reporting metadata, not a security
-- boundary — nothing in TenantScopedDb or any access check reads it.
ALTER TABLE tenants
    ADD COLUMN signup_source ENUM('admin_created', 'trial_signup') NOT NULL DEFAULT 'admin_created' AFTER advisory_mode;

-- A platform-wide kill switch for the public signup endpoint, same pattern as
-- demo_mode/mfa_enforcement (docs/09 Piece 1) — lets a Super Admin pause new
-- trial signups (abuse, capacity, or just "we're done demoing this") without
-- a code deploy.
ALTER TABLE platform_settings
    ADD COLUMN signup_enabled ENUM('off', 'on') NOT NULL DEFAULT 'on' AFTER demo_mode;
