-- docs/07 Bet 1: closes the loop between the template library and real
-- plans. Applying an approved template/customization to a retirement goal
-- (api/goals_apply_template.php) writes its return_assumption_pct into
-- drawdown_return_rate and records which source it came from here, so
-- provenance flows forward into the plan, not just backward into
-- template_audit_log. Exactly one of the two should be set at a time — kept
-- as two nullable columns rather than a polymorphic (type, id) pair so both
-- can carry a real FK constraint.
ALTER TABLE base_plans
    ADD COLUMN applied_template_id      INT UNSIGNED NULL AFTER drawdown_return_rate,
    ADD COLUMN applied_customization_id INT UNSIGNED NULL AFTER applied_template_id,
    ADD CONSTRAINT fk_baseplans_applied_template FOREIGN KEY (applied_template_id) REFERENCES template_strategies(id),
    ADD CONSTRAINT fk_baseplans_applied_customization FOREIGN KEY (applied_customization_id) REFERENCES template_customizations(id);
