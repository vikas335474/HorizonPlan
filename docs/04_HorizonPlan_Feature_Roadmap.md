# HorizonPlan — Feature Roadmap: MVP to Final Product

Companion to `02_HorizonPlan_Project_Instructions.md`. This document answers a different question than the architecture review and build prompts — not "how do we build it correctly," but "what belongs in the product at each stage, and why." Cross-checked against real Indian financial-planning practitioner guidance and forum-sourced user pain points in `05_HorizonPlan_Practitioner_Validation_Review.md` — several items below are directly traceable to specific findings there.

Each phase has a **validation gate**: a condition that should be true before starting the next phase. These are deliberately not calendar dates. Building Phase 3 features before the Phase 2 gate is met means building on unvalidated assumptions — which is exactly the mistake being avoided by deferring the withdrawal/distribution engine in the first place.

---

## Phase 1 — MVP: prove the one differentiator, on real goal structures

**Thesis:** nothing in the existing Indian MFD software market (Wealth Elite, NJ Fundz Network, IFA-Planet, JezzMoney, Investwell Mint, theMFBox) offers an interactive, protected what-if scenario model that shows *sequence-of-returns risk during withdrawal* — a competitive scan against four of these platforms (see `05_HorizonPlan_Practitioner_Validation_Review.md`) confirms none of them do this; their return tools look backward at fund performance, not forward at withdrawal survivability. **This is the real differentiator, and it's in the MVP now, not deferred** — shipping only the static corpus-multiple ratio without the trajectory behind it would make this indistinguishable from any of the four competitor platforms' existing calculators.

**Scope discipline for how this gets pulled in:** the *decumulation* trajectory (does a given retirement corpus survive the withdrawal years, and how does a bad early sequence change that) is in Phase 1. The *accumulation* side (years of saving before retirement, SIP step-up, pre-retirement growth) stays in Phase 2 — it's genuinely table-stakes (every competitor above has SIP calculators), not the differentiator. Splitting it this way keeps the MVP shippable while pulling forward the part that's actually novel.

**Multi-goal is also in the MVP, not deferred.** A planner that only handles one goal per client isn't usable in a real advisor-client conversation — clients plan for retirement, a child's education, a home purchase, etc. simultaneously. This was originally scoped to Phase 2; moved to Phase 1 because it's not an enhancement, it's a baseline requirement for the tool to be usable at all.

**Design decision for Phase 1: goals are independent, not a shared allocated portfolio.** Each goal (each `base_plan` row) has its own starting corpus, set independently by the advisor — "Retirement" and "Priya's Education" are two separate records, each with its own initial amount and inflation assumption. This is a deliberate simplification: a model where a client's *total* net worth gets allocated and rebalanced across competing goals (the approach mature tools like MoneyGuidePro use) is real, but it's an allocation/optimization engine on top of what's here, not a schema tweak — it belongs in Phase 2+, scoped from actual advisor feedback on Phase 1's simpler model.

**In scope:**
- Auth & tenant isolation: Super Admin / Advisor / Client tiers, `tenant_id` scoping via the shared data-access helper, MFA, session rate-limiting.
- Tenant onboarding (admin-created, not self-serve signup — keeps the advisory-mode gate meaningful from day one).
- White-label branding (`white_label_settings` — logo, primary color). Low build cost, and it's a visible differentiator in advisor demos, which matters for a reseller SaaS sales motion.
- **Goal creation (was "Base Plan creation"):** goal type/label (retirement, education, home purchase, other + free-text label), optional target amount and target date, initial corpus, baseline inflation rate. A client can have multiple goals.
- A goals list view per client — the client/advisor lands here first, then drills into a specific goal's timeline.
- Sub-scenario creation with the Live Timeline Slider adjusting a specific goal's baseline assumptions.
- **Withdrawal rate slider with live corpus-multiple display, for retirement-type goals** (Section 4.2 of the instructions). This is the concrete version of the "25x annual expenses" rule of thumb — shown as an adjustable ratio rather than a fixed number, and calibrated to Indian safe-withdrawal-rate research (~3-3.5%) rather than the US 4%/25x convention.
- **Year-by-year decumulation projection + sequence-of-returns stress test** (Section 4.3 of the instructions) — the real differentiator, pulled forward from Phase 2. A deterministic year-by-year chart of the retirement corpus balance under withdrawal, plus a toggle between "steady return" and "adverse sequence" (same average CAGR, different ordering of returns across years) so an advisor can show a client concretely why sequence risk matters, not just describe it. Pure arithmetic, no Monte Carlo, no background job needed — well within Hostinger's execution budget.
- The Global Inheritance Engine: cascade updates, `is_overridden` protection, reset trigger — scoped per goal (per `base_plan_id`), unchanged from the original design.
- `change_log` audit trail on every goal/sub_scenario mutation.
- Distribution-mode disclosure copy baked into every client-facing view (Section 3.6 of the instructions) — this ships with the MVP, not after.
- Onboarding/help copy that explicitly suggests advisors split out high-inflation categories (healthcare being the most commonly underestimated one) as their own goal with its own inflation assumption, rather than burying them inside a single retirement goal's number. The multi-goal architecture already supports this — it just needs to be surfaced so advisors actually discover it (see `05_HorizonPlan_Practitioner_Validation_Review.md`, item 4).
- A basic shareable/printable view of a single goal's scenario, now including the projection chart — this is what actually gets shown in a client meeting.

**Explicitly out of scope:**
- Accumulation-phase modeling: years-to-retirement, SIP contributions, SIP step-up, pre-retirement return rate. Real and validated (every named competitor has SIP calculators), but table-stakes rather than differentiating — Phase 2.
- Full Monte Carlo / probabilistic stress testing (as opposed to the deterministic two-scenario comparison above) — Phase 3, behind the background-job infrastructure, once the simpler version is validated.
- Risk profiler (new item from the competitive scan) — Phase 2.
- Cross-goal portfolio allocation (shared corpus split across goals, rebalancing) — see the design decision above.
- Advisory-mode tenants (RIA partner onboarding can happen in parallel, but the mode-switch and advisory-language UI can wait until Phase 2 unless the RIA partner is actively using the product from day one — if they are, pull 3.6 forward).
- The Section 7 distribution/withdrawal *sequencing* engine (tax-optimized account-order withdrawal — distinct from the sequence-of-returns risk chart above, which is about market timing, not tax optimization).
- WhatsApp sharing.
- BSE/NSE transaction execution, KYC automation, portfolio/AUM tracking, family/household grouping — these are the incumbents' core features; competing on them directly in an MVP dilutes the actual differentiator.
- Mobile app.

**Validation gate to exit Phase 1:** a handful of real MFD/IFA tenants (including any early tenants your RIA partner brings) are actively using multi-goal planning and the withdrawal/sequence-risk projection with real clients, and you have direct feedback on (a) whether the sequence-risk chart actually changes client conversations the way it's meant to, (b) which additional variables clients want to stress-test, and (c) whether advisors are hitting real friction from goals being independent rather than a shared allocated portfolio. Build Phase 2 around answers you actually have, not around this document's guesses.

---

## Phase 2 — Broaden the core engine, open the advisory tier

**Thesis:** once the core differentiator (decumulation sequence-risk projection, shipped in Phase 1) is validated, the two highest-value additions are (a) building out the accumulation side so the tool covers a client's full journey, not just the withdrawal phase, and (b) actually turning on the advisory-mode tier now that there's a live product to review tenants against.

**Candidate scope (confirm against Phase 1 feedback before building):**
- Accumulation-phase modeling: `accumulation_return_rate` (distinct from Phase 1's `drawdown_return_rate` — never merge these back into one field, see validation review item 1), years-to-retirement, and SIP contribution amount with an optional annual step-up percentage (matching standard Indian planning practice and Investwell Mint's step-up calculator, per the competitive scan). This extends the Phase 1 projection chart backward in time — same chart, now showing the saving years leading up to retirement, not just the withdrawal years.
- Risk profiler: a short structured questionnaire producing a risk-tolerance score that informs (not dictates) the return-rate assumptions elsewhere in the tool. New item from the competitive scan (Investwell Mint has this); also has real compliance value given SEBI's active scrutiny of aggressive-fund mis-selling to retirees without risk assessment.
- Retirement age and general multi-variable scenario support beyond the fields above, each independently override-able per sub-scenario, extending the same cascade/override pattern rather than a new engine.
- Advisory-mode activation flow for the RIA partner's tenants (Section 3.6), with advisory-language client output.
- Cross-goal portfolio allocation — if Phase 1 feedback shows advisors hitting real friction from goals being independently-funded rather than a shared, allocated corpus, this is where that gets built. Treat it as a genuine allocation/optimization feature, not a schema patch.
- Corpus composition (liquid vs. locked/annuitized assets, e.g. NPS vs. mutual funds) — only if Phase 1 usage shows this as real friction, per validation review item 3. Don't build speculatively.
- WhatsApp report sharing — logged as a real, validated engagement pattern in this market (every major competitor lists it), not a nice-to-have guess. Build once the core product is stable, not before.

**Still out of scope:** the tax-optimized withdrawal *sequencing* engine (which account to draw from first, LTCG/STCG optimization — distinct from both the withdrawal-rate slider and the sequence-of-returns risk chart already in Phase 1, which are about market timing and corpus survivability, not tax optimization), transaction execution, KYC/onboarding automation.

**Validation gate to exit Phase 2:** the advisory-mode tier has at least one real RIA-reviewed tenant using it in front of real clients, and there's a specific, RIA-partner-endorsed request for what the withdrawal/distribution engine should actually calculate for the Indian market — not a generic assumption carried over from the original blueprint's US framing.

---

## Phase 3 — Scale & retention features

**Thesis:** at this point HorizonPlan has a validated core and a live advisory tier. This phase closes the gap to what makes advisors switch their daily-use tools over, and is where the deferred distribution engine finally gets built — with real requirements instead of guessed ones.

**Candidate scope:**
- The India-specific withdrawal *sequencing* engine (which account/fund category to draw from first, tax-optimized ordering) — scoped from actual Phase 2 validation output, and restricted to advisory-mode tenants only (this is the feature most likely to read as "advice," so it belongs behind the fiduciary-reviewed tier, not the distribution-mode default). The basic withdrawal-rate-to-corpus-multiple mechanic already shipped in Phase 1; this phase is specifically about sequencing and tax optimization across multiple holdings.
- Advisor-facing analytics dashboard (client engagement, plan activity) — competitive table stakes against REDVision/NJ Fundz, worth having once there's a retention motive to build it for.
- Multi-advisor firm hierarchies, if any tenant firm has asked for sub-advisor team structures under one tenant.
- Background job infrastructure (Section 3.5), if computation complexity from the Phase 2 multi-variable engine has actually started approaching Hostinger's execution-time ceiling — check real usage data before building this, don't assume.

**Validation gate to exit Phase 3:** the product is handling enough real tenant volume that infrastructure decisions (hosting tier, background jobs, potential migration off shared hosting) are being driven by actual load data, not projections.

---

## Phase 4 — Final product: expansion

**Thesis:** this phase is speculative by design — it's not a commitment, it's where the roadmap could go if the earlier phases validate a real, growing business. Don't plan implementation detail here; just keep the door open.

**Candidate directions, in rough order of how directly they extend the validated core:**
- BSE Star MF / NSE NMF integration for actual transaction execution — a significant scope jump from a planning tool to a transactional platform, and a decision that changes the regulatory surface (transaction execution has its own compliance requirements beyond advice/distribution). Only pursue if tenant demand is explicit and repeated.
- Multi-language support — India's MFD base extends well into tier 2/3 cities where English-only tooling is a real adoption barrier; several competitors already address this.
- Mobile app / PWA for advisors and clients.
- Expansion beyond MFD/RIA tenants to adjacent reseller types (e.g. insurance distributors), if the underlying multi-tenant reseller architecture proves out and there's a specific reason to believe the same core product fits.

---

## How to use this document
When starting work on any phase, check the validation gate for the *previous* phase first. If it isn't met, that's a signal to keep gathering real usage feedback rather than build the next phase's candidate scope from this document's assumptions — everything past Phase 1 here is a reasoned guess about where demand will point, not a confirmed spec.
