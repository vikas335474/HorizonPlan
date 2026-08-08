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
-- missing ones in ascending numeric order.
--
-- Covers 033 onward — the self-serve individual tier and later. Earlier
-- migrations predate the tier and are assumed present on any live deployment;
-- add markers here as new migrations land.

SELECT '033_personal_tenants                  (tenants.kind)' AS migration,
       IF(COUNT(*) > 0, 'applied', 'MISSING')                 AS state
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'tenants'
   AND COLUMN_NAME  = 'kind'

UNION ALL
SELECT '034_financial_foundations             (client_protection table)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'client_protection'

UNION ALL
SELECT '034_financial_foundations             (client_portfolio_items.interest_rate)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'client_portfolio_items'
   AND COLUMN_NAME  = 'interest_rate'

UNION ALL
SELECT '035_household_self_service            (users.display_name)',
       IF(COUNT(*) > 0, 'applied', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'users'
   AND COLUMN_NAME  = 'display_name';
