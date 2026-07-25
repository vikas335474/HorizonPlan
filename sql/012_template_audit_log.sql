-- template_audit_log: Full provenance trail for templates.
-- Records every create, fork, customize, share, and usage event.
-- Answers: "where did this template come from, who uses it, how has it evolved?"
CREATE TABLE template_audit_log (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    template_id             INT UNSIGNED NULL COMMENT 'FK to template_strategies.id if this is a base template, else NULL',
    customization_id        INT UNSIGNED NULL COMMENT 'FK to template_customizations.id if this is a customization, else NULL',

    user_id                 INT UNSIGNED NOT NULL COMMENT 'advisor who performed the action',

    action                  VARCHAR(50) NOT NULL COMMENT "'created', 'forked_from', 'customized', 'shared', 'used_in_plan'",

    -- Details of what changed (flexible JSON schema per action type)
    entity_details_json     JSON NULL COMMENT 'what changed: old/new values, source template info, etc.',

    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_template_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_template_audit_template FOREIGN KEY (template_id) REFERENCES template_strategies(id),
    CONSTRAINT fk_template_audit_customization FOREIGN KEY (customization_id) REFERENCES template_customizations(id),
    CONSTRAINT fk_template_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_template_audit_entity (tenant_id, template_id, customization_id),
    KEY idx_template_audit_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
