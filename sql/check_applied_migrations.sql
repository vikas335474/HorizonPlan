-- Which migrations does THIS database already have?
--
-- Read-only. Safe to paste into hPanel -> Databases -> phpMyAdmin (or any SQL
-- console) against production, staging, or a scratch DB. It writes nothing and
-- locks nothing — it only reads information_schema.
--
-- WHY THIS EXISTS. The migrations in /sql are applied by hand (see DEPLOY.md)
-- and nothing records which have been run. They are also NOT idempotent — no
-- `IF NOT EXISTS` guards — so re-running an applied one raises a duplicate
-- column/table error rather than quietly doing nothing. That error is harmless,
-- but "just re-run them all and see what errors" is a poor way to find out what
-- state a production database is in. This answers the question first.
--
-- Each row reports one SCHEMA MARKER — a table or column a migration adds.
-- Migrations that make more than one structural change get more than one row,
-- deliberately: a migration can fail partway (the CREATE TABLE succeeds, the
-- following ALTER does not), and a single row per file would report that
-- half-applied state as a clean "applied".
--
-- 'MISSING' means the migration has not been applied to this database. Apply
-- missing ones in ASCENDING NUMERIC ORDER, one file at a time, checking each
-- succeeds before starting the next.
--
-- *** RUN THIS FROM INSIDE THE TARGET DATABASE, NOT A SERVER-LEVEL SQL TAB. ***
-- Every marker below filters on `TABLE_SCHEMA = DATABASE()`. If no database is
-- selected in the SQL client this runs from (e.g. phpMyAdmin's top-level
-- "SQL" tab, rather than the tab reached by clicking into the database itself
-- in the sidebar first), DATABASE() returns NULL — and `TABLE_SCHEMA = NULL`
-- is never true in SQL, for any row. Every single marker then reports MISSING
-- at once, symmetrically, even against a fully-migrated database. This is not
-- hypothetical: it happened on this project's own production database, and
-- the tables were all provably there via a plain SHOW TABLES.
--
-- The first row below exists SOLELY to catch that failure mode by making it
-- self-diagnosing: it always shows which database this actually ran against,
-- right in the same result grid as everything else, so a wrong-context run
-- can't be silently misread as "nothing is applied".
--
-- COVERS 024 THROUGH 043. This file used to cover only 033-035, which meant it
-- could not answer the question it exists to answer for two thirds of the
-- migrations that had shipped — a database missing 037 or 040 was reported as
-- fully up to date. Add a marker here whenever a new migration lands; a
-- migration with no marker is invisible to this check.
--
-- ONE SPECIAL CASE, 043. That migration WIDENS an existing column rather than
-- adding one, so "does reference_costs.unit exist" is true either way and would
-- report applied on an unmigrated database. Its marker checks the column's
-- LENGTH instead. This matters: without 043 the living-cost rows cannot be
-- written at all (SQLSTATE[22001]) and the reference-costs cron fatals on every
-- run, while the column itself looks perfectly present.

SELECT '000_SANITY_CHECK                      (which database is this running against?)' AS migration,
       COALESCE(
           CONCAT('Running against: ', DATABASE()),
           'NO DATABASE SELECTED — every row below is a false MISSING. Re-run this from inside the target database (click its name in phpMyAdmin''s sidebar first), not a server-level SQL tab.'
       ) AS state

UNION ALL
SELECT '024_platform_settings_reset_cooldown  (platform_settings.last_reset_at)' AS migration,
       IF(COUNT(*) > 0, 'applied', 'MISSING')                                    AS state
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_settings' AND COLUMN_NAME = 'last_reset_at'

UNION ALL
SELECT '025_password_resets_purpose           (password_resets.purpose)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose'

UNION ALL
SELECT '026_plan_review                       (tenants.requires_plan_review)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'requires_plan_review'

UNION ALL
SELECT '026_plan_review                       (base_plans.review_status)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_plans' AND COLUMN_NAME = 'review_status'

UNION ALL
SELECT '027_mf_nav_sync                       (client_portfolio_items.amfi_scheme_code)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'amfi_scheme_code'

UNION ALL
SELECT '027_mf_nav_sync                       (mf_nav_cache table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mf_nav_cache'

UNION ALL
SELECT '028_plan_review_schedule              (plan_review_schedules table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_review_schedules'

UNION ALL
SELECT '029_households                        (households table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households'

UNION ALL
SELECT '029_households                        (users.household_id)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'household_id'

UNION ALL
SELECT '030_cash_flow                         (cash_flow_items table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_flow_items'

UNION ALL
SELECT '031_client_advisor_assignment         (users.assigned_advisor_id)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'assigned_advisor_id'

UNION ALL
SELECT '032_progress_snapshots                (goal_snapshots table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_snapshots'

UNION ALL
SELECT '032_progress_snapshots                (client_net_worth_snapshots table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_net_worth_snapshots'

UNION ALL
SELECT '033_personal_tenants                  (tenants.kind)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'kind'

UNION ALL
SELECT '034_financial_foundations             (client_protection table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_protection'

UNION ALL
SELECT '034_financial_foundations             (client_portfolio_items.interest_rate)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'interest_rate'

UNION ALL
SELECT '035_household_self_service            (users.display_name)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'display_name'

UNION ALL
SELECT '036_client_context                    (client_context table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_context'

UNION ALL
SELECT '036_client_context                    (client_dependants table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_dependants'

UNION ALL
SELECT '037_reference_costs                   (reference_costs table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reference_costs'

UNION ALL
SELECT '038_portfolio_reconcile               (client_portfolio_items.source)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'source'

UNION ALL
SELECT '038_portfolio_reconcile               (client_portfolio_items.folio_number)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'folio_number'

UNION ALL
SELECT '039_tax_context                       (client_portfolio_items.fund_type)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'fund_type'

UNION ALL
SELECT '039_tax_context                       (client_portfolio_items.acquisition_value)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portfolio_items' AND COLUMN_NAME = 'acquisition_value'

UNION ALL
SELECT '039_tax_context                       (tax_reference table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tax_reference'

UNION ALL
SELECT '040_product_events                    (product_events table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_events'

UNION ALL
SELECT '041_personal_plan_digest              (users.plan_digest_opt_in)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_digest_opt_in'

UNION ALL
SELECT '042_goal_snapshot_readiness           (goal_snapshots.readiness_score)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_snapshots' AND COLUMN_NAME = 'readiness_score'

-- 043 WIDENS an existing column, so column existence proves nothing — see the
-- header. The check is on length: < 32 means unmigrated, and the living-cost
-- rows cannot be stored until it is fixed.
UNION ALL
SELECT '043_reference_costs_unit_width        (reference_costs.unit >= VARCHAR(32))',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reference_costs' AND COLUMN_NAME = 'unit'
   AND CHARACTER_MAXIMUM_LENGTH >= 32;
