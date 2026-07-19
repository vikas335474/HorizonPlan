# HorizonPlan

B2B2C retirement planning platform for Indian MFDs/IFAs and SEBI-RIA firms. Full context lives in `/docs/` — read the relevant file before starting work on that area, don't assume this file alone is enough.

## Stack
PHP (no framework) + PDO + MySQL, hosted on Hostinger Premium. React SPA (Tailwind, shadcn/ui), pre-compiled — Hostinger serves static files, it does not run a Node build step, so the frontend is always built before deploy (see `.github/workflows/`).

## Docs — read before touching the matching area
- `docs/01_HorizonPlan_Architecture_Review.md` — original security/architecture audit. Read before touching auth or the data-access layer.
- `docs/02_HorizonPlan_Project_Instructions.md` — the actual build spec: schema, security rules, tenant model. **Read this before writing any backend code.**
- `docs/03_HorizonPlan_Roadmap_and_Prompts.md` — Phase 1 (MVP) build order. Follow this sequence; don't build Phase 2+ items without checking `04` first.
- `docs/04_HorizonPlan_Feature_Roadmap.md` — what belongs in MVP vs. later, and why. Check the "explicitly out of scope" list before adding anything not asked for.
- `docs/05_HorizonPlan_Practitioner_Validation_Review.md` — why certain features exist (e.g. India-specific withdrawal rates, sequence-of-returns chart).

## Non-negotiable rules (always apply, every session)
1. **Tenant isolation:** every query on tenant-scoped data goes through `api/lib/TenantScopedDb.php`. Never write a raw `WHERE tenant_id = :tenant_id` inline in an endpoint file — if the helper doesn't exist yet, build it first (see `docs/02`, Section 3.1).
2. **`advisory_mode` on tenants is Super Admin-only**, enforced server-side, never a self-serve toggle. See `docs/02` Section 3.6.
3. **Distribution-mode disclosure copy** renders on every client-facing plan view, and directly adjacent to the withdrawal-rate slider and projection chart specifically.
4. Every mutation to `base_plans` / `sub_scenarios` writes to `change_log`.
5. No credentials in the repo. `db_config.php` stays out of git.
6. Don't build anything from `docs/04`'s "explicitly out of scope" lists without checking the phase validation gate first.

## Current phase
Phase 1 (MVP) — see `docs/03` for build order. Work through it in sequence; each phase there is meant to be a separate, focused session rather than one long continuous one.

Phases 0–4 are built: schema, `TenantScopedDb`/`security_gatekeeper` helpers, auth endpoints, and the `base_plans`/`sub_scenarios` CRUD + Global Inheritance Engine cascade + decumulation projection endpoints (`api/goals_*.php`, `api/subscenarios_*.php`, `api/lib/PlanMath.php`). One addition beyond the original Phase 2 helper: `security_gatekeeper.php` now also has `verifyAccessAny(array $roles)`, since Phase 4 endpoints need "advisor OR client" access checks that the single-role `verifyAccess()` doesn't support — same fail-closed contract, just a role list instead of one role.

Phase 5 is built: `frontend/` — Vite + React SPA, Tailwind v4, cookie-based auth (no token in localStorage; `fetch` calls use `credentials: 'include'` against relative `/api/...` paths so it's same-origin in production). Covers login, a goals list, and a goal detail view with its sub-scenarios, wired to the Phase 3/4 endpoints as they're actually shaped (not assumed) — notably `login.php` and `session.php` return the user object under different keys (`id` vs `user_id`), which the frontend normalizes in one place (`context/AuthContext.jsx`) rather than handling ad hoc. The Section 3.6 distribution-mode disclosure copy is hardcoded to the distribution-mode string for now since `advisory_mode` isn't yet exposed by any endpoint — this is a real gap to close, not an oversight, if a tenant is ever flipped to advisory mode: see the comment in `components/DisclosureBanner.jsx`. There's also no `.github/workflows/` build pipeline yet; the frontend is currently built locally (`npm run build`) and the `dist/` output uploaded manually into `public_html` via Hostinger File Manager — automating that onto the `deploy` branch is a deliberate later decision, not done here.

Phase 6 is built: the Live Timeline Slider (`components/LiveTimelineSlider.jsx`), Reset Trigger Control, and the withdrawal-rate slider + corpus-multiple readout + sequence-risk chart (`components/ScenarioPanel.jsx`, `SequenceRiskChart.jsx`, using `recharts`) — wired to `subscenarios_update.php`, `subscenarios_reset.php`, and `goals_projection.php` as they actually behave, not as the Phase 6 prompt's prose implied:
- `goals_projection.php` only accepts a persisted `sub_scenario_id` (or none, for the parent goal's own values) — it does not take ad hoc rate parameters. So the chart is **not** literally redrawn on every drag frame; it redraws once per committed save (same debounce as the write), which is what "redrawn whenever either slider moves (debounced the same way)" resolves to given the real endpoint shape. The corpus-multiple readout, by contrast, genuinely is live client-side feedback per Section 4.2, computed with the same formula as `PlanMath::corpusMultiple()`.
- "Debounced on release" is implemented as an actual release event (`onMouseUp`/`onTouchEnd`/`onKeyUp`), not a timer — this is stricter than a debounce, not looser: exactly one write per drag, and one per discrete keyboard adjustment.
- Both sliders write through the *same* `is_overridden` flag per `subscenarios_update.php` (docs/02 4.2 — not a bug, documented shared-flag behavior), so touching either one freezes the whole sub-scenario from cascade updates on all three fields.
- `recharts` pushes the production JS bundle over Vite's 500kB warning threshold (611kB currently). Not addressed here — code-splitting the chart behind a dynamic `import()` would fix it, but that's a real optimization to schedule, not something to silently ignore.

## Deploy
Hostinger's native Git deployment (hPanel → Advanced → Git) watches a `deploy` branch and auto-deploys on push — no SSH needed for this path. GitHub Actions builds the React app and pushes the compiled output to `deploy`; `main` stays the working branch. SQL migrations in `/sql` are **not** run automatically by Hostinger's git-deploy — run new migrations manually via hPanel's database tool after each deploy that includes one.
