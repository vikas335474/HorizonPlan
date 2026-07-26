-- docs/09 Group 1 Session 3 — demo_reset.php cooldown. Decision (see the
-- session writeup in CLAUDE.md): general rate limiting on goals_*/
-- subscenarios_*/platform_settings_* is deferred, no concrete abuse scenario
-- named yet. demo_reset.php is the one exception — a repeated call
-- re-seeds 160 clients (~40s each), a real self-inflicted DoS risk even from
-- a legitimate super_admin session. last_reset_at tracks the most recent
-- reset attempt so demo_reset.php can refuse a second call inside the
-- cooldown window.
ALTER TABLE platform_settings
    ADD COLUMN last_reset_at TIMESTAMP NULL DEFAULT NULL AFTER signup_enabled;
