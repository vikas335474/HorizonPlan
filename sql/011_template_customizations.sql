-- template_customizations: Forked/customized versions of templates.
-- When an advisor forks a template, a row here is created with base_template_id pointing to the original.
-- Preserves provenance: can always trace back to the original template.
CREATE TABLE template_customizations (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    base_template_id        INT UNSIGNED NOT NULL COMMENT 'FK to template_strategies.id - original template this was forked from',

    template_name           VARCHAR(255) NOT NULL,
    description             TEXT NULL,

    -- Override fields: if NULL, inherits from base template
    allocation_json         JSON NULL COMMENT 'overrides base template allocation',
    return_assumption_pct   DECIMAL(4,2) NULL COMMENT 'overrides base template return assumption',

    -- Sharing controls
    is_private              TINYINT(1) DEFAULT 1 COMMENT '1 = only this org, 0 = shareable with others',
    is_shareable            TINYINT(1) DEFAULT 0 COMMENT '1 = actively shared with other orgs',

    -- Metadata
    created_by_user_id      INT UNSIGNED NOT NULL COMMENT 'advisor who created this customization',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_modified_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_customizations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_customizations_base_template FOREIGN KEY (base_template_id) REFERENCES template_strategies(id),
    CONSTRAINT fk_customizations_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    KEY idx_customizations_tenant (tenant_id),
    KEY idx_customizations_base_template (base_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
