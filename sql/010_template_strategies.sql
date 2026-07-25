-- template_strategies: Global SaaS templates + advisor-created templates.
-- Global templates have is_system_template=1, creator_org_id=NULL.
-- Advisor-created have is_system_template=0, creator_user_id set, creator_org_id=tenant_id.
CREATE TABLE template_strategies (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    creator_user_id         INT UNSIGNED NULL COMMENT 'advisor who created this template, NULL for system',
    creator_org_id          INT UNSIGNED NULL COMMENT 'tenant_id of creator, NULL for system-created',

    template_name           VARCHAR(255) NOT NULL,
    description             TEXT NULL,
    market_code             CHAR(2) NOT NULL DEFAULT 'IN' COMMENT 'market/region code',

    -- Asset allocation as JSON: {"equity": 60, "debt": 30, "gold": 10, "cash": 0}
    allocation_json         JSON NOT NULL,

    -- Expected return assumption (e.g. 8.5 = 8.5%)
    return_assumption_pct   DECIMAL(4,2) NULL,

    -- Risk profile: conservative, moderate, aggressive, etc.
    risk_profile_enum       VARCHAR(50) NULL COMMENT 'e.g. conservative, moderate, aggressive',

    -- Lifecycle flags
    is_system_template      TINYINT(1) DEFAULT 0 COMMENT '1 = global SaaS template, 0 = advisor-created',
    is_published            TINYINT(1) DEFAULT 0 COMMENT '1 = shared/published, 0 = draft',

    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at            TIMESTAMP NULL,

    CONSTRAINT fk_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_templates_creator FOREIGN KEY (creator_user_id) REFERENCES users(id),
    KEY idx_templates_tenant (tenant_id),
    KEY idx_templates_system (is_system_template),
    KEY idx_templates_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
