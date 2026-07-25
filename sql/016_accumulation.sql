-- docs/07 Session C / docs/06 Section A: accumulation-phase modelling.
-- Broadens the existing decumulation-only projection to cover the saving
-- years before retirement. All columns NULL — only meaningful for
-- goal_type='retirement', same nullable-for-other-goal-types pattern as
-- withdrawal_rate/drawdown_return_rate in 004_base_plans.sql.
ALTER TABLE base_plans
    ADD COLUMN accumulation_return_rate DECIMAL(4,2) NULL
        COMMENT 'pre-retirement expected return. Guardrail: never merge with drawdown_return_rate — docs/05 item 1, docs/06 guardrail 1.'
        AFTER drawdown_return_rate,
    ADD COLUMN current_age SMALLINT UNSIGNED NULL AFTER accumulation_return_rate,
    ADD COLUMN retirement_age SMALLINT UNSIGNED NULL
        COMMENT 'years-to-retirement = retirement_age - current_age'
        AFTER current_age,
    ADD COLUMN monthly_sip_amount DECIMAL(15,2) NULL COMMENT 'recurring monthly contribution during accumulation' AFTER retirement_age,
    ADD COLUMN sip_step_up_rate DECIMAL(4,2) NULL COMMENT 'optional annual SIP increase, e.g. 10.00 = 10%/year' AFTER monthly_sip_amount;

-- Matching custom_* override columns on sub_scenarios, joined to the existing
-- single is_overridden flag (docs/06 guardrail 4 — no second override flag).
-- current_age/retirement_age are NOT overridable per sub-scenario: a what-if
-- variant changes assumptions (rates, contribution), not the client's age.
ALTER TABLE sub_scenarios
    ADD COLUMN custom_accumulation_return_rate DECIMAL(4,2) NULL AFTER custom_drawdown_return_rate,
    ADD COLUMN custom_monthly_sip_amount DECIMAL(15,2) NULL AFTER custom_accumulation_return_rate,
    ADD COLUMN custom_sip_step_up_rate DECIMAL(4,2) NULL AFTER custom_monthly_sip_amount;
