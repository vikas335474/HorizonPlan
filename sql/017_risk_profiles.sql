-- docs/07 Session C / docs/06 Section B: risk profiler. Ships the MECHANISM
-- only — no HorizonPlan-authored question set or scoring rubric is seeded
-- here. The firm's own CFA/RIA supplies the questions and scoring via
-- risk_question_set_update.php; every new/edited set starts 'draft' and a
-- submitted profile's band cannot surface as a suggested return assumption
-- until a firm principal approves it (docs/06 guardrail 2). No default
-- rubric is ever pre-approved on a firm's behalf.

-- One active question set per tenant (a firm may replace its own set; the
-- history of previous sets is not versioned here — docs/06 does not ask for
-- that, only for an approval state on the current one).
CREATE TABLE risk_question_sets (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    questions_json       JSON NOT NULL COMMENT 'array of {id, text, options:[{value,score}]}',
    scoring_rubric_json  JSON NOT NULL COMMENT '{"bands":[{"name","min","max","suggested_return_pct"}, ...]}',
    status               ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
    approved_by_user_id  INT UNSIGNED NULL,
    approved_at          TIMESTAMP NULL,
    created_by_user_id   INT UNSIGNED NOT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_riskquestionsets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_riskquestionsets_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_riskquestionsets_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    -- Enforces "one active question set per tenant" at the DB layer, not just
    -- in application logic — risk_question_set_update.php's upsert relies on
    -- this to never end up creating a second row for the same tenant.
    UNIQUE KEY uq_riskquestionsets_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A submitted client risk profile. Captured regardless of the question set's
-- approval state (docs/06 Section B) — the capture itself is never blocked,
-- only whether its band is later allowed to surface as a suggested return
-- assumption (checked at read time against the referenced question set's
-- CURRENT status, not frozen at submission time).
CREATE TABLE risk_profiles (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    client_id            INT UNSIGNED NOT NULL COMMENT 'FK to users.id, role=client',
    question_set_id      INT UNSIGNED NOT NULL,
    score                INT NOT NULL,
    band                 VARCHAR(50) NOT NULL COMMENT 'band name from the rubric at submission time, e.g. conservative/moderate/aggressive',
    answers_json         JSON NOT NULL,
    created_by_user_id   INT UNSIGNED NOT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_riskprofiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_riskprofiles_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_riskprofiles_questionset FOREIGN KEY (question_set_id) REFERENCES risk_question_sets(id),
    CONSTRAINT fk_riskprofiles_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    KEY idx_riskprofiles_tenant_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
