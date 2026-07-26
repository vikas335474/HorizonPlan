-- docs/09 Group 2 Session 4 — magic-link invite activation. An invite is
-- conceptually identical to a password reset (redeem a single-use, hashed
-- token → set a password → invalidate other tokens → clear active sessions)
-- except the account has no usable password yet and a real admin-provisioned
-- invite might not be opened same-day, so it needs a much longer expiry than
-- a 30-minute "forgot password" link. `purpose` lets password_reset_confirm.php
-- keep working unchanged for both (the redemption logic doesn't need to
-- branch on it) while password_reset_request.php / the new invite issuer can
-- each set their own expires_at.
ALTER TABLE password_resets
    ADD COLUMN purpose ENUM('reset', 'invite') NOT NULL DEFAULT 'reset' AFTER user_id;
