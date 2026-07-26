-- Jr -> Sr Advisor Plan-Approval Workflow (decide-then-build session).
-- Decisions confirmed with the user before this file was written:
--   1. Trigger: only the "advice" fields (withdrawal_rate, drawdown_return_rate,
--      template application) put a goal into the review workflow — not every
--      base_plans edit.
--   2. No hard gate anywhere yet: an unapproved goal still renders fully in
--      PlanReport.jsx/MeetingMode.jsx. Badge + audit trail only, for now.
--   3. Opt-in per tenant, default off — existing firms are unaffected until a
--      firm_admin/super_admin explicitly turns this on for their own tenant.
--   4. Three states, not binary: pending_review / approved / changes_requested,
--      so a reviewer's rejection isn't silent.
--   5. Any sr_advisor/firm_admin in the firm can review (matches
--      requireFirmRole()'s existing model) — no assigned-reviewer routing.
--   6. Editing an already-approved goal's advice fields reverts it to
--      pending_review, same precedent as templates_customize.php /
--      risk_question_set_update.php reverting an approved item to draft on edit.

ALTER TABLE tenants
    ADD COLUMN requires_plan_review ENUM('on', 'off') NOT NULL DEFAULT 'off' AFTER advisory_mode;

-- review_status defaults to 'not_required' for every existing goal — correct
-- regardless of a tenant's requires_plan_review value, since no tenant has
-- ever had this feature until now (default 'off' above) and there is no hard
-- gate this migration could silently trip anyone into. No existing goal
-- becomes blocked or hidden by this migration.
ALTER TABLE base_plans
    ADD COLUMN review_status ENUM('not_required', 'pending_review', 'approved', 'changes_requested') NOT NULL DEFAULT 'not_required' AFTER applied_customization_id,
    ADD COLUMN reviewed_by_user_id INT UNSIGNED NULL AFTER review_status,
    ADD COLUMN reviewed_at TIMESTAMP NULL AFTER reviewed_by_user_id,
    ADD COLUMN review_notes TEXT NULL COMMENT 'reviewer feedback when review_status = changes_requested' AFTER reviewed_at,
    ADD CONSTRAINT fk_baseplans_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id);
