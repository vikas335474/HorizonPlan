-- Tenants: one row per advisory firm / MFD / RIA.
CREATE TABLE tenants (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name        VARCHAR(255) NOT NULL,
    white_label_settings TEXT NULL COMMENT 'JSON: logo, primary color, branding assets',

    -- Section 3.6: gated server-side, never self-serve. Super Admin only.
    advisory_mode       ENUM('distribution', 'advisory') NOT NULL DEFAULT 'distribution',

    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
