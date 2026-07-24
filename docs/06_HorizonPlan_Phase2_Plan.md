# HorizonPlan — Phase 2 Implementation Plan

**Status:** planning doc for a future build session. Nothing here is built yet.
Companion to `02_HorizonPlan_Project_Instructions.md` (the spec) and
`04_HorizonPlan_Feature_Roadmap.md` (which places every item below in Phase 2).
Read those two first. This document turns Phase 2's "candidate scope" into a
concrete, executable plan — but per the roadmap's own discipline, **confirm each
item against real Phase 1 usage feedback before building it.**

---

## Context — why this exists

Phase 1 shipped the differentiator: multi-goal planning plus a deterministic
**decumulation** (withdrawal-phase) projection with a sequence-of-returns stress
test, a withdrawal-rate/corpus-multiple mechanic, the cascade/override engine,
the Super Admin onboarding + white-label tier, and (new) a small **decumulation
strategy-preset** foundation (`frontend/src/lib/strategyPresets.js`).

Phase 2 broadens this from "will an existing corpus survive withdrawal?" to the
client's **full journey**: the saving years before retirement (accumulation),
a documented risk profile, richer multi-variable scenarios, activation of the
**advisory-mode** tier, and — the specific request that prompted this doc — a
**strategy library** so advisors can run/compare named investment models (e.g.
by client age) instead of hand-tuning every assumption.

**Intended outcome:** an advisor can model a client from their current age
through retirement, apply a named strategy, compare alternatives on one chart,
and (for RIA tenants) attach a documented risk profile — all still deterministic
arithmetic within Hostinger's execution budget.

---

## Guardrails — read before writing any code

1. **Never merge accumulation and drawdown return into one field.** Add a new
   `accumulation_return_rate`, distinct from the existing `drawdown_return_rate`.
   Using one rate for both phases is a specifically documented common planning
   error (`docs/05` item 1). This is non-negotiable.
2. **Strategy definitions are DATA, not hardcoded formulas, and not mine to
   invent.** Ship the *mechanism* (a `strategy_templates` table + admin UI) and
   let the firm's CFA/RIA supply the actual parameters and any age-suitability
   claims. Do not bake specific allocation/withdrawal numbers into code as
   authoritative advice. Well-known public heuristics (below) may be offered as
   clearly-labelled, editable starting points that require professional sign-off
   before a tenant uses them with real clients. **This sign-off must be
   enforceable data, not just process:** both `strategy_templates` and the risk
   questionnaire/scoring carry an explicit approval state (see C and B), and an
   unapproved definition is illustration-only — it cannot be applied to, or
   presented on, a real client's plan until a firm principal (CFA/RIA) marks it
   approved for that tenant. Seeded global starters ship as `draft`, never
   pre-approved. This is the data-level guarantee behind "have your CFA/RIA
   supply the definitions before those parts ship to real clients."
3. **Suitability language is a compliance control (`docs/02` §0, §3.6).**
   "Recommended for a 25-year-old," "ABC is the better strategy," or any
   best-for-you framing is *advice*. It may render **only for advisory-mode
   (SEBI-RIA) tenants**. Distribution-mode (MFD) tenants get neutral
   comparison/illustration language only. The `advisory_mode` wiring already
   exists (`tenants.advisory_mode`, exposed via `session.php`, read by
   `DisclosureBanner.jsx`) — gate all suitability copy on it.
4. **Keep the override model consistent.** New per-scenario override fields join
   the *existing* single `is_overridden` flag — do **not** add a second flag
   (`docs/02` §4.2).
5. **Stay deterministic.** Phase 2 is still closed-form arithmetic. Monte Carlo /
   probability-of-success and the background-job infrastructure stay in Phase 3.
6. **Validation-gate discipline.** `docs/04` gates Phase 2 on real Phase 1
   feedback (are advisors hitting friction from independent goals? what do they
   want to stress-test?). Build the items feedback confirms, not all of them
   speculatively — cross-goal allocation and corpus composition especially are
   marked "only if Phase 1 usage shows real friction."

---

## Current state this builds on (for the new session)

- **Schema** (`sql/`): `base_plans` has `goal_type, goal_label, target_amount,
  target_date, initial_net_worth, inflation_rate, withdrawal_rate,
  drawdown_return_rate, projection_horizon_years`. `sub_scenarios` has
  `custom_inflation, custom_withdrawal_rate, custom_drawdown_return_rate,
  is_overridden`. No accumulation, age, SIP, or risk fields exist yet.
- **Engine**: `api/lib/PlanMath.php` — pure in-memory arithmetic, `corpusMultiple()`,
  `steadyReturnSeries()`, `adverseSequenceSeries()`. No DB access. Extend it here.
- **Projection endpoint**: `api/goals_projection.php` returns
  `steady_return_series` + `adverse_sequence_series` for retirement goals.
- **Override/cascade**: `api/subscenarios_update.php`, `subscenarios_reset.php`,
  cascade in `goals_update.php`; all covered by `tests/test_inheritance_cascade.php`.
- **Preset foundation**: `frontend/src/lib/strategyPresets.js` — decumulation-only,
  client-side, hardcoded. Phase 2 supersedes it with server-driven
  `strategy_templates` (migrate these three presets in as seed data).
- **Tenancy/roles**: `TenantScopedDb` (allowed tables: base_plans, sub_scenarios,
  change_log, users), `security_gatekeeper.php` (`verifyAccess`,
  `verifyAccessAny`, super_admin always allowed). Super Admin console exists.
- **Tests/CI**: `tests/*.php` + `.github/workflows/tests.yml` run against MySQL.
  Every new engine/endpoint gets a test here — this is what catches DB-only bugs.

---

## Scope & design

### A. Accumulation-phase modelling

**Schema (`sql/009_accumulation.sql`):** add to `base_plans` (all NULL, only
meaningful for `goal_type='retirement'`):
- `accumulation_return_rate DECIMAL(4,2) NULL` — pre-retirement expected return
  (distinct from `drawdown_return_rate` — guardrail 1).
- `current_age SMALLINT UNSIGNED NULL`, `retirement_age SMALLINT UNSIGNED NULL`
  — years-to-retirement = retirement_age − current_age.
- `monthly_sip_amount DECIMAL(15,2) NULL` — recurring contribution.
- `sip_step_up_rate DECIMAL(4,2) NULL` — optional annual SIP increase (standard
  Indian practice; matches Investwell Mint's step-up calculator per `docs/05`).

Add matching `custom_*` override columns to `sub_scenarios`, joined to the
existing `is_overridden` flag (guardrail 4).

**Engine (`PlanMath.php`):** add `accumulationSeries(...)` — year-by-year corpus
from `current_age` to `retirement_age`: `balance[n] = balance[n-1] *
(1 + accumulation_return_rate) + annual_SIP_with_stepup[n]`. Then a combined
`lifecycleSeries()` that runs accumulation then hands its terminal corpus to the
existing decumulation series, producing one continuous curve. Keep it O(years),
deterministic. Add `tests/test_accumulation.php` (pure) and extend
`test_auth_db.php`/projection coverage.

**Endpoint:** extend `goals_projection.php` to return an `accumulation_series`
(and a combined series) when the accumulation fields are set; keep it backward
compatible (retirement goals without the fields behave as today).

**Frontend:** goal-creation modal (`ClientGoals.jsx` `NewGoalModal`) and goal
detail gain the accumulation inputs; the chart (`SequenceRiskChart.jsx` or a new
`LifecycleChart.jsx`) shows the saving years leading into the withdrawal years.

### B. Risk profiler

**Schema (`sql/010_risk_profiles.sql`):** new `risk_profiles` table
(`id, tenant_id, client_id, score, band ENUM('conservative','moderate','aggressive'),
answers JSON, created_by_user_id, created_at`). Tenant-scoped → add to
`TenantScopedDb::ALLOWED_TABLES`.

**Design:** a short structured questionnaire → a score that **informs, never
dictates**, the return-rate assumptions elsewhere. The questionnaire content and
scoring must come from the firm/CFA (compliance value re: SEBI scrutiny of
aggressive-fund mis-selling — `docs/05` item 2/new-item). Ship the mechanism and
an editable question set; don't invent the scoring rubric as authoritative.
**Approval gate (guardrail 2):** the active question set + scoring rubric for a
tenant carries an approval state (a `risk_question_sets` row, or an `approved_by
_user_id`/`approved_at` pair on the set) set by a firm principal. Until it's
approved, a submitted profile may be captured but its band must **not** surface
as a suggested return assumption on a client plan — the mechanism ships inert,
the firm's own definition is what turns it on. No HorizonPlan-authored default
rubric is ever marked approved on the firm's behalf.

**Endpoints:** `risk_profile_submit.php` (advisor/super_admin), `risk_profile_read.php`.
**Frontend:** questionnaire on the client/goal view; the resulting band surfaces
as a *suggested* return assumption (advisor still sets the number).

### C. Strategy library (the age-based request, done responsibly)

This replaces the Phase 1 hardcoded `strategyPresets.js` with server-driven,
firm-editable templates.

**Schema (`sql/011_strategy_templates.sql`):** `strategy_templates`
(`id, tenant_id NULL, name, description, category ENUM('accumulation',
'decumulation','allocation'), applies_when JSON, params JSON, provenance TEXT,
requires_advisory_mode TINYINT(1) DEFAULT 0, status ENUM('draft','approved')
DEFAULT 'draft', approved_by_user_id NULL, approved_at DATETIME NULL,
created_by_user_id, created_at`).
- `status` = the enforceable sign-off gate from guardrail 2. Only `approved`
  templates may be applied to a real client goal/sub-scenario; `draft` templates
  are visible in the admin UI for review but cannot be applied client-side. A
  firm principal (CFA/RIA) approving it sets `status='approved'` +
  `approved_by_user_id`/`approved_at`. `strategy_templates_create/update.php`
  must reset `status` to `draft` on any change to `params`/`applies_when` so an
  edited definition re-enters review rather than silently keeping stale approval.
- `tenant_id NULL` = a global/starter template; non-null = a firm's own. Global
  starters are seeded `draft` and are **never directly applicable to a client** —
  a firm adopts one by cloning it into its own `tenant_id` row (starting `draft`),
  then its principal approves that copy. This keeps approval firm-scoped without a
  per-tenant status on the shared global row, and guarantees a HorizonPlan-seeded
  heuristic is never approved on any firm's behalf.
- `applies_when` = optional hints, e.g. `{"min_age":20,"max_age":25}` — used for
  *filtering/labelling*, never auto-applied as advice.
- `params` = the assumption bundle applied to a goal/sub-scenario (e.g.
  `{"custom_withdrawal_rate":3.0}`, or accumulation params).
- `provenance` = where the definition came from (the firm's CFA/RIA, a cited
  source). Required — no anonymous "just trust it" strategies.
- `requires_advisory_mode` = if true, only advisory-mode tenants may present it
  with suitability language.

**Seed data (starter templates, `tenant_id=NULL`):** migrate the three existing
decumulation presets in. Additional well-known *public* heuristics may be seeded
as **clearly-labelled, editable, sign-off-required** starting points — e.g. the
"100/110/120 − age" equity-allocation rule of thumb, target-date glide paths,
the bucket strategy, Guyton-Klinger guardrails. **Do not present any of these as
HorizonPlan's recommendation.** Each carries a `provenance` note and, if it makes
an age-suitability claim, `requires_advisory_mode=1`.

**Endpoints:** `strategy_templates_list.php` (advisor+), `strategy_templates_create/update.php`
(super_admin for global, advisor for own-tenant — decide from feedback).
Applying a template reuses the existing flow the Phase 1 presets already use:
`subscenarios_create.php` → `subscenarios_update.php` with the template's `params`.

**Frontend:** a strategy picker on the goal detail (extends the current
"Compare a strategy preset" panel) that lists templates, shows `applies_when`
as a neutral tag in distribution mode ("often used for ages 20–25") vs. an
advisory recommendation only when `advisory_mode==='advisory'`. A compare view
(2–3 scenarios side by side on one chart) is the high-value addition here.

### D. Advisory-mode activation flow

Super Admin can already flip `advisory_mode` (Phase 1: `tenant_update.php` +
console). Phase 2 adds the **advisory-language client output**: confirm the exact
advisory disclosure/recommendation copy with the RIA partner (placeholder lives
in `DisclosureBanner.jsx`), and let suitability labels (C) and any advice framing
render for advisory tenants. Treat every advisory string with auth-level care.

### E. Conditional — only if Phase 1 feedback demands it

- **Cross-goal portfolio allocation** (shared corpus split across goals) — a real
  optimisation feature, not a schema patch. Build only if advisors report
  friction from independent goals (`docs/04` design decision).
- **Corpus composition** (liquid vs locked: NPS/PPF/EPF buckets) — `docs/05`
  item 3. Only if usage shows it as real friction.
- **WhatsApp report sharing** — validated engagement pattern; build once stable.

---

## Compliance checklist (every Phase 2 PR)

- [ ] No suitability/recommendation copy renders in distribution mode.
- [ ] Any age-based/"best" framing is gated on `advisory_mode==='advisory'`.
- [ ] Seeded strategy templates carry `provenance` and correct
      `requires_advisory_mode`; none are presented as HorizonPlan's own advice.
- [ ] Sign-off is enforced by data, not prose: only `status='approved'`,
      firm-owned strategy templates can be applied to a client, and an unapproved
      risk question set never surfaces a suggested return band. Seeded/global
      starters and any HorizonPlan-authored default rubric remain `draft`.
- [ ] `accumulation_return_rate` stays distinct from `drawdown_return_rate`.
- [ ] New tenant-scoped tables go through `TenantScopedDb`; `advisory_mode`
      remains super-admin-only to change.
- [ ] Every mutation still writes `change_log`.

## Validation gates

- **Entry (from `docs/04`):** Phase 1 is live with real MFD/IFA tenants using
  multi-goal + sequence-risk with real clients, and you have feedback on (a) does
  the chart change conversations, (b) which variables clients want to stress-test,
  (c) friction from independent goals. Build B/C/E toward what that feedback says.
- **Exit (from `docs/04`):** the advisory tier has ≥1 real RIA-reviewed tenant
  using it with clients, and an RIA-endorsed spec for what the withdrawal/
  distribution engine should compute for India — the entry condition for Phase 3.

---

## Suggested build order (one focused session each)

1. **Schema + engine for accumulation** (A): migrations, `PlanMath` series +
   pure tests, extend `goals_projection.php` + DB test. No UI yet.
2. **Accumulation UI**: goal fields + lifecycle chart.
3. **Strategy templates** (C): schema, endpoints, migrate the 3 presets, admin
   UI to define templates, goal-detail picker + compare view.
4. **Risk profiler** (B): schema, endpoints, questionnaire UI.
5. **Advisory-mode output** (D): confirmed copy + suitability gating.
6. **Conditional items** (E) — only if feedback demands.

Run each phase's prompt style from `docs/03`: build, then ask what from the
architecture review / this doc it hasn't addressed yet.

## Verification

- Pure engine tests (`tests/test_accumulation.php`, etc.) + the MySQL CI in
  `.github/workflows/tests.yml` for anything touching the DB (this is what
  catches native-prepare / SQL-only bugs like the Phase 1 login `INTERVAL` bug).
- Manual: create a retirement goal with accumulation fields, verify the combined
  projection is continuous and matches a by-hand calc for year 1 of each phase;
  apply a strategy template and confirm it lands as a sub-scenario with the
  correct `params` and the projection redraws from the server.
- Compliance: log in as a distribution-mode tenant and confirm no suitability
  language appears anywhere; flip a test tenant to advisory mode and confirm it
  does.
- Sign-off gate: confirm a `draft` strategy template (and a seeded global starter)
  cannot be applied to a client goal server-side — not merely hidden in the UI —
  and that an unapproved risk question set captures a profile without surfacing a
  suggested return band. Add a DB test asserting the apply path rejects a
  non-`approved` template.
