-- Template approval workflow (docs/07 Bet 1). An unapproved template or
-- customization is illustration-only — it can be browsed and forked, but
-- api/goals_apply_template.php refuses to apply it to a real client's goal
-- until this flips to 'approved'. Every newly created/forked row starts
-- 'draft' by default; approval is always a deliberate second step (see
-- api/templates_approve.php), never implied by creation, even for a
-- super_admin creating a global template.
ALTER TABLE template_strategies
    ADD COLUMN approval_status     ENUM('draft', 'approved') NOT NULL DEFAULT 'draft' AFTER is_published,
    ADD COLUMN approved_by_user_id INT UNSIGNED NULL AFTER approval_status,
    ADD COLUMN approved_at         TIMESTAMP NULL AFTER approved_by_user_id,
    ADD CONSTRAINT fk_templates_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id);

ALTER TABLE template_customizations
    ADD COLUMN approval_status     ENUM('draft', 'approved') NOT NULL DEFAULT 'draft' AFTER is_shareable,
    ADD COLUMN approved_by_user_id INT UNSIGNED NULL AFTER approval_status,
    ADD COLUMN approved_at         TIMESTAMP NULL AFTER approved_by_user_id,
    ADD CONSTRAINT fk_customizations_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id);

-- 'approved' joins the existing action vocabulary — a template being marked
-- ready for real client use is itself a provenance event, same as any other
-- row in this log.
ALTER TABLE template_audit_log
    MODIFY COLUMN action VARCHAR(50) NOT NULL COMMENT "'created', 'forked_from', 'customized', 'approved', 'shared', 'used_in_plan'";
