-- docs/09 Piece 1 — a single-row, platform-wide configuration table. Lets a
-- SaaS-provider admin toggle between production behavior (mandatory MFA,
-- real emails) and demo behavior (MFA skipped, emails suppressed, one-click
-- data reset) without a code deploy. Only one row ever exists — enforced by
-- convention (id is always 1, seeded here) and by every reader/writer in
-- api/lib always targeting id = 1, not a DB constraint.
CREATE TABLE platform_settings (
    id                  INT UNSIGNED PRIMARY KEY DEFAULT 1,
    mfa_enforcement     ENUM('enabled', 'disabled') NOT NULL DEFAULT 'enabled',
    demo_mode           ENUM('off', 'on') NOT NULL DEFAULT 'off',
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (id) VALUES (1);
