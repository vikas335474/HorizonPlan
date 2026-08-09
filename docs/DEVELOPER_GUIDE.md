# HorizonPlan Developer Guide

> Orientation for a developer new to this codebase. Read `CLAUDE.md` first (the
> standing, non-negotiable rules), then this guide for *how the pieces fit*, then
> the area-specific file in `/docs/` before touching that area. This guide points
> at real files and line-level patterns — when in doubt, the code is the source
> of truth and its inline comments explain the *why*.

HorizonPlan is a B2B2C retirement-planning platform for Indian MFDs/IFAs and
SEBI-RIA firms. It is a **planning-and-conversation tool, not a transaction
back-office** — there is no order routing, KYC, or commission reconciliation, by
design (see `docs/10` for the honest competitive framing).

---

## 1. Architecture at a glance

| Layer | Tech | Where |
|-------|------|-------|
| Backend | PHP 8 (no framework) + PDO + MySQL/MariaDB | `api/` |
| Shared backend logic | plain PHP classes/functions | `api/lib/` |
| Schema | numbered SQL migrations, applied in order | `sql/` |
| Frontend | React (Vite) + Tailwind + shadcn/ui | `frontend/` |
| CLI tools / crons | standalone PHP scripts | `tools/` |
| Tests | plain PHP scripts, a real DB for the `*_db.php` ones | `tests/` |

**Same-origin model.** The compiled React app is served as static files from
Hostinger's `public_html`, with the PHP API sitting under `public_html/api` on
the *same origin*. Consequences that shape everything:

- No CORS, no bearer tokens in `localStorage`. Auth rides on an **httpOnly
  session cookie** that the browser sends automatically (`credentials: 'include'`
  in `frontend/src/lib/api.js`).
- Every endpoint is a single `.php` file reachable at `/api/<name>.php`.
- There is **no build step on the server** — the frontend must be built
  (`npm run build`) before every deploy. CI (`.github/workflows/deploy.yml`)
  automates this to a `deploy` branch; see `DEPLOY.md`.

**Directory map.**

```
api/
  <endpoint>.php        one file per endpoint; top-of-file comment states its contract
  lib/
    security_gatekeeper.php   auth, CSRF, MFA, sessions, rate limiting, firm-role gate
    TenantScopedDb.php        the tenant-isolation data-access helper (see §3)
    PlanMath.php              pure projection arithmetic, no DB
    ...                       one class/module per concern (see §7)
  db_config.php         git-ignored; DB creds + getPdo(). Copy from db_config.example.php
frontend/src/
  lib/api.js            the ONLY place that talks to the backend
  context/AuthContext.jsx   session bootstrap + auth actions; useAuth() everywhere
  components/ProtectedRoute.jsx  route guard (login + mandatory-MFA)
  pages/ components/    see the file-level header comment in each
sql/                    001_… migrations, applied in numeric order
tests/run_all.sh        the whole suite
tools/                  crons + one-off admin scripts
```

---

## 2. The request lifecycle

Trace a typical authenticated read, `GET /api/goals_projection.php?id=42`:

1. **Frontend.** A page calls `api.getProjection(42)` in `frontend/src/lib/api.js`.
   `request()` issues `fetch` with `credentials: 'include'`. For a mutation
   (POST/PUT/PATCH/DELETE) it also attaches the `X-CSRF-Token` header, read from
   the non-httpOnly `hp_csrf` cookie (the double-submit pattern, §4).
2. **Bootstrapping.** The endpoint's first line is
   `require_once __DIR__ . '/lib/security_gatekeeper.php';`, which installs the
   JSON error handler (`installApiErrorHandlers()`) *before* anything else can
   fatal — so even a broken `db_config.php` returns a structured 500, not a blank
   page.
3. **Method guard.** Each endpoint rejects the wrong HTTP method with `405`
   before doing any work.
4. **Auth + CSRF.** `verifyAccess($db, 'advisor')` (or `verifyAccessAny($db,
   ['advisor','client'])`) runs the CSRF check on non-GET requests, validates the
   session cookie against `active_sessions`, enforces the role (super_admin always
   passes), enforces mandatory-MFA enrollment, and **returns the session** or
   `exit()`s with a JSON error. Callers never handle a null return.
5. **Tenant scope.** The endpoint constructs
   `new TenantScopedDb($db, (int) $session['tenant_id'])`. The tenant id comes
   from the *verified session*, never from request input.
6. **Work.** Reads/writes go through the scoped helper. Mutations to
   `base_plans`/`sub_scenarios` also call `$scopedDb->logChange(...)` (rule #4).
   Pure math (projections, readiness score) is delegated to `PlanMath` — no DB
   access lives in that layer.
7. **Response.** The endpoint `echo json_encode([...])`s. The convention is
   `{"status": "success", ...}` or `{"status": "error", "message": "..."}` with
   an appropriate HTTP status code.

The status-code contract used across the API: **400** bad input, **401**
no/invalid session, **403** wrong role / privilege escalation / unapproved
content, **404** not found (or not in this tenant — see §3), **405** wrong
method, **409** conflict (e.g. duplicate email), **429** rate-limited, **202**
`mfa_required` (a valid non-error the frontend branches on), **503** a feature
not configured on this deployment (e.g. Google Sign-In with no Client ID).

---

## 3. Tenant isolation — the model that must never leak

**Every query on tenant-scoped data goes through `api/lib/TenantScopedDb.php`.**
This is non-negotiable rule #1. The class binds the tenant id once, at
construction, from the verified session — there is no method that accepts a
caller-supplied tenant id, so calling code has no path to bypass it.

- `select()`, `insert()`, `update()`, `delete()` all AND-in
  `tenant_id = :tenant_id` automatically. `insert()` overwrites any caller-passed
  `tenant_id`; `update()` refuses to move a row across tenants and refuses a
  condition-less bulk update.
- An **allow-list** (`ALLOWED_TABLES`) guards against a typo silently querying an
  unscoped table.
- `logChange()` writes the `change_log` audit row for `base_plans`/`sub_scenarios`
  mutations, taking the acting user id explicitly so it's always traceable.

**Why "not in this tenant" surfaces as 404, not 403.** A scoped `update`/`delete`
on a row that belongs to another firm simply matches 0 rows. Endpoints treat that
as "doesn't exist" — the same response a genuinely missing id gets — so the API
never confirms the existence of another tenant's data. See e.g.
`cash_flow_delete.php`, `templates_customize.php`.

**The two documented exception patterns** (both still safe, both explained at the
point of use):

1. **Raw tenant-scoped `JOIN` reads.** `TenantScopedDb::select()` can't express a
   `JOIN`. Three read endpoints that need to join to `users.email` drop to a raw
   query that is *still* `WHERE …tenant_id = :tenant_id`:
   `clients_list.php`, `change_log_list.php`, `goals_review_queue.php`. That's the
   whole exception — always still tenant-filtered.
2. **Deliberate cross-tenant template methods.** A *global* strategy template is,
   by definition, visible outside the tenant that owns its row. A small set of
   methods on `TenantScopedDb` (`findTemplateStrategyById`,
   `selectGlobalPublishedTemplates`, `countGlobalTemplateUsage`,
   `approveGlobalTemplate`) are narrow, documented exceptions: reads return either
   aggregate counts or rows already flagged `is_published = 1`, and the one write
   is restricted to `is_system_template = 1` rows with the caller having verified
   `super_admin` first. See the block comment above those methods.

Never write a raw inline `WHERE tenant_id = …` in a *new* endpoint. If the helper
can't express what you need, prefer extending the helper; only drop to a raw
tenant-scoped query if you're following one of the three documented JOIN
precedents, and comment why.

---

## 4. Security helpers (`api/lib/security_gatekeeper.php`)

One file owns the security surface. Highlights (each function has its own
docblock — read it before changing behaviour):

- **Sessions.** `issueSession()` mints a random token, stores it in
  `active_sessions`, and sets the httpOnly/secure/SameSite=Strict `hp_session`
  cookie (8h TTL). `getCurrentSession()` looks it up; `destroySession()` clears it.
- **Role gates.** `verifyAccess($db, $role)` and `verifyAccessAny($db, $roles)`
  enforce role and return the session or `exit()`. **`super_admin` passes every
  role gate** by convention — endpoints don't special-case it.
- **CSRF — double-submit cookie.** The server sets a *readable* `hp_csrf` cookie
  (`issueCsrfToken()`); the JS layer echoes it back as `X-CSRF-Token`;
  `verifyCsrfToken()` compares them with `hash_equals`. Checked on every
  non-GET/HEAD/OPTIONS request. GET is exempt (read-only).
- **MFA.** RFC 6238 TOTP (`api/lib/Totp.php`) *or* a linked Google account each
  satisfy the mandatory-MFA requirement (they're alternatives, not stacked).
  `requireMfaEnrollment()` (called by the verify* helpers) 403s an unenrolled
  session — **unless** `platform_settings.demo_mode='on'` or
  `mfa_enforcement='disabled'`. Login is a two-step handshake: `login.php` checks
  the password and issues a short-lived pending token; `mfa_verify.php` consumes
  it (single-use) and issues the real session.
- **Firm-role gate.** `requireFirmRole($db, $session, $allowed)` checks the
  advisor's firm-level role (`jr_advisor`/`sr_advisor`/`firm_admin`). A `NULL`
  firm_role (any advisor created before migration 020) is treated as
  `sr_advisor` — the middle tier — so nothing silently gains or loses capability.
- **Rate limiting.** `checkLoginRateLimit()`/`recordLoginAttempt()` cap failed
  logins per email+IP. General rate limiting on `goals_*`/`subscenarios_*` is
  deliberately deferred (see `CLAUDE.md`); `demo_reset.php` has its own cooldown
  (`claimDemoResetSlot()`).
- **Platform settings.** `getPlatformSettings()` reads the single-row
  `platform_settings` config, request-cached, **failing closed** (stricter
  posture) if the row is unreadable.

> **Note on the current default:** mandatory MFA is *defaulted OFF*
> (`platform_settings.mfa_enforcement`, migration 023) for the early-access
> period. The mechanism is fully built — only the default is flipped. Re-enable
> before real advisor/client data. Do not read "no open audit gap" as
> "launch-ready" (`CLAUDE.md` → Security status).

---

## 5. Roles

| Role (`users.role`) | Scope | Notes |
|---------------------|-------|-------|
| `super_admin` | Platform-wide | Passes every role gate; manages firms, platform settings, global templates. `advisory_mode` is super_admin-only (rule #2). |
| `advisor` | One tenant | The working role. Sub-divided by `users.firm_role`. |
| `client` | Own data only | Self-service login: own goals, what-if sliders, read-only portfolio/cash-flow/risk. Every advisor-only read/write is hidden client-side **and** blocked server-side. |

Advisor **firm roles** (migration 020, `docs/09` Piece 2): `jr_advisor` (author,
can't approve) < `sr_advisor` (approve, the `NULL` default) < `firm_admin`
(also manage advisors + firm branding — but **not** `advisory_mode`).

---

## 6. Data model

Schema lives in `sql/`, one numbered migration per change, applied in order. Each
file has a header comment explaining what and why; read it before altering a
table. Rough grouping:

- **Tenancy & auth:** `tenants`, `users`, `active_sessions`, `login_attempts`,
  `mfa_pending`, `password_resets`, `google_auth` (021), `firm_role` (020),
  `platform_settings` (019/023/024), `trial_signup` (022).
- **Planning core:** `base_plans` (goals), `sub_scenarios` (what-ifs),
  `change_log` (audit), `accumulation` (016), `market_history` (015, global
  reference data), corpus-composition columns (018).
- **Strategy templates:** `template_strategies`, `template_customizations`,
  `template_audit_log`, `template_approval_state` (013),
  `base_plans_applied_template` (014).
- **Risk / portfolio / cash-flow / households / reviews:** `risk_profiles` (017),
  `client_portfolio` (018), `mf_nav_sync` (027), `households` (029),
  `cash_flow` (030), `plan_review` (026) + `plan_review_schedule` (028).
- **Client→advisor assignment:** `users.assigned_advisor_id` (031).
- **Progress over time:** `goal_snapshots`, `client_net_worth_snapshots` (032).

### Progress snapshots are a record, not a projection

`goal_snapshots` (032) stores what a goal's corpus actually was AND what the
plan expected, on each captured date. The expected figure is **stored at
capture time, never recomputed on read** — recomputing from the goal's current
assumptions would let an advisor editing a return rate retroactively turn a
"behind" history into an "on track" one. Same immutable-record instinct as
`change_log`.

Two consequences worth knowing before you touch it:

- **Nothing is backfilled.** History starts the first time a snapshot runs. A
  date with no row is a date nobody observed, and the chart deliberately breaks
  the line there rather than bridging it.
- **Capture is idempotent per day** via the unique keys on
  `(goal_id, as_of_date)` / `(client_id, as_of_date)`, so the monthly cron and a
  manual "Record now" on the same date converge on one row. Any new capture path
  must upsert, not insert.

The two series are kept separate on purpose: a client's net worth is never
summed into a goal's progress, because docs/02 §4.1 says the portfolio is not a
pool a goal draws from.

### Assignment is attribution, not access control

`users.assigned_advisor_id` (migration 031) records which advisor a client
belongs to. It exists for the "My clients" filter and the per-advisor practice
analytics — **it is not a permission boundary**. Tenant isolation remains the
only security boundary: every advisor in a firm can still read and write every
client in their tenant, exactly as before the column existed. `scope=mine` on
`clients_list.php` is a filter the caller opts into, and nothing anywhere
restricts a read by assignment.

If you are tempted to "tighten" this into a real ownership check, that is a
product decision, not a cleanup — it would change the firm's access model, and
`tests/test_client_assignment_db.php` asserts the current behaviour explicitly
so the change can't happen by accident.

### FK / teardown-ordering landmine

Several cross-table foreign keys mean **delete order matters** whenever you tear
down or re-seed. In particular, migration 014 added
`applied_template_id`/`applied_customization_id` FKs on `base_plans` pointing at
`template_strategies`/`template_customizations`, so children must be deleted
*before* their parents. This bites in exactly two places, and both already
document the FK-safe order at the point it matters — copy from them, don't
re-derive it:

- **`api/demo_reset.php`** (the demo re-seed): see the block comment at the delete
  loop for the full chain (`cash_flow_items` / `client_portfolio_items` /
  `risk_profiles` / `sub_scenarios` / `template_audit_log` / `base_plans` →
  `risk_question_sets` / `template_customizations` / `template_strategies` →
  `households` → `active_sessions` → `users` → `tenants`).
- **`tests/test_tenant_isolation.php`** (fixture setup): same ordering rationale.

**Adding a table that FKs to `users` costs more than the migration.** `client_protection`
(migration 034) is the worked example: its two plain FKs to `users` mean every
teardown path has to clear it *before* `DELETE FROM users`, and there are more
of those than you'd guess — `api/demo_reset.php` plus roughly a dozen
`tests/*_db.php` fixture blocks. The failure is also delayed and confusing: the
suite stays green until something actually writes a row to the new table, then a
dozen unrelated tests start failing with an FK violation naming a table they've
never heard of. If you add one, grep for `DELETE FROM users` and fix every hit in
the same commit. (Tables that FK with `ON DELETE CASCADE`/`SET NULL` — e.g.
`goal_snapshots`, migration 032 — don't have this problem.)

### Destructive-test landmine

`tests/*_db.php` fixtures now wrap themselves in a transaction that rolls back
(`docs/09` Session 2), but there is still **no hard guard** stopping
`tests/run_all.sh` from running against a database that holds real/demo data.
**Always point the suite at a disposable DB.** This is the one deliberately
deferred item on the security/quality ledger.

### Migrations 033–039: Recent feature expansions (Phase 2 core + personalisation)

After the core MVP (032), the following migrations added:

- **033: `personal_tenants`** — Self-serve individual tier (sql/033). A personal tenant has `kind='personal'`, one user with `role='client'`, and `advisory_mode='self_directed'` (docs/04 Phase 1 scope creep; implemented by session end). Write access is gated on tenant kind, not role (`SelfService.php`).
- **034: `financial_foundations`** — Client financial-position table (`client_protection`): emergency reserve / income-replacement cover / medical cover tracking. Two amounts + dependant count stored; thresholds are sourced reference points, never recommendations. See `FinancialFoundations.php`.
- **035: `household_self_service`** — Extends personal tenants to support couples: partner invite (email + magic link), two-person household with shared `households.id`, per-person goals/risk. Each partner reads everything; each writes only their own data.
- **036: `client_context`** — Personalisation context table (`client_context`): city tier (7th CPC tier, `household_id` scoped—shared between partners), ongoing medical cost (per-person). Opt-in; no backfill. Feeds `PersonalisationReference.php`.
- **037: `reference_costs`** — Sourced reference data table: education cost ranges (government/private/overseas driver) + healthcare, with source URL + `is_verified` flag. Populated by cron; fallback to `PersonalisationReference.php` constants while empty.
- **038: `portfolio_reconcile`** — CAS/MFCentral reconciliation rewrite (docs/12 D-1, sql/038): added `client_portfolio_items.folio_number` and `source` column ('manual' | 'cas_import'). Prevents duplicate on reimport. See `PortfolioReconcile.php`.
- **039: `tax_context`** — Per-instrument tax treatment reference table (`tax_reference`): capital-gains/holding-period treatment notes, `is_verified` flag. Added to `client_portfolio_items`: `fund_type` (equity|debt|hybrid for mutual funds), `acquisition_value`, `acquisition_date`. See `PortfolioTaxContext.php`, `TaxReference.php`.

These are the "long tail of hardening + demo + UX" sessions from `docs/CHANGELOG_SESSION_HISTORY.md`. Read `docs/12` (D-1 through D-4) for the detailed design decisions.

---

## 7. `api/lib/` — the shared backend modules (30 files)

| File | Responsibility |
|------|----------------|
| **Security & auth** | |
| `security_gatekeeper.php` | Auth, CSRF, MFA, sessions, rate limiting, firm-role gate, platform settings (§4). |
| `Totp.php` | RFC 6238 TOTP, no external deps. |
| `GoogleAuth.php` | Google Sign-In: network / pure / DB layers split for testability. |
| `InviteTokens.php` | Magic-link invite activation (reuses `password_resets`). |
| **Data access & persistence** | |
| `TenantScopedDb.php` | Tenant-isolated data access (§3). |
| `error_handler.php` | Turns fatals/uncaught exceptions into a structured JSON 500. |
| `Mailer.php` | Thin wrapper over PHP `mail()` (Hostinger local MTA). |
| **Planning core** | |
| `PlanMath.php` | **Pure** projection arithmetic — decumulation, accumulation, corpus composition (liquid-first), sequence-of-returns, historical replay, the 0–100 readiness score, and `targetGoalFunding()` (the funding signal for target-based goals, which deliberately assumes no growth). No DB. |
| `GoalFieldValidation.php` | Per-field range/type validation shared by goal create + update so the two entry points can't drift. |
| `RiskProfileScoring.php` | Pure scoring of questionnaire answers against a firm's rubric. |
| `TemplateValidation.php` | Shared allocation/risk-profile validation for template endpoints. |
| `PlanReview.php` | Jr→Sr approval-workflow state transitions. |
| `PlanReviewMailer.php` | Plan review email generation and scheduling. |
| **Cash flow & portfolio** | |
| `CashFlowSummary.php` | Normalises income/expense lines to monthly, sums surplus, and (advisor-only) compares surplus to total goal SIPs. |
| `PortfolioReconcile.php` | Pure logic for CAS/MFCentral CSV reconciliation (docs/12 D-1, sql/038) — diffs, validation, deduplication. No DB. |
| `PortfolioTaxContext.php` | Per-holding tax-context orchestrator (docs/12 D-2, sql/039) — shapes cached treatment notes with acquisition data into an honest "facts only" answer. Pure, no DB. |
| `TaxReference.php` | Tax reference data helper — reads the cached `tax_reference` table (capital-gains/holding-period treatment notes) and validates them. |
| `ReferenceCosts.php` | Reference-costs caching helper — reads the `reference_costs` table (education/medical cost ranges) for use in personalisation. |
| **Households & personal planning** | |
| `HouseholdProjection.php` | Sums members' projections into a household aggregate. |
| `SelfService.php` | Self-serve individual tier — gates write access to personal tenants only (sql/033). Enforces "own data only" on self-directed client roles. |
| **Progress tracking & alerts** | |
| `ProgressSnapshot.php` | Goal-progress capture (P1-1). Tenant-scoped in-request path for "Record now"; raw-SQL cross-tenant path for the monthly cron — same split, and same reasoning, as `MfNavSync.php`. |
| `AlertsEngine.php` | Stateless alerts/rules engine (docs/12 D-4, sql/041) — pure computation of five trigger types (goal_met, goal_drift, price_stale, review_due, foundations_gap). No DB access. |
| `AlertsInputs.php` | Alert input assembly — bundles goal/portfolio/review/foundation data per client for the stateless engine. |
| `FoundationsInputs.php` | Financial foundations input extraction — pulled from `foundations_read.php` so the engine and the client page never drift. |
| **Personalisation & sync** | |
| `PersonalisationReference.php` | Personalisation reference data — city-tier multipliers (HRA-derived, code constant), education ranges (fallback to this when `reference_costs` cache is empty). |
| `MfNavSync.php` | Daily MF-NAV price sync (cached NAVs only; never a live AMFI call inside a request). |
| **Demo & trials** | |
| `DemoAccess.php` | Demo login boundary — gates login and demo reset behind environment flags. |
| `DemoSeeder.php` | Demo data seeding — 4 firms, 160 clients, all user accounts and their relationships. |
| `TrialSignup.php` | Self-serve trial signup helper — validates and creates new trial firm tenants. |

**House style for these:** a file/class-level docblock stating *why this exists /
the invariant it protects*, plus PHPDoc (`@param`/`@return`/`@throws`) on public
methods. `TenantScopedDb.php`, `PlanMath.php`, and `CashFlowSummary.php` are the
bar — match them.

---

## 8. Frontend architecture

- **`frontend/src/lib/api.js`** is the *only* module that talks to the backend.
  One method per endpoint; each documents its shape. It owns CSRF-header
  attachment and error normalisation (`ApiError`). Add new endpoints here, not
  ad-hoc `fetch`es in components.
- **`context/AuthContext.jsx`** bootstraps the session (`api.session`) and exposes
  the auth actions + the resolved `user` / `tenant` / `platform` blocks.
  **`useAuth()` is the single source of identity and role** for every page and
  component.
- **`components/ProtectedRoute.jsx`** wraps every authenticated route: redirects to
  `/login` with no session and to `/settings` when MFA isn't satisfied (a soft
  app-layer gate on top of the server 403).
- **`App.jsx`** is the router table + provider tree; per-route role intentions are
  in inline comments there. `Home()` routes clients to `/goals`, advisors/admins
  to the Dashboard.
- **Role-gating is defence-in-depth, not the enforcement.** Pages hide advisor-only
  affordances from a client session (e.g. `isAdvisor` in `GoalDetail.jsx`), and
  some client-guard/redirect (`AdminConsole.jsx`). **The real gate is always
  server-side** on every endpoint — the UI guard is UX, not security.
- Each page/component carries a **file-level header comment** describing its role,
  key props, which `api.js` calls it makes, and any role-gating. Read it first.
- Charts are Recharts (`SequenceRiskChart`, `LifecycleChart`); shared primitives
  live in `components/ui.jsx`. The demo tour is `context/DemoTourContext.jsx`
  (public demo accounts only). The in-app feature run-book is `pages/FeatureGuide.jsx`
  (route `/guide`).

### Frontend component structure (56 React components)

**Pages** (`frontend/src/pages/`) — top-level routes:
- `Home.jsx` — auth router (client → /goals, advisor → /dashboard)
- `LoginPage.jsx`, `SignupPage.jsx`, `ForgotPassword.jsx`, `ResetPassword.jsx` — auth flows
- `StartFree.jsx` — self-serve individual Q&A onboarding
- `SignupPersonal.jsx` — personal tenant signup (self-serve + couple flow)
- `Dashboard.jsx` — advisor/admin persona dashboard (firm book, attention queue, analytics)
- `ClientDetail.jsx` — planner + portfolio + cash-flow + review hub for one client
- `GoalDetail.jsx` — detailed plan + what-if UI for one goal; edit UI gated by tenant kind + role
- `MeetingMode.jsx` — full-screen presentation view (client-safe)
- `AdminConsole.jsx` — super_admin platform settings + firm management
- `FeatureGuide.jsx` — in-app run-book (`/guide`)
- `PersonalDashboard.jsx` — self-serve individual's "My goals" page
- `HouseholdDashboard.jsx` — couple's shared household view

**Components** (`frontend/src/components/`) — reusable pieces, each with file-level header:
- **Goal & projection UI:**
  - `GoalCard.jsx` — roster card (status, progress, drift badge)
  - `RetirementTargetCard.jsx` — retirement goal specific (corpus, spending, readiness score)
  - `SequenceRiskChart.jsx` — steady vs. adverse decumulation chart (Recharts)
  - `LifecycleChart.jsx` — accumulation + decumulation lifecycle
  - `ProgressChart.jsx` — actual vs. expected goal corpus over time (progress snapshot data)
  - `HistoricalReplay.jsx` — market-year replay series selector + chart
- **Portfolio & cash flow:**
  - `ClientPortfolioUI.jsx` — asset/liability ledger + reconciliation UI
  - `CashFlowUI.jsx` — income/expense + surplus UI
  - `ProgressUI.jsx` — goal progress tracking + "Record now" capture
- **Financial & risk:**
  - `FoundationsUI.jsx` — the four pre-goal checks (emergency reserve, cover, medical, debt)
  - `RiskProfileUI.jsx` — questionnaire authoring + scoring
  - `PersonalisationUI.jsx` — city tier + medical cost + education drivers (E-2)
- **Alerts & monitoring:**
  - `AlertsUI.jsx` — the five stateless alert types (goal_met, drift, price_stale, review_due, foundations_gap)
  - `ReadinessScore.jsx` — the 0–100 score display + caveat
- **Plan & template:**
  - `TemplateUI.jsx` — strategy template library + apply + approve flows
  - `PlanReviewUI.jsx` — Jr→Sr review workflow state UI
  - `ChangeLogUI.jsx` — audit trail / history card
- **Client-facing & household:**
  - `DisclosureBanner.jsx` — distribution-mode disclosure (required on every client view)
  - `PartnerHouseholdUI.jsx` — couple household roster + invitation
  - `AudienceTracks.jsx` — persona-aware copy (advisor vs. client labels)
- **Admin & settings:**
  - `OnboardingChecklist.jsx` — firm setup checklist
  - `Spotlight.jsx` — feature spotlights (onboarding for real advisors)
  - `ResetTriggerControl.jsx` — demo reset UI (admin only)
  - `ScenarioPanel.jsx` — what-if scenario + sub-scenario controls
- **Utilities:**
  - `ProtectedRoute.jsx` — route guard (login + MFA check)
  - `Modal.jsx`, `LiveTimelineSlider.jsx` — UI primitives
  - `ui.jsx` — Recharts primitives, button/form/card/table/dropdown components (shadcn/ui based)
  - `AppHeader.jsx` — responsive header (hamburger below `sm`)

**Context** (`frontend/src/context/`) — state & session management:
- `AuthContext.jsx` — session bootstrap, auth actions, user/tenant/platform data (use `useAuth()` everywhere)
- `DemoTourContext.jsx` — guided feature tour (public demo only)

**Library** (`frontend/src/lib/`) — pure helpers:
- `api.js` — **the only place that talks to the backend**; one method per endpoint
- `personalPlanner.js` — self-serve onboarding Q&A logic + suggested goals
- `strategyPresets.js` — sourced illustration-framed risk bands (never recommendations)

---

## 9. Local development

```bash
# 1. Database (MySQL/MariaDB). Create a *disposable* schema and apply migrations
#    in numeric order:
mysql -e "CREATE DATABASE horizonplan_dev;"
for f in sql/*.sql; do mysql horizonplan_dev < "$f"; done

# 2. Backend config (git-ignored):
cp api/db_config.example.php api/db_config.php
#    …edit DB_NAME/DB_USER/DB_PASS. GOOGLE_CLIENT_ID may stay empty (Sign-In 503s
#    cleanly). APP_BASE_URL only matters for the plan-review email cron.

# 3. PHP API (built-in server serving the api/ dir):
php -S localhost:8000 -t api

# 4. Frontend (Vite dev server; proxies /api → localhost:8000, see vite.config.js):
cd frontend && npm install && npm run dev
```

Seed demo data with `php tools/seed_demo_data_full.php` (4 firms / 160 clients;
`admin@nirvana` / `senior@nirvana` / a client, password `DemoPass@2026`). Set
`platform_settings.demo_mode='on'` to allow those manual logins without MFA.
**Only ever seed/point tests at a disposable DB** (§6).

Google Sign-In's real OAuth round-trip has never been verified end-to-end in the
sandbox (it can't reach `accounts.google.com`); do it once with a real Client ID
before relying on it.

---

## 10. Test harness

`bash tests/run_all.sh` runs the whole suite. It exits non-zero on any real
failure, so it's CI-safe.

- **Pure tests** (`test_plan_math`, `test_totp`, `test_password_hashing`,
  `test_inheritance_cascade`, `test_corpus_composition`, …) always run — no DB
  needed.
- **DB-integration tests** (`*_db.php`, plus `test_tenant_isolation`) need
  `api/db_config.php` pointed at a real DB; they **self-skip** when no DB is
  configured, and wrap their fixtures in a transaction that rolls back.

The bar for this codebase is a **real** MySQL/MariaDB + a real request/response
cycle — not static review. Install the DB, apply every migration, run the suite,
and (for UI) drive the actual dev servers. Nearly every entry in the build
history was caught or confirmed this way.

---

## 11. How to add a new endpoint safely

A checklist that keeps a new endpoint consistent with the rest of the API:

1. **Schema first (if needed).** Add a numbered migration in `sql/` with a header
   comment (what + why). Never silently degrade an existing tenant on a migration
   — backfill explicitly. Mind FK ordering (§6).
2. **Create `api/<name>.php`.** Start with a **top-of-file comment** stating:
   purpose, HTTP method, auth/role, tenant-scoping, key inputs, outputs, and error
   cases. (Match the existing headers — e.g. `cash_flow_list.php`.)
3. **Boilerplate:**
   ```php
   <?php
   declare(strict_types=1);
   // <header comment>
   require_once __DIR__ . '/lib/security_gatekeeper.php';
   require_once __DIR__ . '/db_config.php';
   require_once __DIR__ . '/lib/TenantScopedDb.php';
   header('Content-Type: application/json; charset=UTF-8');
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); /* … */ exit(); }
   $db = getPdo();
   $session = verifyAccess($db, 'advisor');           // or verifyAccessAny([...])
   $scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);
   ```
4. **Read input** from `json_decode(file_get_contents('php://input'), true) ?? []`
   (POST/PUT) or `$_GET` (GET). Validate every field; a client-supplied
   `client_id` must be confirmed to belong to this tenant before use.
5. **Never write a raw `WHERE tenant_id = …`.** Use `$scopedDb`. If you truly need
   a JOIN, follow one of the three documented raw-tenant-scoped precedents (§3) and
   comment why.
6. **Audit mutations.** `base_plans`/`sub_scenarios` changes call
   `$scopedDb->logChange(...)` (rule #4). Advice-field edits may trigger plan
   review (`PlanReview.php`).
7. **Keep math in `PlanMath`** (or the relevant pure lib). Endpoints orchestrate;
   they don't re-implement arithmetic.
8. **Respond** with `{"status": "success", ...}` / `{"status":"error","message":…}`
   and the right status code (§2).
9. **Wire the frontend:** add a method to `frontend/src/lib/api.js` (documented),
   consume it from a page/component, and role-gate the UI affordance — remembering
   the server is the real gate.
10. **Test for real:** add a `*_db.php` test if it touches the DB, run
    `tests/run_all.sh` green, and drive it in the browser.

For anything with a real schema or product decision, **decide-then-build**:
confirm the open choices with the user before writing the migration (`CLAUDE.md`
→ Working conventions).

---

## 12. Deploy & further reading

- **Deploy:** `DEPLOY.md` (automated `deploy.yml` → `deploy` branch, plus the
  staging pipeline and manual fallback). SQL migrations are **not** auto-applied —
  run new ones by hand after a deploy that includes one.
- **Docs index:** `CLAUDE.md` lists `docs/01`–`docs/10` and when to read each.
  `docs/CHANGELOG_SESSION_HISTORY.md` is the verbatim build narrative + full
  security-audit findings — read it for the *why/how-verified* behind any piece.
- **User-facing:** `docs/USER_GUIDE.html` (per-persona feature tour) and the
  marketing one-pager `docs/HorizonPlan_One_Pager.html`.
