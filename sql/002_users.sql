-- Users: Super Admin / Advisor / Client, scoped to a tenant.
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    email           VARCHAR(191) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL COMMENT 'password_hash(), never store plaintext or call this "encrypted"',
    role            ENUM('super_admin', 'advisor', 'client') NOT NULL,
    mfa_secret      VARCHAR(255) NULL COMMENT 'TOTP secret, set once MFA is enabled for this user',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
