-- docs/09 Piece 2 — a firm-level role hierarchy within the 'advisor' role.
-- role stays unchanged (super_admin/advisor/client); firm_role is a
-- sub-classification that only ever applies to advisor-role users.
ALTER TABLE users
    ADD COLUMN firm_role ENUM('jr_advisor', 'sr_advisor', 'firm_admin') NULL
    AFTER role;

-- Existing advisor rows (and every super_admin/client row) get NULL. NULL on
-- an advisor is treated as sr_advisor by requireFirmRole() for backward
-- compatibility — no existing advisor loses capability from this migration.
