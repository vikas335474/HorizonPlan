-- Password reset tokens: self-service "forgot password" flow.
--
-- Only a SHA-256 HASH of the token is stored — the raw token exists only in
-- the emailed link and the user's browser URL bar, never in the database.
-- This deliberately differs from mfa_pending's stored-raw-token pattern: a
-- leaked mfa_pending token is useless without ALSO knowing the account's
-- password (it's step 2 of an already-password-verified flow), but a leaked
-- password-reset token is a standalone full account takeover on its own — so
-- this table gets the extra layer that a raw-secret handshake token doesn't
-- need. A DB dump/leak here exposes no usable tokens.
CREATE TABLE password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw token; the raw token itself is never stored',
    user_id     INT UNSIGNED NOT NULL,
    expires_at  TIMESTAMP    NOT NULL,
    used_at     TIMESTAMP    NULL COMMENT 'set once consumed; a used token can never be redeemed twice',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_password_resets_token_hash (token_hash),
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
