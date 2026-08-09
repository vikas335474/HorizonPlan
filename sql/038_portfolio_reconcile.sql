-- docs/12 Prompt D-1 · Portfolio input overhaul — reconcile-by-holding CAS
-- import (instead of the old always-insert behavior, which silently
-- duplicated every holding on a second upload).
--
-- WHY source EXISTS. A CAS re-import must only ever touch rows a PRIOR CAS
-- import created — never a hand-entered holding, even if its fund name
-- happens to match text in the new statement. Without this flag, a
-- reconcile pass has no honest way to know which rows it is allowed to
-- update/flag; scoping to source='cas_import' is the safety boundary, the
-- same category of decision as bucket/interest_rate only meaning something
-- for one item_kind. NOT NULL DEFAULT 'manual' backfills every existing row
-- correctly with no separate backfill step: every row in this table before
-- this migration was in fact hand-entered (the old import path never
-- tagged anything, and didn't need to — it never reconciled either).
ALTER TABLE client_portfolio_items
    ADD COLUMN source ENUM('manual', 'cas_import') NOT NULL DEFAULT 'manual'
        COMMENT 'manual = advisor/individual typed it in; cas_import = created or last reconciled by a CAS/MFCentral CSV import. Reconciliation only ever matches/updates/flags cas_import rows.'
        AFTER category;

-- WHY folio_number EXISTS, SEPARATE FROM description. A real CAS export's
-- reliable match key across two different uploads (weeks/months apart) is
-- (folio number, scheme name) — not the free-text `description` this table
-- already stores, which previously had the folio baked INTO the text
-- ("HDFC Flexi Cap Fund (Folio 12345678)") purely for display, unusable as
-- a clean join key. Nullable: a manual entry, or a CAS row whose statement
-- had no folio column mapped, has none — reconciliation for that row falls
-- back to normalised-scheme-name matching, flagged lower-confidence in the
-- import preview (see api/lib/PortfolioReconcile.php).
ALTER TABLE client_portfolio_items
    ADD COLUMN folio_number VARCHAR(40) NULL
        COMMENT 'CAS folio number, when the imported statement carried one. NULL for manual entries and for CAS rows whose statement had no folio column mapped.'
        AFTER amfi_scheme_code;
