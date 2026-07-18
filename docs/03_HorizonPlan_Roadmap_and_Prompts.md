# HorizonPlan MVP — Implementation Roadmap & Prompts

Companion to `02_HorizonPlan_Project_Instructions.md` and `04_HorizonPlan_Feature_Roadmap.md`. Upload all three to your project's instructions. This file covers **build order for Phase 1 (MVP) only** — what to build and in what sequence to ship the validated core: auth, tenant isolation, multi-goal support, the Live Timeline Slider, the withdrawal-rate/corpus-multiple mechanic, the decumulation sequence-of-returns projection chart (the actual differentiator — see the roadmap's Phase 1 thesis), and the Global Inheritance Engine. It deliberately excludes anything the feature roadmap places in Phase 2+ (accumulation-phase modeling, advisory mode activation, risk profiler, WhatsApp sharing, the tax-optimized distribution engine, etc.) — don't pull those forward into these prompts without checking the Phase 1 validation gate first.

Phase order below is deliberate: security/tenant-isolation scaffolding comes before any feature work, because retrofitting it later is the expensive path.

---

## Phase 0 — Decisions & repo scaffolding
**Goal:** resolve the open market-scope question, set up the repo skeleton, no feature code yet.

**Prompt:**
> Before writing any code: I need to decide the target market question in Section 0 of the project instructions (India-first MFD scoping vs. US-first advisor scoping). [State your answer here, or ask me to help you decide by comparing the two paths.] Once that's settled, scaffold the repo structure: `/api` for PHP endpoints, `/api/lib` for shared helpers (security_gatekeeper.php, TenantScopedDb.php), `/frontend` for the React SPA, `/sql` for schema migration files. Don't implement any endpoint logic yet — just the folder structure, a `.gitignore` that excludes config/credential files, and a `README.md` describing the structure.

---

## Phase 1 — Database schema
**Goal:** all tables from the instructions file, correctly, before any app code touches them.

**Prompt:**
> Write the SQL migration files for the schema described in the project instructions: `tenants`, `users`, `base_plans`, `sub_scenarios`, plus the new `change_log` and `login_attempts` tables. Use the exact column definitions from the original blueprint where specified, add the new tables per Section 3.3 and 3.2 of the instructions, add the `advisory_mode` column to `tenants` per Section 3.6 (default `'distribution'`), add the multi-goal columns to `base_plans` per Section 4.1 (`goal_type`, `goal_label`, `target_amount`, `target_date`), add the withdrawal-rate columns per Section 4.2 (`base_plans.withdrawal_rate`, `sub_scenarios.custom_withdrawal_rate` — both nullable, both participating in the existing `is_overridden` flag, no new override column), and add the decumulation projection columns per Section 4.3 (`base_plans.drawdown_return_rate`, `sub_scenarios.custom_drawdown_return_rate` — same override pattern — and `base_plans.projection_horizon_years INT NOT NULL DEFAULT 30`). Output as numbered migration files (`001_tenants.sql`, `002_users.sql`, etc.) so they can be run in order. Do not add any tables not mentioned in the instructions, and do not build a `jobs` table yet — that's conditional per Section 3.5 and the feature roadmap's Phase 3 gate.

---

## Phase 2 — Shared security & data-access layer
**Goal:** build the two helpers everything else depends on, before any real endpoint.

**Prompt:**
> Build `api/lib/security_gatekeeper.php` and `api/lib/TenantScopedDb.php` per Section 3.1 and 3.2 of the project instructions. Requirements:
> - `security_gatekeeper.php`: token resolution, session expiry check, role verification, rate limiting on repeated failed attempts (using the `login_attempts` table).
> - `TenantScopedDb.php`: a PHP class wrapping select/insert/update/delete that always injects `tenant_id` from the verified session and gives calling code no path to bypass it.
> - Fix the `json_decode` vs `json_encode` bug from the original blueprint everywhere JSON request bodies are read.
> - Include inline comments explaining why each security check exists, so it's reviewable.
>
> After writing this, walk me through how you'd try to break tenant isolation as an attacker, and confirm the helper actually blocks each attempt.

---

## Phase 3 — Auth endpoints
**Goal:** login, session issuance, logout — with rate limiting and secure token handling from the start.

**Prompt:**
> Build the login, logout, and session-check endpoints using `security_gatekeeper.php`. Token should be issued as an httpOnly, secure, sameSite cookie per Section 3.2 — not returned in a JSON body for client-side storage. Include the rate-limiting/lockout behavior. Don't build password reset or MFA yet — just flag in your response that both are still outstanding before this can go to real users.

---

## Phase 4 — Core plan endpoints
**Goal:** goal (base_plans) and sub_scenarios CRUD, routed through the Phase 2 helpers, with the Global Inheritance Engine cascade and change_log writes.

**Prompt:**
> Build the base_plans and sub_scenarios endpoints using `TenantScopedDb.php` for all data access — no inline tenant_id WHERE clauses. Since multi-goal is in scope (Section 4.1), include: an endpoint to list all goals for a given client (a client has multiple `base_plans` rows), and create/read/update on individual goals including the `goal_type`, `goal_label`, `target_amount`, `target_date` fields. For retirement-type goals, include `withdrawal_rate` / `custom_withdrawal_rate` per Section 4.2, and have the read endpoint return a computed `corpus_multiple` value (1 ÷ effective withdrawal rate) alongside the stored fields — don't store it. Also build the projection endpoint per Section 4.3: given a goal, return the two computed year-by-year series (steady-return and adverse-sequence) as arrays of yearly balances, using the deterministic arithmetic described there — no external math libraries needed, this is a straightforward loop. Implement the Global Inheritance Engine cascade exactly as described in the original blueprint (parent updates skip rows where `is_overridden = 1`, scoped per goal, covering `custom_inflation`, `custom_withdrawal_rate`, and `custom_drawdown_return_rate`) and the reset-trigger endpoint. Every mutation to these tables must write a row to `change_log` per Section 3.3. Show me the cascade logic and the change_log write together so I can confirm they're actually paired correctly.

---

## Phase 5 — Frontend: auth + goals list + plan views
**Goal:** React SPA shell, login flow, a goals list view, and the individual goal / sub-scenario views wired to Phase 3/4 endpoints.

**Prompt:**
> Build the React SPA shell with Tailwind and shadcn/ui: login page, a goals list view for a client (showing each goal's label, type, and target if set), and a detail view for a single goal showing its sub-scenarios. Wire it to the Phase 3 and Phase 4 endpoints. Use cookie-based auth (no token in localStorage). Every client-facing plan view must display the distribution-mode disclosure copy from Section 3.6 of the instructions — all MVP tenants are distribution-mode, so this always renders for now. Keep this to the minimum needed to see real data flow end-to-end — don't build the Live Timeline Slider yet.

---

## Phase 6 — Live Timeline Slider + sequence-risk chart + Reset Trigger UI
**Goal:** the specific interactive component from the original blueprint's Section 6, the withdrawal-rate control, and the decumulation projection chart — together, since they're meant to be viewed as one unit.

**Prompt:**
> Build the Live Timeline Slider component per the original blueprint's Section 6: local React state updates while dragging, single API dispatch on drag-release, and the Reset Trigger Control (soft alert border + reset arrow when `is_overridden === true`, calling the reset endpoint). Keep the debounce-on-release behavior — don't fire requests per drag frame. For retirement-type goals, add a second slider for withdrawal rate (default 3.5%, range ~2.5%–4% per Section 4.2 — do not default to the US 4%/25x convention) with a live-updating "corpus multiple" readout next to it (e.g. "≈28.6× annual expenses"), computed client-side from the slider value for instant feedback, confirmed against the API's computed value on save. Below both sliders, render the sequence-risk chart per Section 4.3: a line chart with two series (steady-return vs. adverse-sequence) from the projection endpoint, redrawn whenever either slider moves (debounced the same way). Use `recharts` for the chart. Render the distribution-mode disclosure copy immediately adjacent to this whole panel, not just elsewhere on the page — it's the most advice-adjacent part of the MVP.

---

## Phase 7 — Heavy computation (only if needed)
**Goal:** background job pattern for any forecasting logic that risks exceeding Hostinger's execution-time ceiling. Skip this phase entirely if MVP forecasting logic is provably fast enough to run synchronously — don't build infrastructure you don't need yet.

**Prompt:**
> Before building this: tell me what the actual forecasting/simulation computation involves and roughly how long it takes for a realistic scenario. If it's comfortably under a few seconds, we don't need this phase — say so and stop. If it risks approaching the execution-time ceiling, build the `jobs` table processing per Section 3.5: a job-submission endpoint, a cron-triggered worker script, and a frontend polling mechanism.

---

## Phase 8 — Pre-launch hardening pass
**Goal:** close the remaining gaps flagged in the architecture review before any real client data touches this.

**Prompt:**
> Do a security pass against `01_HorizonPlan_Architecture_Review.md` and the project instructions. Specifically confirm: MFA is implemented, no credentials are committed to the repo, all endpoints route through the two shared helpers with no exceptions, `change_log` is actually being written on every relevant mutation, and CSRF protection is in place given the cookie-based auth. Report back file-by-file, not just "looks good" — list what you checked and what you found in each one.

---

## Notes on using these prompts
- Run them in order. Phase 2 in particular should not be skipped or rushed — every later phase depends on it, and it's far cheaper to get right now than to retrofit once there are twenty endpoints.
- After each phase, ask Claude to point out anything from the architecture review it hasn't addressed yet, rather than assuming later phases will catch it.
- Phase 7 is conditional — don't build background job infrastructure speculatively if the actual computation turns out to be fast.
