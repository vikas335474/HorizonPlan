-- change_log: every mutation to base_plans/sub_scenarios writes here.
-- Section 3.3 — this is the only record of what assumptions a client was shown
-- and when. Required functionality, not optional telemetry.
CREATE TABLE change_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    entity_type         VARCHAR(50) NOT NULL COMMENT "'base_plan' or 'sub_scenario'",
    entity_id           INT UNSIGNED NOT NULL,
    field_changed       VARCHAR(100) NOT NULL,
    old_value           TEXT NULL,
    new_value           TEXT NULL,
    changed_by_user_id  INT UNSIGNED NOT NULL,
    changed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_changelog_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_changelog_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id),
    KEY idx_changelog_entity (tenant_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
