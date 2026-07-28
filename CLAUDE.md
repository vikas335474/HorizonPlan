# HorizonPlan

B2B2C retirement planning platform for Indian MFDs/IFAs and SEBI-RIA firms. Full context lives in `/docs/` — read the relevant file before starting work on that area, don't assume this file alone is enough.

## Stack
PHP (no framework) + PDO + MySQL, hosted on Hostinger Premium. React SPA (Tailwind, shadcn/ui), pre-compiled — Hostinger serves static files, it does not run a Node build step, so the frontend must be built (`npm run build`) before every deploy. A `.github/workflows/deploy.yml` pipeline now automates this by publishing the compiled output to a `deploy` branch — see "Deploy" below and `DEPLOY.md`. A parallel `deploy-staging.yml` pipeline (push to `develop` → `deploy-staging` branch) publishes to a fully separate staging subdomain/database, for admin/dev/testing work that shouldn't touch production or real trial-signup data — see `DEPLOY.md`'s "Staging environment setup".

## Docs — read before touching the matching area
- `docs/01_HorizonPlan_Architecture_Review.md` — original security/architecture audit. Read before touching auth or the data-access layer.
- `docs/02_HorizonPlan_Project_Instructions.md` — the actual build spec: schema, security rules, tenant model. **Read this before writing any backend code.**
- `docs/03_HorizonPlan_Roadmap_and_Prompts.md` — Phase 1 (MVP) build order. Follow this sequence; don't build Phase 2+ items without checking `04` first.
- `docs/04_HorizonPlan_Feature_Roadmap.md` — what belongs in MVP vs. later, and why. Check the "explicitly out of scope" list before adding anything not asked for.
- `docs/05_HorizonPlan_Practitioner_Validation_Review.md` — why certain features exist (e.g. India-specific withdrawal rates, sequence-of-returns chart).
- `docs/06_HorizonPlan_Phase2_Plan.md` — Phase 2 core: accumulation modelling + risk profiler spec. Read before touching those areas.
- `docs/07_HorizonPlan_Product_Vision_Differentiation.md` — product thesis ("the meeting, the follow-through, the record") and the prioritized differentiation bets with build sequencing. Read this when deciding *what* to build next; `docs/02` still governs *how*.
- `docs/08_HorizonPlan_Demo_Readiness_and_Gap_Analysis.md` — demo-readiness assessment and prioritized gap list.
- `docs/09_HorizonPlan_PreLaunch_Hardening_Session_Prompts.md` (+ `docs/09_Demo_Prep_Session_Prompt.md`) — the pre-launch hardening + demo-prep session prompts. Most are built (see history log); a few remain (billing, analytics).
- `docs/10_HorizonPlan_Competitive_Analysis_and_Feature_Backlog.md` — honest competitor comparison, sourced user feedback, and the **prioritized value-add backlog** (P0–P3) with ready-to-run session prompts. Read this when deciding what to build next.
- **`docs/CHANGELOG_SESSION_HISTORY.md`** — the full verbatim session-by-session build narrative and detailed security-audit findings that used to live in this file. Read it when you need the *why*/*how-verified* behind a specific piece, not just *what exists*.
- **`docs/DEVELOPER_GUIDE.md`** — onboarding for a developer new to the code: architecture, request lifecycle, the tenant-isolation model, the security helpers, the test harness, local dev setup, and a "how to add a new endpoint safely" checklist. Read this before your first change to any backend/frontend area; the area-specific `docs/0x` files still govern the *how* of each feature.
- `docs/USER_GUIDE.html` — self-contained printable user guide: per-persona (super_admin / firm_admin / sr_advisor / jr_advisor / client) capabilities + a plain-language tour of every feature. Aimed at a non-technical advisor evaluating or onboarding.
- `docs/HorizonPlan_One_Pager.html` — the marketing one-pager (self-contained, print-friendly, theme-aware). Keep it in sync with the capability index below when features ship.

## Non-negotiable rules (always apply, every session)
1. **Tenant isolation:** every query on tenant-scoped data goes through `api/lib/TenantScopedDb.php`. Never write a raw `WHERE tenant_id = :tenant_id` inline in an endpoint file — if the helper doesn't exist yet, build it first (see `docs/02`, Section 3.1). (A few read endpoints that need a `JOIN` the helper can't express — `clients_list.php`, `change_log_list.php`, `goals_review_queue.php` — drop to a raw *tenant-scoped* query; that's the one documented exception pattern, always still tenant-filtered.)
2. **`advisory_mode` on tenants is Super Admin-only**, enforced server-side, never a self-serve toggle (a `firm_admin` can self-serve branding but NOT `advisory_mode`). See `docs/02` Section 3.6.
3. **Distribution-mode disclosure copy** renders on every client-facing plan view, and directly adjacent to the withdrawal-rate slider and projection chart specifically.
4. Every mutation to `base_plans` / `sub_scenarios` writes to `change_log`.
5. No credentials in the repo. `db_config.php` stays out of git.
6. Don't build anything from `docs/04`'s "explicitly out of scope" lists without checking the phase validation gate first.

## Working conventions (how sessions here operate)
- **Decide-then-build:** for anything with a real schema or product decision, confirm the open choices with the user (via `AskUserQuestion`) *before* writing a migration. `docs/09`/`docs/10` prompts are written this way; follow it.
- **Verify for real:** this codebase's bar is a real MySQL instance + a real HTTP request/response cycle + (for UI) a real Playwright browser run — not static review alone. Install `mariadb-server`, apply all `/sql` migrations, run `tests/run_all.sh`, and drive the actual dev servers (`php -S -t api` + `vite dev`). Nearly every entry in the history log was caught or confirmed this way.
- **Guardrail-style caution:** ship the *mechanism*, not an opinion, where the firm should supply content (risk questionnaire, strategy templates); default new firm-wide toggles OFF; never silently degrade an existing tenant on a migration (explicit backfill).
- **Known landmine:** several `tests/*_db.php` files were fixed to wrap their fixtures in transactions (docs/09 Session 2), but there is still no hard guard stopping `tests/run_all.sh` from running against a database that holds real/demo data. Point tests at a disposable DB.

## Current state — what's built (capability index)
Phase 1 (MVP) and the documented Phase 2 core are built, plus a long series of hardening/demo/UX sessions. **This is a *planning-and-conversation* tool, not a transaction back-office** — see `docs/10` for the honest competitive framing (no order routing, KYC, or commission reconciliation, by design). For the full story behind any item below, see `docs/CHANGELOG_SESSION_HISTORY.md`.

**Core planning engine** (`api/lib/PlanMath.php`, pure arithmetic, no DB) — decumulation projection, accumulation (SIP + step-up) → decumulation as one lifecycle, corpus composition (liquid/locked two-bucket, spend-liquid-first), sequence-of-returns (steady vs. adverse), historical sequence replay (real market years, `market_history`, unverified-data flagged), and the blended 0–100 Retirement Readiness Score. `goals_projection.php` surfaces all of it; series are shared via `decumulationSeriesForGoal()`/`readinessScoreForGoal()`.

**Plans & scenarios** — multi-goal `base_plans` (retirement/education/home/other) + `sub_scenarios` what-ifs through the Global Inheritance Engine cascade (single shared `is_overridden` flag). Per-field validation (`GoalFieldValidation.php`) shared by create/update. Full edit UI on `GoalDetail.jsx` (plan params, accumulation, corpus composition), gated so a client sees no advisor-only edit affordance.

**Strategy templates** — global + firm templates/customizations, approval-gated (`draft`/`approved`), apply-to-goal writes the return assumption + cascades + logs a `used_in_plan` audit row. `super_admin` approves global; `sr_advisor`/`firm_admin` approve firm-owned.

**Risk profiler** — per-tenant question set + scoring rubric the *firm* supplies (never HorizonPlan-authored), approval-gated; captured profiles surface a suggested return only when the set is approved (read-time gate). Authoring UI + client-facing questionnaire.

**Client portfolio** — `client_portfolio_items` ledger (assets/liabilities, liquid/locked, net worth computed on read), CAS/MFCentral CSV import, and a **daily MF NAV price-sync cron** (`mf_nav_cache`, `tools/mf_nav_sync.php`, migration 027) that refreshes tracked holdings from cached NAVs (manual "Refresh prices" uses the cache, no live call). *Not* a live RTA folio feed.

**Cash-flow module** (docs/10 P0-5) — a unified client cash-flow view: `cash_flow_items` (migration 030, `kind` income|expense, `label`, `amount`, `cadence` monthly|annual, `category`, `is_active`), tenant-scoped via `TenantScopedDb`, per-client on every row. The pure helper `api/lib/CashFlowSummary.php` normalises each line to monthly (annual ÷ 12), sums income/expense/surplus, and — advisor-only — compares surplus to the client's total goal SIPs (the "is the plan fundable from cash flow?" gap). Advisor card + client-facing read-only card (no SIP framing leaked) + a **household roll-up** (`household_cash_flow.php`, sum of members' statements). Balance sheet (portfolio) and cash-flow statement are deliberately separate. Firm/advisor supplies every figure; default empty, no backfill.

**Client-facing** — Meeting Mode (full-screen presentation), printable/shareable client report, DisclosureBanner on every client view, white-label branding, and **client self-service login** (own goals, what-if sliders, read-only portfolio, read-only cash flow, read-only risk band) with all advisor-only reads/writes hidden from a client session.

**Firm governance** — firm roles (`jr_advisor`/`sr_advisor`/`firm_admin`, NULL treated as `sr_advisor`), a **Jr→Sr plan-approval workflow** (`review_status` on `base_plans`, opt-in per tenant, migration 026), approval gates on templates/risk sets, and a **read-only audit log** (`change_log_list.php`, per-goal History card + firm-wide `/activity`).

**Advisor & admin UX** — dashboard with at-a-glance client health (readiness + risk band, sort/filter), onboarding checklist + spotlight (real advisors) and a guided demo tour (public demo only), a 3-step firm-onboarding wizard, firm detail drawer, platform-stats bar, and an in-app **feature guide** (`/guide`). Mobile-responsive throughout (Session 7 audit + tablet/client-role follow-ups); `AppHeader` collapses to a hamburger below `sm`.

**Platform & access** — self-serve trial signup (always `distribution` mode, `firm_admin` founder) + public "try a live demo" picker; platform settings (MFA enforcement, demo mode, signup toggle) + demo reset (5-min cooldown); magic-link invite activation; a public login-page feature list. Marketing one-pager at `docs/HorizonPlan_One_Pager.html`.

**Deploy infra** — automated `deploy.yml` (→ `deploy` branch) + parallel `deploy-staging.yml` (→ `deploy-staging`, `VITE_APP_ENV=staging` banner).

### Known open items / deferred (not gaps introduced, decisions on record)
- **Mandatory MFA is currently defaulted OFF** (`platform_settings.mfa_enforcement`, migration 023) for the early-access period — re-enable before real advisor/client data. The mechanism is fully built; only the default was flipped.
- **Google Sign-In's real-browser OAuth round-trip** was never verified end-to-end (sandbox can't reach `accounts.google.com`); do it once with a real Client ID before relying on it.
- **Destructive test-suite guard** (above) — still no hard protection against running the suite on a live DB.
- **General rate limiting** on `goals_*`/`subscenarios_*` deliberately deferred (docs/09 Session 3) — revisit only on a named abuse case.
- **Next-build backlog** (billing, analytics dashboard, and the value-add items — household planning, PDF export, scheduled reviews, client-visible risk profile, PWA, live RTA feed, tax reports) is prioritized in `docs/10` with ready session prompts.

## Security status
MFA (RFC 6238 TOTP, `api/lib/Totp.php`) + Google Sign-In as an equivalent second factor, CSRF (double-submit cookie, checked on every non-GET in `verifyAccess`/`verifyAccessAny`), mandatory-MFA-enrollment enforcement (server-side gate in the shared helpers, currently defaulted off per above), per-field validation on goal create/update, self-service password reset (hashed single-use tokens) + magic-link invites, and rate-limited login are all implemented. Tenant isolation is enforced through `TenantScopedDb` (with the three documented raw-tenant-scoped-JOIN read exceptions). No pre-launch security gap remains open from the Phase 8 audit trail; the one deliberately-deferred item is the destructive-test-suite guard. **Full file-by-file audit findings, the MFA/CSRF/enforcement decision write-ups, and the password-reset design are in `docs/CHANGELOG_SESSION_HISTORY.md` under "Security status".** Do not treat "no open audit gap" as "launch-ready" without re-enabling mandatory MFA and doing the real Google-OAuth round-trip check.

## Deploy
**Automated (preferred):** `.github/workflows/deploy.yml` builds the React app on every push to `main` (touching `frontend/`, `api/`, or the workflow) and publishes a `public_html`-shaped tree — compiled `dist/` at the root plus `api/` under `/api` — to a dedicated **`deploy` branch**, building on top of that branch's history (never force-pushed) so Hostinger's `git pull` always fast-forwards. Hostinger's native Git deployment (hPanel → Advanced → GIT, branch `deploy`, directory `public_html`) pulls it automatically. `api/db_config.php` is stripped in CI and lives only on the server, so pulls never touch it. **Full setup + gotchas in `DEPLOY.md`.** Note: the workflow only fires from `main`, so it activates after this branch is merged, not on the feature branch itself.

**Manual fallback:** build locally (`cd frontend && npm run build`), then upload the `dist/` output into `public_html` via Hostinger's File Manager alongside `public_html/api`. Watch two things this replaces: `.htaccess` is a hidden dotfile inside `dist/` that file managers drop, and every `dist/` file (including `assets/`) must land at the `public_html` root together or the absolute `/assets/…` paths 404.

SQL migrations in `/sql` are **not** run automatically under either path — run new migrations manually via hPanel's database tool after each deploy that includes one.
