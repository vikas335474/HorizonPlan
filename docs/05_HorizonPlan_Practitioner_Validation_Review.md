# HorizonPlan — Practitioner & Community Validation Review

## Methodology — read this before the findings
This is **not** a commissioned panel review by named financial planners. It's a secondary-research pass: pulling what respected, publicly-writing Indian financial planning voices (freefincal / M. Pattabiraman, Arthgyaan, Deepesh Raghaw's columns, HDFC MF's own published guidance) have said about where retirement planning commonly goes wrong, plus real forum-sourced questions from individuals actually doing this math for themselves (Reddit-adjacent and professional-network forums). Then cross-checking that against `04_HorizonPlan_Feature_Roadmap.md`. Treat this as a credible signal worth acting on, not a substitute for actually putting the product in front of your RIA partner and real MFDs, which remains the real validation gate per the roadmap.

---

## Confirmed correct — no change needed
**3.5% default withdrawal rate (not the US 4%/25x convention).** Directly matches published Indian planner guidance: a more conservative 3-3.5% withdrawal rate, corresponding to a ~28-33x corpus multiple, is the commonly cited figure for Indian retirees given structurally higher inflation than the US.

---

## Gaps found, and what to do about them

### 1. Single return rate across accumulation and drawdown phases — fix before building Phase 2's return-rate field
Published guidance calls this out by name as one of the most common errors in retirement planning: using one assumed return rate for the years you're saving and the years you're withdrawing, when they should be modeled separately (drawdown-phase allocations are typically more conservative). **Action:** when Phase 2 builds the "expected return rate" multi-variable slider, split it into two fields — `accumulation_return_rate` and `drawdown_return_rate` — from the start. Don't ship a single rate and retrofit this later.

### 2. Sequence-of-returns risk — new Phase 2 item
This is not a theoretical edge case in the current Indian market — it's an actively cited, named problem. Recent commentary points to the April 2025 market downturn as a concrete case where retirees who kept equity-heavy SIPs running through withdrawal saw their corpus deplete faster than the same average return would suggest, purely because of *when* the bad years landed relative to their withdrawal timeline; SEBI has flagged aggressive-fund mis-selling to retirees as a related pattern. Two people with identical CAGR over 30 years can end up with meaningfully different final corpus purely from the order returns arrived in.

The current model (a static corpus ÷ withdrawal-rate ratio) can't represent this — it needs a year-by-year trajectory, not a single number. **Action, scoped deliberately small:** Phase 2 adds a deterministic year-by-year corpus projection (starting corpus, annual withdrawal adjusted for inflation, annual growth at the assumed rate) shown as a line chart for retirement-type goals. Phase 3's Monte Carlo/background-job infrastructure (already planned) is the natural home for a full probability-of-success sequence-risk stress test once the simpler deterministic version is validated — don't jump straight to Monte Carlo before the basic chart exists and is used.

### 3. Corpus composition (liquid vs. locked) — new Phase 2/3 item
Real Indian retirement planning treats EPF, NPS, and PPF as distinct buckets because they have different lock-in and liquidity characteristics (NPS is largely annuitized and illiquid past a point; EPF becomes accessible on leaving employment) — not as one undifferentiated pool of money. Our schema currently has a single `initial_net_worth` figure per goal. **Action:** log as a Phase 2/3 candidate — break `initial_net_worth` into a small set of named buckets (e.g. liquid/market-linked vs. locked/annuitized) only once Phase 1 usage confirms advisors are hitting this as real friction, per the roadmap's existing validation-gate discipline. Don't build it speculatively now.

### 4. Healthcare-specific inflation — already solved by an earlier decision, needs only UI guidance
Healthcare cost inflation is repeatedly cited as running well above general inflation (8-14% vs. ~6%) and as the most commonly underestimated line item, especially for early retirees. Because multi-goal support is already in the Phase 1 MVP, an advisor can already model this today — create a "Healthcare Reserve" goal (using the existing `goal_type: 'other'` or a dedicated category) with its own higher `inflation_rate`, independent of the main retirement goal's assumption. **Action:** no schema change. Add this as an explicit tip in advisor-facing onboarding/help copy so advisors actually think to do it, rather than leaving it as an implicit capability nobody discovers.

### 5. Accumulation-phase contribution modeling (how much to save monthly) — confirms an existing Phase 2 item, not new
Forum-sourced questions and practitioner tools consistently start from "how much do I need to save monthly to hit this number," not just "will my existing corpus last." This is already the Phase 2 "SIP contribution amount" multi-variable item — this research doubly confirms it's real demand, not a guess. No scope change; noting the confirmation for the record.

---

## What's deliberately still not changing
Expense-category-level budgeting (housing, education, staff, travel as separate tracked line items) showed up in forum-sourced examples, but building full category-level budgeting is a different, larger feature than what HorizonPlan is scoped to be — the multi-goal architecture already lets an advisor approximate this by splitting goals (e.g. "Healthcare Reserve" as its own goal, per item 4). Not pulling this into any phase yet; if Phase 1/2 usage shows advisors specifically asking for category-level budgeting rather than just splitting goals, revisit then.

---

## Competitive feature scan: JezzMoney, Investwell Mint, theMFBox, IFA-Planet

Direct comparison against four named India MFD platforms, to check what's genuinely worth adopting versus what's just back-office feature parity that isn't the point of this product.

| Platform | What it actually does well | Verdict for HorizonPlan |
|---|---|---|
| **Investwell Mint** | Most substantial of the four (established 2000, 4,700+ advisors, ₹6L cr AUM). Risk profiler (evaluate/document client risk levels). SIP Step-Up calculator (annually increasing contribution, not flat). Model Portfolio Comparison (current vs. ideal portfolio, side by side). | Adopt: risk profiler concept (new Phase 2 item, below) and step-up SIP modeling (folded into the existing Phase 2 SIP item). |
| **theMFBox / AdvisorKhoj toolset** | Names a dedicated "Retirement Organizer" and "Composite Goal Planner" as separate modules, with charts. | Validates, doesn't change anything: confirms retirement-as-specialized-goal-type-with-charts is the right call, which is already the design. |
| **IFA-Planet** | Deliberately simple. Its one distinguishing idea is family/household account grouping (merging goals/accounts across family members under one view). | Log as a Phase 3+ idea. Not now — real added complexity to the client/tenant model, not validated as needed yet. |
| **JezzMoney** | Mostly marketing-forward (WhatsApp/AI branding language). Concrete part is breadth — "50+ calculations" for goal-based planning. | Nothing specific to adopt beyond what's already planned (WhatsApp sharing is already a logged Phase 2 item, for its own validated reason, not because JezzMoney has it). |

**The important negative finding:** none of the four — despite all having "goal planning" and return/growth calculators — appear to model sequence-of-returns risk or show a year-by-year withdrawal trajectory. Their return-analysis tools (Rolling/Trailing Return Analysis, Growth Calculator) look backward at historical fund performance, not forward at whether a specific withdrawal plan survives a bad early sequence. This is genuine open ground, not something being built defensively against existing competition — see the Phase 1 scope change below.

**Deliberately not importing from any of the four:** eKYC/onboarding automation, BSE/NSE transaction execution, commission/capital-gains reconciliation, CRM/lead-gen, WhatsApp-as-marketing-channel. These are each platform's actual core value, built over 10-20 years of back-office maturity. Competing there directly isn't the plan — see the feature roadmap's original differentiation thesis, unchanged by this scan.

**New Phase 2 item from this scan:** a risk profiler — a short structured questionnaire producing a risk tolerance score, informing (not dictating) the return-rate assumptions used elsewhere. Beyond matching a real competitor feature, this has compliance value given SEBI's active scrutiny of aggressive-fund mis-selling to retirees without risk assessment (see item 2 above) — having a documented risk profile on file is a real suitability safeguard, not just a feature checkbox.
