-- MFA pending tokens: short-lived handshake records used in two separate flows:
--
--   1. Enroll confirm: after mfa_enroll.php generates a TOTP secret and the user
--      has set up their authenticator app, mfa_enroll_confirm.php verifies the
--      first OTP against this record before writing to users.mfa_secret.
--
--   2. Login step 2: after login.php verifies email+password for an already-enrolled
--      user, it issues a pending token here instead of a full session. The user's
--      OTP code is then submitted to mfa_verify.php, which checks this record and
--      issues a real active_sessions row on success.
--
-- purpose column distinguishes the two flows so a token issued for enrollment
-- cannot be used as a login-step-2 token and vice versa.
--
-- Tokens expire in 10 minutes — long enough for a user to open their
-- authenticator app, short enough to limit the exposure window if intercepted.

CREATE TABLE mfa_pending (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token       CHAR(64)     NOT NULL  COMMENT 'random_bytes(32) as hex',
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('enroll', 'login') NOT NULL,
    payload     TEXT         NULL      COMMENT 'For enroll purpose: the base32 TOTP secret, held server-side until confirm. NULL for login purpose.',
    expires_at  TIMESTAMP    NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_mfa_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mfa_pending_token (token),
    KEY idx_mfa_pending_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
