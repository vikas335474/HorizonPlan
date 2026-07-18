# HorizonPlan MVP — Project Instructions

Use this as the standing instruction set for building HorizonPlan. It supersedes the original blueprint's implementation details in the specific places marked **[REVISED]** below; everything else from the original blueprint stands as designed.

---

## 0. Target market & persona — decided

**Market: India-first.** Primary buyer is the Indian AMFI-registered MFD / IFA (Independent Financial Advisor — the standard colloquial term for an MFD in this market). This is the volume segment: tens of thousands of AMFI-registered distributors, with several thousand new registrations per month, versus fewer than 1,000 SEBI-registered RIAs nationally. A lean, low-overhead build matches this segment's price sensitivity better than the enterprise-priced US market (RightCapital/eMoney/MoneyGuidePro territory, where incumbents already ship tax-optimized withdrawal sequencing and AI-assisted planning at $1,500–2,400+/year).

**Two tenant modes, not two products:**
- **Distribution mode (default):** for MFD/IFA tenants. Client-facing output is framed as goal *visualization/illustration*, never as personalized advice — this matches how existing Indian MFD software (REDVision/Wealth Elite's "Goal GPS," IFA-Planet, JezzMoney) already stays compliant with SEBI's Investment Advisers Regulations, 2013, which bar MFDs from giving personalized investment advice.
- **Advisory mode:** for SEBI-RIA tenants. Same underlying engine, but client-facing output can carry recommendation/advice language, because a registered fiduciary is accountable for it.

**Gating rule — this is a compliance control, not a UI toggle:** a tenant is only switched to advisory mode after a SEBI-registered RIA partner has actually reviewed and stands behind that specific tenant. It is never a self-serve signup checkbox. See Section 3.6.

**Deferred, not dropped:** the original blueprint's Section 7 distribution/withdrawal-sequencing engine used US tax terminology (taxable vs. tax-deferred accounts), which doesn't map to Indian retail investment structures. Don't replace it with an unvalidated Indian tax engine either — that's real complexity (equity/debt MF capital gains treatment, NPS/PPF/EPF rules) that shifts with policy and should be built only after validation with the RIA partner and real MFD users. See the companion feature roadmap for where this lands in the build sequence.

---

## 1. Stack (unchanged from original blueprint)
- Frontend: pre-compiled React SPA, Tailwind CSS, shadcn/ui components.
- Backend: PHP (no framework — see 3.4 for the one exception), PDO for all database access.
- Database: MySQL, hosted alongside the app on Hostinger.
- Hosting: Hostinger Premium Web Hosting. SSH access is available (restricted to home directory) — use it for deployment and cron setup, not just FTP.

## 2. Hosting constraints to design around **[REVISED — verified against Hostinger's current published limits]**
- Default PHP `max_execution_time` is 30 seconds, adjustable via `.htaccess`/PHP config up to the plan's ceiling — not unlimited. **Any endpoint doing real computation (forecasting, simulation) must be designed to finish well under this, or must be moved to a background job (see 3.5).**
- Premium-tier plans have a fixed PHP worker/process ceiling. Do not assume unlimited concurrency; avoid designs that spawn long-running synchronous requests under load.
- Confirm current CPU-second, worker, and connection limits directly in hPanel before finalizing any performance-sensitive design — these get revised by the host over time and should not be hardcoded into this document from memory.

## 3. Backend architecture rules

### 3.1 Tenant isolation — mandatory pattern, no exceptions **[REVISED]**
Every table holding tenant-scoped data carries `tenant_id`. This is correct and stays as designed. **What's added:** no PHP file may query a tenant-scoped table directly with a hand-written `WHERE tenant_id = :tenant_id` clause. Instead:

- Build one shared PHP data-access helper (e.g. `TenantScopedDb.php`) that wraps `select`, `insert`, `update`, `delete` operations and *always* injects the tenant_id from the verified session — the calling code cannot omit it, because the helper doesn't expose a path that skips it.
- All new endpoints route data access through this helper. This closes the "one forgotten WHERE clause = cross-tenant leak" risk before endpoint count grows past a handful of files.

### 3.2 Auth — additions to the original gatekeeper pattern **[REVISED]**
Keep `security_gatekeeper.php`'s core shape (resolve token → session → role check), but:
- Fix the confirmed bug: any endpoint reading a JSON body must use `json_decode(file_get_contents("php://input"), true)`, not `json_encode`.
- Decide and document explicitly where the auth token lives client-side. Default to an httpOnly, secure, sameSite cookie rather than `localStorage`, to avoid XSS-based token theft.
- Add rate limiting on the login endpoint (a simple `failed_attempts` + `locked_until` column on `users`, or a login-attempts table keyed by IP + email, is sufficient for MVP — doesn't need a separate service).
- Add multi-factor authentication before this handles real client accounts, even a simple TOTP/email-code flow for MVP. Do not launch to real users without it, given the data sensitivity.
- Use `password_hash()` / `password_verify()` explicitly — call it "hashing," not "encryption," in all documentation and code comments.

### 3.3 Audit logging — new requirement **[REVISED]**
Add a `change_log` table: `(id, tenant_id, entity_type, entity_id, field_changed, old_value, new_value, changed_by_user_id, changed_at)`. Write to it on every mutation to `base_plans` and `sub_scenarios`, particularly cascade updates from the Global Inheritance Engine — this is the only record of what assumptions a client was shown at a given time, and is expected functionality for a product giving financial guidance, not an optional extra.

### 3.4 Endpoint structure
The original blueprint's one-file-per-endpoint pattern (`update_timeline.php` etc.) is fine for MVP scale, provided every endpoint routes through the shared `security_gatekeeper.php` and `TenantScopedDb.php` helpers from day one. Do not let endpoints re-implement tenant checks inline once the helper exists.

### 3.5 Heavy computation **[NEW]**
Any forecasting/simulation logic that risks running past a few seconds must not run synchronously inside the web request. Use a simple `jobs` table (`id, tenant_id, status, payload, result, created_at, completed_at`) plus a PHP script triggered by Hostinger's cron scheduler to process pending jobs. The frontend polls for job completion. Do not attempt to run Monte-Carlo-style simulation synchronously against a 30-second request ceiling.

### 3.6 Advisory-mode gating **[NEW]**
`tenants.advisory_mode` is an enum: `'distribution'` (default) or `'advisory'`. This field is **not** editable by the tenant themselves through any self-service UI — only a Super Admin can flip it, and only after off-platform confirmation that the RIA partner has reviewed that tenant. Enforce this at the API layer: reject any request to modify `advisory_mode` that doesn't come from a super_admin session, regardless of what the frontend shows.

Frontend consequence: any component that renders client-facing plan output must read the tenant's `advisory_mode` and switch copy accordingly — e.g. "This is an illustration to help you think through your goals, not personalized investment advice" for distribution mode, vs. advisory language for advisory mode. This is a compliance-relevant string, not a cosmetic one — treat changes to it with the same care as changes to the auth logic.

## 4. Database schema
Use the original blueprint's `tenants`, `users`, `base_plans`, `sub_scenarios` tables as the starting schema, plus:
- `change_log` (see 3.3)
- `jobs` (see 3.5, only if/when heavy computation is added)
- `login_attempts` or equivalent (see 3.2)
- `tenants.advisory_mode` column (see 3.6) — add to the `tenants` table itself, not a separate table.

### 4.1 Multi-goal fields on `base_plans` **[NEW]**
Multi-goal planning is in the MVP (see the feature roadmap, Phase 1) — a client plans for retirement, education, a home purchase, etc. simultaneously, not one goal at a time. The schema already supports multiple `base_plans` rows per `client_id` with no changes needed there. Add these columns to `base_plans`:
- `goal_type` ENUM('retirement','education','home_purchase','other')
- `goal_label` VARCHAR(255) — advisor-set display name, e.g. "Priya's Education Fund"
- `target_amount` DECIMAL(15,2) NULL — optional; not every goal is modeled around a fixed target
- `target_date` DATE NULL

**Design decision:** each goal has its own independent starting corpus (`initial_net_worth`, unchanged) rather than drawing from one shared, allocated client-level portfolio. Cross-goal allocation is a real feature but a meaningfully more complex one (an optimization/rebalancing model, not a schema addition) — it's deferred to Phase 2+, scoped from actual advisor feedback rather than built speculatively now.

### 4.2 Withdrawal rate / corpus multiple **[NEW]**
Retirement-type goals need the standard safe-withdrawal-rate mechanic: a withdrawal rate slider with a live-computed "corpus multiple" (annual expenses × 1/rate) shown alongside it. This is foundational retirement math, not the deferred Section 7 tax-optimized withdrawal *sequencing* engine — keep those two conceptually and architecturally separate; this one is in the MVP.

- `base_plans.withdrawal_rate` DECIMAL(4,2) NULL — parent-level default, same role as `inflation_rate`. Only meaningful when `goal_type = 'retirement'`.
- `sub_scenarios.custom_withdrawal_rate` DECIMAL(4,2) NULL — override field. Participates in the *existing* `is_overridden` flag alongside `custom_inflation` — do not add a second override flag for MVP. This means customizing either field freezes the whole scenario from inheriting cascade updates on both; document this behavior in the UI copy so it isn't a surprise, and revisit only if real advisor usage shows a need for independent per-field overrides.
- Corpus multiple (1 ÷ withdrawal rate) is a **computed display value**, never stored.
- **Do not default the slider to the US 4%/25x convention.** Default to 3.5%, range roughly 2.5%–4%, reflecting the Indian-market research (structurally higher inflation, ~6-7% vs. 2-3% in the US, pushes the recommended rate down from the US figure). Label this explicitly in the UI as a starting point to adjust per client, not a fixed rule — the whole point of the slider is that the "25x" heuristic is a vague anchor, not a personalized number.
- This is the single most advice-adjacent control in the MVP. The distribution-mode disclosure copy (Section 3.6) must render directly adjacent to this specific control, not just generically elsewhere on the page.

### 4.3 Decumulation projection & sequence-of-returns stress test **[NEW — pulled into MVP]**
This is the actual differentiator per the feature roadmap's Phase 1 thesis, not a Phase 2 nice-to-have. A competitive scan of four Indian MFD platforms (JezzMoney, Investwell Mint, theMFBox, IFA-Planet — see `05_HorizonPlan_Practitioner_Validation_Review.md`) found none of them show a forward-looking, year-by-year withdrawal survivability chart. Ship this in Phase 1.

- `base_plans.drawdown_return_rate` DECIMAL(4,2) NULL — post-retirement expected return assumption. Only meaningful for `goal_type = 'retirement'`. **Do not reuse this field for a future accumulation-phase rate** — Phase 2 will add a separate `accumulation_return_rate`; keep them distinct from the start, since conflating pre- and post-retirement return assumptions is a specifically documented common planning error (Section 4.2's sibling finding, validation review item 1).
- `sub_scenarios.custom_drawdown_return_rate` DECIMAL(4,2) NULL — override, participating in the same `is_overridden` flag as `custom_inflation` and `custom_withdrawal_rate`. Still no second override flag.
- `base_plans.projection_horizon_years` INT NOT NULL DEFAULT 30 — how many years the projection runs. 30 matches the standard convention from the original Trinity Study research; advisors can extend it (e.g. for early/FIRE retirees planning a longer post-retirement horizon).
- **Computation, not storage:** the year-by-year corpus balance is computed on read, never stored. For each year `n` from 1 to `projection_horizon_years`: `balance[n] = balance[n-1] * (1 + drawdown_return_rate) - (annual_withdrawal * (1 + inflation_rate)^n)`, where `annual_withdrawal = initial_net_worth * withdrawal_rate`. This is simple iterative arithmetic — no matrix operations, no external libraries, comfortably inside Hostinger's execution-time budget from Section 2. Do not route this through the Section 3.5 background-job pattern; that's reserved for genuinely heavy computation, which this is not.
- **Sequence-of-returns stress test:** a second computed series using the same average return (same geometric mean as `drawdown_return_rate`) but reordered — below-average returns in the early years, above-average later, so the two lines share a CAGR but diverge in outcome. This is a fixed, simple, illustrative reordering (e.g., swap the first third and last third of a synthetic return sequence), not a historical-data backtest or Monte Carlo draw — keep it deterministic and explainable to a client in one sentence. Full probabilistic stress testing stays in Phase 3.
- Frontend: render both series on one chart (steady vs. adverse-sequence), for retirement-type goals, alongside the withdrawal-rate slider and corpus-multiple readout from Section 4.2. This combined view is what actually gets shown in a client meeting — the static ratio alone is not the differentiator, the trajectory is.

## 5. Frontend
Original blueprint stands: React + Tailwind + shadcn/ui, debounced slider updates on drag-release. No changes.

## 6. What NOT to do
- Do not commit `db_config.php` or any file containing real database credentials to the git repository. Use a `.gitignore`'d config file or environment-based config loaded outside the web root.
- Do not build the Section 7 distribution/withdrawal-sequencing engine as part of the MVP. It's a Phase 3+ item per the feature roadmap, gated on validation with the RIA partner and real MFD users.
- Do not let a tenant self-serve into advisory mode. That switch is a Super Admin action only, per 3.6.
- Do not deviate from the tenant-scoping helper pattern once it exists, even for "quick" endpoints.
- Do not build WhatsApp sharing, BSE/NSE transaction integration, or multi-goal planning into the MVP — all are logged, real, and deferred. See `04_HorizonPlan_Feature_Roadmap.md` for where each lands.
