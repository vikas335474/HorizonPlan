# HorizonPlan

B2B2C retirement planning platform for Indian MFDs/IFAs and SEBI-RIA firms. Full context lives in `/docs/` — read the relevant file before starting work on that area, don't assume this file alone is enough.

## Stack
PHP (no framework) + PDO + MySQL, hosted on Hostinger Premium. React SPA (Tailwind, shadcn/ui), pre-compiled — Hostinger serves static files, it does not run a Node build step, so the frontend is always built before deploy (see `.github/workflows/`).

## Docs — read before touching the matching area
- `docs/01_architecture_review.md` — original security/architecture audit. Read before touching auth or the data-access layer.
- `docs/02_project_instructions.md` — the actual build spec: schema, security rules, tenant model. **Read this before writing any backend code.**
- `docs/03_roadmap_and_prompts.md` — Phase 1 (MVP) build order. Follow this sequence; don't build Phase 2+ items without checking `04` first.
- `docs/04_feature_roadmap.md` — what belongs in MVP vs. later, and why. Check the "explicitly out of scope" list before adding anything not asked for.
- `docs/05_practitioner_validation_review.md` — why certain features exist (e.g. India-specific withdrawal rates, sequence-of-returns chart).

## Non-negotiable rules (always apply, every session)
1. **Tenant isolation:** every query on tenant-scoped data goes through `api/lib/TenantScopedDb.php`. Never write a raw `WHERE tenant_id = :tenant_id` inline in an endpoint file — if the helper doesn't exist yet, build it first (see `docs/02`, Section 3.1).
2. **`advisory_mode` on tenants is Super Admin-only**, enforced server-side, never a self-serve toggle. See `docs/02` Section 3.6.
3. **Distribution-mode disclosure copy** renders on every client-facing plan view, and directly adjacent to the withdrawal-rate slider and projection chart specifically.
4. Every mutation to `base_plans` / `sub_scenarios` writes to `change_log`.
5. No credentials in the repo. `db_config.php` stays out of git.
6. Don't build anything from `docs/04`'s "explicitly out of scope" lists without checking the phase validation gate first.

## Current phase
Phase 1 (MVP) — see `docs/03` for build order. Work through it in sequence; each phase there is meant to be a separate, focused session rather than one long continuous one.

## Deploy
Hostinger's native Git deployment (hPanel → Advanced → Git) watches a `deploy` branch and auto-deploys on push — no SSH needed for this path. GitHub Actions builds the React app and pushes the compiled output to `deploy`; `main` stays the working branch. SQL migrations in `/sql` are **not** run automatically by Hostinger's git-deploy — run new migrations manually via hPanel's database tool after each deploy that includes one.
