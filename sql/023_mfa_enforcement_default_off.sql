-- Flip mandatory-MFA-enrollment's default OFF while the platform is still in
-- its initial demo/early-access period (no real advisor/client data yet).
-- The toggle mechanism itself (platform_settings.mfa_enforcement, the
-- AdminConsole "Platform" tab, requireMfaEnrollment()) was already built and
-- already worked correctly — the only problem was that both the schema
-- default AND the already-seeded row started life as 'enabled', so every
-- fresh signup (including the new self-serve trial flow) hit a mandatory
-- "set up an authenticator app" wall before they'd done anything else.
--
-- This does not remove or weaken MFA: users can still enroll voluntarily
-- (login.php/mfa_verify.php/Settings.jsx are all unchanged), and a
-- super_admin can flip mfa_enforcement back to 'enabled' from the Platform
-- tab the moment real user data is in and enforcement is actually wanted.
ALTER TABLE platform_settings
    MODIFY mfa_enforcement ENUM('enabled', 'disabled') NOT NULL DEFAULT 'disabled';

UPDATE platform_settings SET mfa_enforcement = 'disabled' WHERE id = 1;
