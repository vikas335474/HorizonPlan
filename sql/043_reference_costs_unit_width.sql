-- Widens reference_costs.unit so the living-cost rows can actually be written.
--
-- THE BUG THIS FIXES. Migration 037 defined `unit VARCHAR(20)`, which fit every
-- unit that existed at the time ('inr_total', 'inr_annual'). The living-cost
-- anchor (LivingCostReference.php) then added a per-capita monthly unit —
-- 'inr_monthly_per_capita', 22 characters — without widening the column. The
-- result was not a silent truncation but a hard failure: MySQL in strict mode
-- raises SQLSTATE[22001] "Data too long for column 'unit'", so
-- syncReferenceCosts() threw part-way through and the ENTIRE living-cost
-- dataset failed to land. The feature shipped unable to store its own data;
-- the quarterly reference-costs cron would have fataled on every run.
--
-- Caught by tests/test_reference_costs_db.php once its stale unit whitelist was
-- corrected — the whitelist had been masking the schema mismatch, because the
-- pure-dataset assertion exited before the DB write was ever reached.
--
-- VARCHAR(32), not (22). The point is headroom for the next unit, so this
-- migration is not needed a third time. Widening a VARCHAR is an in-place
-- metadata-only change in InnoDB when it stays under the 255-byte boundary,
-- so it does not rewrite the table.
--
-- SAFE TO RE-RUN AFTER A PARTIAL SYNC. No data is lost by widening, and
-- syncReferenceCosts() upserts, so simply running the sync (or the manual seed
-- in sql/manual/) after this migration fills in whatever failed to write.

ALTER TABLE reference_costs
    MODIFY COLUMN unit VARCHAR(32) NOT NULL
        COMMENT 'absolute-rupee unit only, never a multiplier: inr_total | inr_annual | inr_monthly_per_capita';

SELECT '043_reference_costs_unit_width  (reference_costs.unit -> VARCHAR(32))' AS migration, 'applied' AS state;
