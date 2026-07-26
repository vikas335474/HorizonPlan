-- Google Sign-In: lets a user authenticate with an existing Google account
-- instead of password+TOTP. A successful Google login is treated as
-- satisfying the mandatory-MFA requirement in its own right (see
-- security_gatekeeper.php::userHasMfaEnrolled()) — this is a deliberate
-- product decision, not a downgrade: Google's own account security plus a
-- verified email stands in for the second factor.
--
-- google_sub stores Google's stable, non-reassignable subject identifier
-- (the JWT "sub" claim) for the linked account — never the email, since an
-- email can be reused/changed on Google's side. NULL until the user's first
-- successful Google sign-in auto-links it (matched by verified email against
-- an existing, admin-provisioned users row — this app has no self-serve
-- signup, so Google login can never create a new account, only authenticate
-- into one that already exists).
ALTER TABLE users
    ADD COLUMN google_sub VARCHAR(255) NULL AFTER mfa_secret,
    ADD UNIQUE KEY uq_users_google_sub (google_sub);
