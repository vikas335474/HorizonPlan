-- Active sessions: referenced by the original blueprint's security_gatekeeper.php
-- (SELECT user_id, tenant_id, role FROM active_sessions WHERE token = ... AND expires_at > NOW())
-- but never formally defined in the blueprint's schema section. Added here so that code
-- actually has a table to run against. tenant_id and role are denormalized from users
-- deliberately, matching the original gatekeeper's single-query lookup pattern.
CREATE TABLE active_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token       CHAR(64) NOT NULL COMMENT 'random_bytes(32) as hex, generated at login',
    user_id     INT UNSIGNED NOT NULL,
    tenant_id   INT UNSIGNED NOT NULL,
    role        ENUM('super_admin', 'advisor', 'client') NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP NOT NULL,

    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    UNIQUE KEY uq_sessions_token (token),
    KEY idx_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
