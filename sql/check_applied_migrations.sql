-- Which migrations does THIS database already have?
--
-- Read-only. Safe to paste into hPanel -> Databases -> phpMyAdmin (or any SQL
-- console) against production, staging, or a scratch DB. It writes nothing and
-- locks nothing -- it only reads information_schema.
--
--   RUN ALL THREE STATEMENTS TOGETHER. The SET lines below are part of the
--   check; pasting only the SELECT will report UNKNOWN.
--
-- If the `state` column says UNKNOWN, no database was selected. Either pick
-- your database in phpMyAdmin's left sidebar and re-run, or name it explicitly
-- by replacing NULL on the @override line, e.g.
--
--     SET @override := 'u123456_horizonplan';
--
-- WHY THIS EXISTS. The migrations in /sql are applied by hand (see DEPLOY.md)
-- and nothing records which have been run. They are also NOT idempotent -- no
-- `IF NOT EXISTS` guards -- so re-running an applied one raises a duplicate
-- column/table error rather than quietly doing nothing. That error is harmless,
-- but "just re-run them all and see what errors" is a poor way to find out what
-- state a production database is in. This answers the question first.
--
-- Each row reports one SCHEMA MARKER -- a table or column a migration adds.
-- Migrations that make more than one structural change get more than one row,
-- deliberately: a migration can fail partway (the CREATE TABLE succeeds, the
-- following ALTER does not), and a single row per file would report that
-- half-applied state as a clean "applied".
--
-- Three states, and the distinction between the last two is the whole point:
--   applied  -- the marker is present.
--   MISSING  -- the marker is absent. Apply it (ascending numeric order).
--   UNKNOWN  -- no database was selected, so nothing could be inspected.
--
-- UNKNOWN exists because an earlier version of this file did not have it. It
-- resolved the schema with DATABASE() alone, which is NULL when no database is
-- selected -- so `TABLE_SCHEMA = NULL` matched nothing and every marker came
-- back MISSING on a database that in fact had them all. Reporting "not applied"
-- for "could not tell" is the dangerous direction to fail in: it invites you to
-- re-run migrations you have already run. An unknown schema now says so.
--
-- Covers 033 onward -- the self-serve individual tier and later. Earlier
-- migrations predate the tier and are assumed present on any live deployment;
-- add markers here as new migrations land.

-- Replace NULL with 'your_database_name' if the check reports UNKNOWN.
SET @override := NULL;
SET @db       := COALESCE(@override, DATABASE());

SELECT COALESCE(@db, '(no database selected)') AS schema_checked,
       '033_personal_tenants        (tenants.kind)' AS migration,
       IF(@db IS NULL, 'UNKNOWN', IF(COUNT(*) > 0, 'applied', 'MISSING')) AS state
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db
   AND TABLE_NAME   = 'tenants'
   AND COLUMN_NAME  = 'kind'

UNION ALL
SELECT COALESCE(@db, '(no database selected)'),
       '034_financial_foundations   (client_protection table)',
       IF(@db IS NULL, 'UNKNOWN', IF(COUNT(*) > 0, 'applied', 'MISSING'))
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db
   AND TABLE_NAME   = 'client_protection'

UNION ALL
SELECT COALESCE(@db, '(no database selected)'),
       '034_financial_foundations   (client_portfolio_items.interest_rate)',
       IF(@db IS NULL, 'UNKNOWN', IF(COUNT(*) > 0, 'applied', 'MISSING'))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db
   AND TABLE_NAME   = 'client_portfolio_items'
   AND COLUMN_NAME  = 'interest_rate'

UNION ALL
SELECT COALESCE(@db, '(no database selected)'),
       '035_household_self_service  (users.display_name)',
       IF(@db IS NULL, 'UNKNOWN', IF(COUNT(*) > 0, 'applied', 'MISSING'))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db
   AND TABLE_NAME   = 'users'
   AND COLUMN_NAME  = 'display_name';
