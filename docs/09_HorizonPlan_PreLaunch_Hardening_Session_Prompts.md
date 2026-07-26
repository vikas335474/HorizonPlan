# Pre-Launch Hardening — Session Prompts

> Each session below is **self-contained** — copy-paste the whole session's block as the prompt for that session. Every decision is already made; the implementer should still read `CLAUDE.md` and the referenced docs before writing code, not assume this prompt alone is enough.

## Why this order

Four groups, strictly sequential — each group assumes the previous group is done, and later groups build on infrastructure earlier ones touch (Group 2's invite flow reuses `password_resets`; Group 4's plan-tier gating reads the same `platform_settings`/tenant model Group 1 hardens).

1. **Security hardening** — closes the last known pre-launch gaps and one landmine found in a prior session (destructive tests). Must happen before more real firms/clients go in, regardless of anything else.
2. **Onboarding & invite flow** — the one piece of onboarding UX still using a real security anti-pattern (shared temp passwords). Depends on nothing from Group 1 functionally, but shares the "don't onboard more real firms on a half-fixed foundation" urgency.
3. **Admin/advisor operational UX** — polish that makes the product read as complete to a real firm, lower urgency than security but higher than Group 4.
4. **Platform/business readiness** — the biggest net-new surface (billing, plan tiers). Deliberately last: don't build monetization plumbing around a product whose core security/onboarding/UX gaps are still open.

Each session is scoped to be completed and verified in one sitting, same rigor bar as every prior session in `CLAUDE.md`'s history: real MySQL, real HTTP cycle, real browser where UI is involved — no claiming something works without running it.

---

# Group 1 — Security Hardening

## Session 1: Per-field validation on `goals_update.php`

### Goal
Close the one remaining flagged gap from the Phase 8 security audit (see CLAUDE.md "Also found, flagged, deliberately not fixed"): `goals_update.php`'s partial-update loop has no type/range validation for `initial_net_worth`, `inflation_rate`, `target_amount`, `target_date`, `withdrawal_rate`, `drawdown_return_rate` — only `projection_horizon_years` is checked today.

### Decisions already made
- Validate each field **only when it's present in the request body** (this is a partial-update endpoint — don't require fields that aren't being changed).
- Ranges (reject with 400 otherwise):
  - `initial_net_worth`: > 0, and a sane upper bound (suggest 10,000,000,000 — ₹1000 crore — as a ceiling; adjust if that's unrealistic for the target market, but *some* ceiling must exist).
  - `inflation_rate`, `withdrawal_rate`, `drawdown_return_rate`, `accumulation_return_rate`, `locked_return_rate`: 0–100 (percentages; negative rates and >100% are nonsensical here). Check `docs/02` for whether any of these were ever intended to go negative (e.g. a deliberately pessimistic scenario) before hardcoding 0 as the floor — if the product intends to allow negative return assumptions for stress-testing, floor should be lower (e.g. -20) instead.
  - `target_amount`: > 0 if present.
  - `target_date`: must be a valid date string, and — decide and document — either "must be in the future" or "no constraint" (a target date in the past is probably a data-entry error worth rejecting, but confirm this doesn't break any legitimate historical-goal use case first).
  - `liquid_corpus_amount`/`locked_corpus_amount`: >= 0 (the existing both-or-neither + sum-must-equal-`initial_net_worth` check already exists — don't duplicate, just add the range check alongside it).
  - `current_age`/`retirement_age`: reasonable human bounds (e.g. 18–100) in addition to the existing `retirement_age > current_age` check.
- `goals_create.php` should get the **same** validation (it currently has the identical gap for the same fields, just under a different endpoint) — do both in this session, not just `goals_update.php`, since they're the same data-integrity boundary.
- Return the *existing* error response shape (`{"status":"error","message":"..."}`, 400) — don't invent a new error format.

### Build steps
1. Add a small shared validation helper (new file `api/lib/GoalFieldValidation.php` or similar, or inline in both endpoints if the duplication is small enough — your call, but don't copy-paste the same range-check logic twice without at least a comment noting the duplication is deliberate).
2. Wire it into `goals_update.php`'s partial-update loop and `goals_create.php`'s field validation.
3. Update `tests/test_plan_math.php` or a new `tests/test_goal_field_validation.php` (DB-integration test, same self-skip pattern as the other `_db.php` tests) covering: each field's boundary values (just inside/outside range), that omitted fields still pass through untouched (partial update), and that the existing `both-or-neither`/sum/`retirement_age > current_age` checks still work unchanged.

### Verification
- Run against a real MySQL instance: valid updates still succeed, each new range check 400s correctly, omitting a field doesn't trigger its validation.
- Confirm `tests/run_all.sh` passes with the new test added.
- **Before running the full suite**, read Session 2 below first if it hasn't been done yet — running `tests/run_all.sh` right now will destroy any demo data if Session 2 hasn't fixed the destructive tests. If Session 2 hasn't run yet, either accept the demo-data loss (it's disposable, `tools/seed_demo_data_full.php` recreates it) or do Session 2 first.

### What NOT to build
- Don't add validation to fields nobody asked about (e.g. `goal_label`, `goal_type` already have implicit validation via NOT NULL/ENUM at the DB layer — leave those).
- Don't build a generic reusable "form validation framework" — this is six specific fields with known ranges, not a platform.

---

## Session 2: Fix the destructive DB test suite

### Goal
`tests/test_client_portfolio_db.php`, `tests/test_risk_profiles_db.php`, `tests/test_inheritance_cascade.php`, `tests/test_templates_db.php`, and `tests/test_tenant_isolation.php` each issue a blanket `DELETE FROM tenants/users/base_plans/...` with **no transaction wrapper**, then insert a hardcoded-ID (`tenants.id = 1, 2`) fixture. This is safe only against an empty/disposable test database — running `tests/run_all.sh` against a database that also holds real or demo data destroys it, confirmed directly in the session that built `docs/09`'s demo seeder.

### Decisions already made
- Fix means: wrap each of these five tests' setup (the `DELETE FROM ...` calls and the fixture `INSERT`s) **and** the whole test body in a transaction, rolled back at the end — same pattern every other `_db.php` test in this suite already uses (see `tests/test_mfa_enforcement_db.php` or `tests/test_platform_settings.php` for the reference shape: `$db->beginTransaction()` before any fixture writes, `$db->rollBack()` at the very end, and note that `exit(1)` on a failed assertion does NOT run `finally` blocks in PHP — the transaction wrapper is what makes that safe, not `finally`).
- The hardcoded `tenants.id = 1, 2` (and similar hardcoded user IDs) pattern can stay **inside** the transaction — it's fine as long as it's never committed. Don't refactor these tests to use dynamic IDs unless the hardcoded-ID assumption itself turns out to be broken by the transaction wrapper (it shouldn't be — a transaction doesn't change what IDs are free, it just controls whether the insert becomes permanent).
- If a hardcoded ID collides with a real row *inside* the transaction (e.g. if this were somehow run against a database where tenant id 1 or 2 already legitimately exists), the test should fail loudly (a PDO exception is fine — don't swallow it) rather than silently succeeding against the wrong row. Confirm this is already the behavior (it should be, once wrapped in a transaction that never commits) rather than adding new handling.

### Build steps
1. For each of the five files: move the `DELETE FROM` cleanup and fixture `INSERT`s to right after `$db->beginTransaction()`, and add `$db->rollBack()` as the very last line (replacing whatever cleanup, if any, currently happens at the end — check each file individually, they may differ slightly).
2. Re-read each file fully before editing — don't assume they're identical in structure. `test_templates_db.php`'s grep hit showed a *different* tenant set (`'HorizonPlan Admin', 'Advisor Firm A', 'Advisor Firm B'`) than the other four — confirm the exact fixture shape per file before wrapping it.
3. Do not change what each test asserts — this is a safety-wrapper change only, not a test-logic change. Diff the pass/fail output before and after to confirm identical results.

### Verification
- Run each of the five tests individually before and after the change, confirm identical PASS output.
- **The real test**: seed the demo dataset (`php tools/seed_demo_data_full.php`), note the tenant/client counts, run the full `tests/run_all.sh`, and confirm the counts are **unchanged** afterward. This is the regression this session exists to prevent — don't skip it.
- Run the full suite twice in a row to confirm nothing is left in a bad state by a rolled-back transaction (e.g. auto-increment gaps are fine and expected; row counts and content are what must match).

### What NOT to build
- Don't add a "protect production" flag/guard to `tests/run_all.sh` itself (e.g. refusing to run against a non-test database) unless asked — that's a bigger, separate piece of infrastructure (environment detection, an explicit "this is a test DB" marker) and wasn't the ask. The transaction fix alone closes the actual destructive-mutation risk.

---

## Session 3: Rate limiting decision + implementation

### Goal
Decide whether `goals_*`, `subscenarios_*`, `platform_settings_*`, and `demo_reset.php` need rate limiting beyond the existing login-attempt limiter, and implement it if so.

### Decisions already made
- This is a **decide-then-build** session, not a pure build session — start by actually deciding, based on the following framing, rather than defaulting to "yes, add rate limiting everywhere":
  - Every one of these endpoints already requires an authenticated, CSRF-verified session — the risk isn't credential-stuffing (that's what the login limiter already covers), it's an authenticated user (or a compromised session) hammering the DB. At current expected scale (a handful of advisories, not a public API), this is a low-probability, low-blast-radius risk.
  - `demo_reset.php` is the one endpoint in this list with a real abuse case worth closing regardless of scale: a super_admin session (demo or otherwise) calling it repeatedly would repeatedly wipe and re-seed 160 clients, each call taking ~40 seconds — a self-inflicted denial-of-service on the app for the duration. This one should get a cooldown (suggest: refuse a second call within 5 minutes of the last one, tracked via a new column on `platform_settings` or a simple in-DB timestamp check) regardless of the broader decision.
  - For `goals_*`/`subscenarios_*`/`platform_settings_*`: **recommend deferring** general rate limiting unless a concrete abuse scenario or compliance requirement is named — this matches the honest "worth a decision, not fixed" framing already in `CLAUDE.md`, and building generic per-endpoint rate limiting without a real trigger risks becoming speculative infrastructure the way Phase 7's job-queue was correctly skipped.
- If you (the implementer, or whoever is directing this session) decide general rate limiting IS warranted after reviewing the above, use the existing `login_attempts`-style pattern (a table keyed by user_id/IP + a time window) rather than inventing a new mechanism — `checkLoginRateLimit()`/`recordLoginAttempt()` in `api/lib/security_gatekeeper.php` is the reference shape.

### Build steps (minimum: the `demo_reset.php` cooldown)
1. Add a `last_reset_at` (nullable `TIMESTAMP`) column to `platform_settings` via a new migration (`sql/021_platform_settings_reset_cooldown.sql` or similar — check the actual next-available migration number in `sql/` before naming it).
2. `demo_reset.php`: read `last_reset_at`, 429 (or 403, match existing convention) if a reset happened within the last 5 minutes, otherwise proceed and update `last_reset_at` to `NOW()` at the start of the reset (not the end — so a reset that's still running doesn't allow a concurrent second call).
3. If general rate limiting was decided on: extend the pattern to the relevant endpoints, documenting the chosen window/threshold and why, same as `LOGIN_RATE_LIMIT_WINDOW_MINUTES`/`LOGIN_RATE_LIMIT_MAX_ATTEMPTS` are documented today.

### Verification
- Live HTTP test: call `demo_reset.php` twice in quick succession, confirm the second call is rejected with a clear message and the first reset's data is untouched by the rejected second call.
- Confirm a reset succeeds again after the cooldown window (either wait it out in the test, or make the window configurable enough to test with a short value and confirm the real constant is set correctly for production).
- Add this to `tests/test_platform_settings.php` (or a new dedicated test) as a DB-integration test, not just a manual curl check.

### What NOT to build
- A general-purpose rate-limiting framework/middleware — this app has ~40 endpoints total; a shared helper function is the right level of abstraction, not a framework.

---

# Group 2 — Onboarding & Invite Flow

## Session 4: Magic-link invite activation

### Goal
Replace the shared-temp-password invite flow (`tenants_create.php`'s `first_advisor`, `admin_advisor_create.php`) with a real invite-link activation flow, reusing the existing `password_resets` token infrastructure rather than building a parallel mechanism.

### Decisions already made
- **Reuse `password_resets`** (migration 009): it already stores a SHA-256 hash of a single-use, 30-minute-expiry token, and `password_reset_confirm.php` already implements "redeem token → set new password → invalidate other tokens → clear active_sessions." An invite is conceptually identical to a password reset except the account has no usable password yet (or has a random one nobody will ever type) — the redemption flow is the same.
- Concretely: when `tenants_create.php`/`admin_advisor_create.php` creates a new advisor, instead of generating and displaying/emailing a `temporary_password`, generate a random unusable password hash (e.g. `password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)` — nobody will ever know or need this value) and immediately issue a `password_resets` row for that user, same as `password_reset_request.php` does. Email the resulting link (`/reset-password?token=...`) instead of a temp password. **30 minutes is too short for an invite** (a real admin-provisioned invite might not be opened same-day) — decide a longer invite-specific expiry (suggest 7 days) rather than reusing `PASSWORD_RESET_TTL` verbatim; this likely means parameterizing the expiry rather than hardcoding it, or adding a `purpose` column to `password_resets` (`'reset'` vs `'invite'`) if the two need different TTLs — check the existing schema before deciding which is less invasive.
- The on-screen fallback (showing a value in the admin console if email delivery fails) should become "here's the invite link" (copyable), not "here's a temp password" — the admin can hand the link to the advisor through another channel (Slack, WhatsApp) if email doesn't land, same fallback *purpose* as today, different *content*.
- This changes `tenants_create.php`, `admin_advisor_create.php`, and — check — whether `clients_create.php` has the same temp-password pattern for onboarding a client (docs/02 says clients are also admin/advisor-provisioned, not self-serve) and should get the identical fix for consistency, or whether clients have a different enough flow that it's out of scope this session (verify by reading `clients_create.php` before deciding).
- Frontend: `AdminConsole.jsx`'s `AddAdvisorForm`/`CreateFirmModal` (wizard step 2) currently generate and show a temp password field — this UI needs to become "an invite email will be sent" with the copyable-link fallback instead of a password field. `ClientGoals.jsx`'s add-client form, if it has the same pattern, needs the matching change.

### Build steps
1. Check the exact current schema of `password_resets` (migration 009) and decide: new `purpose` column with a longer TTL for `'invite'`, or a separate invite-specific table if the two purposes' constraints diverge enough (single-use, hash-only-storage, and invalidate-siblings-on-redeem should all still apply either way).
2. New migration if a schema change is needed.
3. Update `tenants_create.php`, `admin_advisor_create.php` (and `clients_create.php` if applicable) to issue an invite token + email an activation link instead of generating/returning a temp password.
4. A new (or reused, if `reset-password` page's UI already fits) frontend page/flow for "set your password" via the invite link — check whether `ResetPassword.jsx` can be reused as-is or needs an "invite" variant (e.g. different heading copy: "Welcome to HorizonPlan — set your password" vs. "Reset your password").
5. Update `AdminConsole.jsx`'s advisor-creation UI and `ClientGoals.jsx`'s client-creation UI (if applicable) to drop the temp-password field/display in favor of the "invite sent" confirmation + copyable link fallback.
6. Update `tools/seed_demo_data_full.php` and `tools/seed_demo_data.php` — **these seed real usable passwords for every demo account by design** (`DemoPass@2026`, so a human can actually log in and demo the product) — confirm this session's change doesn't accidentally break that (the seeders insert directly into `users`/`password_hash` via raw SQL, bypassing the endpoints entirely, so they should be unaffected, but verify this explicitly rather than assuming).

### Verification
- Real MySQL + real HTTP cycle: create a firm/advisor via the (now-changed) endpoint, confirm no usable temp password is ever returned in the API response or shown in the UI, confirm the invite token in `password_resets` redeems correctly via `password_reset_confirm.php` (or its invite equivalent) and results in a working login.
- Confirm the old temp-password login path this replaces (an admin manually giving out `temporary_password`) no longer works at all — i.e. this isn't an *additional* path, it's a *replacement*.
- Confirm token expiry (whatever TTL is chosen) is enforced, and re-invite (issuing a fresh token) works if the first one expires unused.
- Playwright: walk the full invite → email link (grab it from wherever it's logged/visible in dev, e.g. `error_log` if `demo_mode` suppression is on) → set-password → login flow with zero console errors.
- Re-run `tests/run_all.sh` (safe now, after Session 2) and confirm `tests/test_password_reset_db.php` and any templates/auth tests still pass unchanged, plus add new coverage for the invite-specific token behavior (longer TTL, `purpose` distinction if added).

### What NOT to build
- Self-serve signup — docs/04 is explicit that onboarding stays admin/advisor-provisioned. This session only changes *how* an already-admin-created account gets its first password, not who can create an account.

---

# Group 3 — Admin/Advisor Operational UX

## Session 5: First-time advisor onboarding walkthrough

### Goal
A newly invited advisor (after Session 4's invite flow) currently lands on an empty client list with no guidance. Add a lightweight guided first-run experience: add your first client → create a goal → (optionally) send a report.

### Decisions already made
- This is **frontend-only, zero new schema, zero new endpoints** — same "almost entirely composition" cost profile as Meeting Mode (`docs/07` Bet 5). Detect "first-run" client-side by an empty `clients_list.php` response (zero clients) — don't add a `has_completed_onboarding` column or similar; a client list of zero clients IS the first-run signal, and it naturally stops being true the moment the advisor adds their first client.
- Presentation: a dismissible checklist/banner on `Dashboard.jsx` (or wherever the advisor's home view is) with three steps, each a direct link/button into the existing flows (`+ New client` modal, then — once at least one client exists — a nudge toward creating a goal for them). Don't build a modal-takeover wizard forcing the sequence; an advisor should be able to ignore it and use the app normally.
- Persist dismissal in `localStorage` (client-side only) keyed by user id, not a DB column — this is pure UX affordance, not state that needs to survive a device switch or be auditable.

### Build steps
1. A new small component (e.g. `OnboardingChecklist.jsx`) rendered conditionally on `Dashboard.jsx` when the advisor has zero clients (or fewer than some small threshold — decide, e.g. "show until they have at least 1 client AND 1 goal", then never show again for that user).
2. Wire the "dismiss" action to `localStorage`.
3. Each checklist item links to the existing relevant action (reuse `NewClientModal`/whatever already exists — don't duplicate that UI).

### Verification
- Playwright: log in as a freshly invited advisor (zero clients), confirm the checklist appears; add a client, confirm the checklist updates/advances; dismiss it, reload, confirm it stays dismissed; log in as an advisor who already has clients, confirm it never appears. Zero console errors throughout.

### What NOT to build
- Don't build a generic "product tour" framework (tooltips-over-every-element, step libraries) — three checklist items covers the actual gap named in `docs/08`.

---

## Session 6: Audit-log UI for admins

### Goal
`change_log` (every mutation to `base_plans`/`sub_scenarios`, per non-negotiable rule #4) captures everything but nothing surfaces it to a human. Add a read-only view so an advisor/admin can answer "who changed what, and when."

### Decisions already made
- **Read-only, tenant-scoped, no new schema** — `change_log` already has everything needed (`entity_type`, `entity_id`, `field_changed`, `old_value`, `new_value`, `changed_by_user_id`, `changed_at`, `tenant_id`).
- New endpoint `api/change_log_list.php` — GET, `verifyAccessAny(['advisor', 'super_admin'])` (per `TenantScopedDb`'s existing tenant-scoping — an advisor sees their own tenant's history, a super_admin... decide whether super_admin gets cross-tenant visibility here or must pick a tenant context first; the simplest and most consistent-with-existing-precedent choice is: super_admin session behaves like an advisor of their *own* tenant for this endpoint, same as every other tenant-scoped read in the app, with a super_admin who needs another firm's history using the existing `tenant_detail.php`-style explicit `tenant_id` param if cross-tenant visibility is actually wanted — confirm this against how `goals_list.php` etc. already handle super_admin sessions before deciding).
- Filterable by `entity_type`/`entity_id` (so it can be embedded as a "history" tab on a specific goal's detail page, not just a firm-wide firehose) and paginated (this table will grow indefinitely — don't return unbounded results; add `LIMIT`/`OFFSET` or cursor-based paging, whichever matches this codebase's existing convention elsewhere — check if any other list endpoint already paginates before inventing a new pattern).
- Join in the acting user's email (via `TenantScopedDb::select()` or a raw tenant-scoped query if a join is needed — recall `clients_list.php`'s own precedent of dropping to a raw tenant-scoped query when `TenantScopedDb` can't express a `JOIN`) so the UI shows "advisor@firm.in changed withdrawal_rate from 3.5 to 4.0 on 2026-03-01" rather than a bare user ID.

### Build steps
1. `api/change_log_list.php`: tenant-scoped read with optional `entity_type`/`entity_id` filters and pagination, joined to `users.email`.
2. Frontend: a new "History" tab or panel on `GoalDetail.jsx` (entity-scoped: this goal's changes) **and** a firm-wide view somewhere in `AdminConsole.jsx` or a new page (decide based on where an admin would actually look — probably worth a firm-wide "Activity" page/tab given the framing in `docs/08` is "can I see who changed what" at the firm level, not just per-goal).
3. `api.js`: new `getChangeLog(entityType, entityId)` / `getChangeLogForTenant(...)` client methods.

### Verification
- Real MySQL + HTTP: make several goal/sub-scenario edits, confirm `change_log_list.php` returns them in the right order with correct old/new values and the correct acting user's email; confirm tenant isolation (a different tenant's advisor sees none of this).
- Playwright: view a goal's history tab after making an edit, confirm the new entry appears without a page reload issue; view the firm-wide activity view, confirm pagination works if there's enough data (the demo dataset's `change_log` may be sparse — consider whether to seed some via `tools/seed_demo_data_full.php` as a follow-up, or generate enough live edits during the Playwright walkthrough itself to exercise pagination).

### What NOT to build
- Don't add write/revert capability ("roll back to this value") — this is a read-only audit trail, not an undo system; reverting a value should go through the normal update endpoints (which will themselves write a new `change_log` row), not a special revert action.

---

## Session 7: Mobile responsiveness audit

### Goal
`docs/08` flagged that the Tailwind/`ui.jsx` design system is "responsive-by-convention" but never explicitly tested on a real small viewport. Audit and fix.

### Decisions already made
- This is an **audit-then-fix** session: start by actually loading every major page (`Dashboard`, `ClientGoals`, `GoalDetail`, `PlanReport`, `MeetingMode`, `AdminConsole`, `Settings`, `RiskQuestionnaireBuilder`) at a real small viewport (Playwright supports `page.setViewportSize({width: 375, height: 812})` — an iPhone-class width) and screenshot each, rather than guessing which pages are broken.
- `MeetingMode.jsx` deserves specific attention: it's explicitly a full-screen presentation view meant to be used **in a live client meeting**, plausibly on a tablet or phone in-hand rather than a laptop — if anything gets prioritized for an actual fix (vs. just documenting what's broken), start there.
- `PlanReport.jsx` (the client-facing, potentially printed/shared report) is the second priority — a client opening a shared report link on their phone is a realistic, not edge-case, scenario.
- Internal advisor/admin tools (`AdminConsole`, `RiskQuestionnaireBuilder`) are lower priority — these are used at a desk, not in the field — fix only if trivial, otherwise just document as a known gap.

### Build steps
1. Playwright audit pass across all major pages at 375px width (and maybe one tablet width, e.g. 768px), screenshot each, and — output a short written findings list (what's actually broken: overflow, unreadable text, unusable controls) before touching any code.
2. Fix `MeetingMode.jsx` and `PlanReport.jsx` issues found (if any) — these are the two pages worth spending real effort on per the priority above.
3. For everything else, either fix if trivial (e.g. a `grid-cols-3` that should be `grid-cols-1 sm:grid-cols-3` — a one-line Tailwind class change) or explicitly note as deferred in this session's own follow-up write-up.

### Verification
- Screenshots before/after for every page audited, not just the ones fixed — the "before" screenshots are the actual deliverable proving the audit happened, not just a claim.
- Playwright interaction test on `MeetingMode.jsx` and `PlanReport.jsx` specifically at the small viewport: confirm every control (nav arrows, sliders if present, buttons) is actually tappable/usable, not just visually present.

### What NOT to build
- A separate mobile app or a mobile-specific route/component tree — this is a responsive-CSS audit of the existing single SPA, not a new product surface (that's explicitly Phase 4/speculative per `docs/04`).

---

# Group 4 — Platform/Business Readiness

## Session 8: Billing / subscription / plan-tier concept

### Goal
Every tenant currently has identical capability — no seat limits, no plan gating, nothing to demo a monetization story with. This is the biggest architectural addition in this whole prompt set — treat it accordingly and don't rush it into one session if the scope below turns out bigger once you're in the schema.

### Decisions already made (the ones that can be decided in advance; others are flagged below as needing a real business decision before building)
- **Needs a real business decision first, not an engineering one**: what are the actual plan tiers, what do they gate (seats? clients? goals? advisory-mode access? templates?), and what's the billing model (per-seat, per-client, flat firm fee)? **Do not invent these numbers speculatively.** If this session starts without that decision made, the first deliverable is a short options write-up (2-3 concrete tier structures with tradeoffs) for a human to pick from — same posture as `docs/09`'s risk-questionnaire guardrail ("ships the mechanism, not the opinion").
- Given that constraint, what CAN be decided now, mechanically:
  - A `tenant_plans` (or `subscription_plans` + `tenant_subscriptions`) table structure: plan name, seat/client/goal limits (nullable = unlimited), price (informational only — **do not build real payment processing in this session**, see below), and a `tenants.plan_id` (or subscription-table) linking each firm to its current plan.
  - Enforcement: a new small helper (`TenantScopedDb` or a sibling) that checks "does this tenant have room for one more advisor/client" before `admin_advisor_create.php`/`clients_create.php`/`tenants_create.php`'s advisor-creation succeed, 403ing with a clear "plan limit reached" message otherwise.
  - Every existing tenant should default to an "unlimited" plan (or the highest tier) on migration — **this session must not silently degrade any currently-onboarded firm's capability**. Explicit backfill in the migration, not an assumed default.
  - Super Admin console gets a way to view/change a tenant's plan (extends `tenant_detail.php`/`FirmDetailModal`, same pattern as the existing compliance-mode/branding controls).
- **Explicitly do NOT build in this session**: real payment processing (Stripe/Razorpay integration), invoicing, dunning/failed-payment handling, or a self-serve upgrade/downgrade flow. Those are a distinct, much larger scope (real money, real compliance surface) that deserves its own dedicated session(s) after the schema/gating groundwork here is validated. This session's scope is the **data model and enforcement**, not the **money movement**.

### Build steps
1. Get the tier-structure decision made (see above) before writing any migration.
2. New migration(s): plan/subscription tables + `tenants` linkage, with an explicit backfill of existing tenants to an unlimited/highest tier.
3. Enforcement checks added to the relevant creation endpoints, returning a clear, distinct error (not a generic 400/403) so the frontend can show "you've hit your plan limit, contact your account manager" rather than a generic failure.
4. Super Admin UI to view/change a tenant's plan.
5. `tools/seed_demo_data_full.php`'s 4 demo firms should each get a plan assigned (varied across the 4, to demo the gating itself) — update the seeder once the schema exists.

### Verification
- Real MySQL + HTTP: create a tenant on a limited plan, add advisors/clients up to the limit, confirm the next creation attempt is cleanly rejected with the plan-limit message; confirm every pre-existing tenant (seeded before this migration) still has full capability post-migration.
- DB-integration test covering the backfill (every tenant existing before the migration ends up unlimited, not accidentally capped) and the limit-enforcement logic itself.
- Playwright: super_admin changes a firm's plan, confirm the new limit takes effect immediately (or on next relevant action) without requiring a re-login.

### What NOT to build
- Real billing/payment integration (see above — explicitly deferred).
- Usage-based metering/overage billing — decide plan *limits*, not consumption-based pricing, unless the business decision from the first step explicitly calls for it.

---

## Session 9: Advisor-facing analytics/engagement dashboard

### Goal
Already correctly scoped as a Phase 3 candidate in `docs/04`, not Phase 1/2 MVP scope — **do not start this session until Phase 2's validation gate has actually been checked and passed**, per `docs/04`'s own phase-gating rule (non-negotiable rule #6: don't build anything from a later phase without checking the phase validation gate first).

### Decisions already made
- This entire session is conditional: read `docs/04`'s Phase 3 criteria before doing anything else. If the gate hasn't been met, this session's actual deliverable is confirming that and stopping — not building the dashboard anyway because it's next on a list.
- If/when the gate is met: this dashboard is about advisor/firm *engagement with the platform itself* (login frequency, feature usage, client-meeting cadence via Meeting Mode usage, report-generation frequency) — a retention/adoption signal for the SaaS provider (super_admin), not a client-facing or even firm-facing analytics product. Don't conflate this with the existing `Dashboard.jsx` (which is the advisor's view of *their clients'* health, already built) — this is the *platform's* view of *advisor* engagement, a different audience (super_admin) and a different question.

### Build steps
Do not plan these until the gate check above is actually done — a real scope here depends entirely on what Phase 3's validation actually asked for, which isn't fully specified yet in this prompt set on purpose (per the "don't build ahead of validation" rule this whole roadmap is trying to respect).

### What NOT to build
- Anything, until the gate is confirmed met. This entry exists in the sequence so it isn't forgotten, not as a green light.
