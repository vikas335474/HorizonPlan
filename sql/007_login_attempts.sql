-- login_attempts: rate limiting, per docs/02 Section 3.2. Deliberately not tied to a
-- users.id FK — a failed attempt may be for an email that doesn't exist, and we still
-- want to rate-limit by that email/IP to avoid leaking whether an account exists.
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(191) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL COMMENT 'VARCHAR(45) to fit IPv6',
    successful   BOOLEAN NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_attempts_email_time (email, attempted_at),
    KEY idx_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
