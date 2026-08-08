-- Self-serve individual tier — an individual planning for themselves, with no
-- advisor and no firm.
--
-- THE MODEL: a "tenant of one". An individual signing up gets their own
-- tenant, containing exactly one user, with role='client'. Deliberately NOT a
-- new role:
--
--   * Tenant isolation is already the one security boundary in this codebase
--     (docs/02 §3.1). A tenant of one inherits every guarantee unchanged —
--     one individual can no more see another's plan than one firm can see
--     another firm's.
--   * A new role would have to be reasoned about at every verifyAccess() /
--     verifyAccessAny() call site in api/ (~70 of them). Reusing 'client'
--     means every existing read path — goals, projections, what-if scenarios,
--     portfolio, cash flow, progress — already works for an individual on day
--     one, because a client could always read those.
--
-- WHAT CHANGES: `kind` tells the two apart.
--
--   'firm'     — the existing B2B2C model. A client's data is entered and
--                maintained BY THEIR ADVISOR; the client reads and explores
--                but does not author. Unchanged, and the default for every
--                existing row.
--   'personal' — a tenant of one. The individual authors their own data,
--                because there is nobody else to do it.
--
-- The write-permission gate keys off THIS COLUMN, never off the role alone.
-- That is the whole point: if self-service writes were granted to the 'client'
-- role generally, every advisor-managed client would silently gain the ability
-- to edit their own plan, which would break the Jr->Sr approval workflow and
-- the audit trail's assumption that an advisor authored the advice. See
-- api/lib/SelfService.php.
--
-- DISCLOSURE: advisory_mode gains a third value.
-- The existing two both assert a professional intermediary — distribution
-- mode assumes an MFD, and advisory mode's copy literally reads "Prepared by
-- your SEBI-registered investment adviser as part of your personalised
-- financial plan." For someone planning alone BOTH are false statements, and
-- the disclosure is a non-negotiable rule (CLAUDE.md rule #3), so it has to be
-- true, not merely present. 'self_directed' carries copy that says what is
-- actually happening: the person built this themselves, from figures they
-- entered, as an illustration rather than advice.
--
-- No backfill and no behaviour change for anything that exists: every current
-- tenant is 'firm', and advisory_mode's existing values are untouched.

ALTER TABLE tenants
    ADD COLUMN kind ENUM('firm', 'personal') NOT NULL DEFAULT 'firm'
        COMMENT 'firm = advisor-managed (B2B2C); personal = a self-serve individual, tenant of one'
        AFTER company_name,
    MODIFY COLUMN advisory_mode ENUM('distribution', 'advisory', 'self_directed')
        NOT NULL DEFAULT 'distribution'
        COMMENT 'drives the compliance disclosure copy; self_directed is the no-adviser case';

-- signup_source records HOW a tenant was created, distinct from WHAT it is.
-- A personal tenant is always self-created, but keeping the two columns
-- separate means "how did they arrive" stays answerable independently of
-- "what kind of account is this".
ALTER TABLE tenants
    MODIFY COLUMN signup_source ENUM('admin_created', 'trial_signup', 'personal_signup')
        NOT NULL DEFAULT 'admin_created';

CREATE INDEX idx_tenants_kind ON tenants (kind);
