# 14 — HorizonPlan: Professional Review (CFA + CA lenses) and the honest gap list

> **Purpose.** A rigorous, professional-lens review of the whole product — engine,
> individual tier, advisor tier, firm tier — written from what a CFA-trained
> planner and a practising Indian CA would actually flag, together with what real
> individual users, advisors and firm principals consistently pay for and complain
> about. Not a marketing check. Every finding is anchored to a file:line or a
> named endpoint/behavior. Every finding has an **outcome**: fixed in this
> session, added to the ready-to-run backlog, or recorded as a deliberate
> decision (with the reasoning that stands behind it).
>
> **Read this alongside** `docs/10` (competitive framing + P0/P1/P2/P3 backlog),
> `docs/13` (individual flow — the closest predecessor to this file), `docs/07`
> (the "meeting / follow-through / record" thesis), and `docs/05` (practitioner
> validation of the India-specific choices, e.g. the 3–3.5% withdrawal baseline).
> Where a finding here duplicates one already in those files, this file cites
> them and adds nothing new — that is the point, not a loose end.

---

## 1. Executive summary

HorizonPlan is substantially more mature than the "goal calculator" modules
inside the incumbent Indian MFD platforms. The methodology (PlanMath.php), the
guardrails (facts-not-instructions, "never invent a figure"), and the docs
tradition (`docs/02` through `docs/13`, with decisions on the record) all read
as a product that has already been through several honest professional reviews
of its own. This review found **no methodological errors** in the engine, **no
tax-context claims that are wrong** where the app makes them, and **no security
or tenant-isolation regression** from the recent landing-page changes (see
§8 for the audit trail).

What it did find:

1. **One real bug** — a CSS token (`--color-amber-ink`) referenced in two
   personal-dashboard components but never defined, so a decreasing readiness
   delta silently rendered in the parent ink colour instead of the intended
   amber. **Fixed in this session.**
2. **Three cosmetic / a11y fixes** landed from the code-review skill: white
   text on coral CTAs (below WCAG 4.5:1) → switched to ink text; three
   inline coral drop-shadow strings → new `--shadow-coral` /
   `--shadow-coral-strong` tokens; mobile nav missing the coral "For
   individuals" dot the desktop nav has → added.
3. **Four planning-methodology recommendations** a CFA would flag (§3) —
   all real, all valuable, none blocking. Three are added to the backlog with
   ready-to-run prompts in §7; one (Monte Carlo / probability framing) is
   recorded as **deliberately not-doing**, with the reasoning docs/13 §8
   already carries.
4. **Six tax-context recommendations** a CA would flag (§4) — three are
   already on the docs/10 backlog (tax reporting P2-2, RTA feed P2-1, billing
   P2-4 as pre-requisite for premium tiers). Two are worth adding to the
   backlog with fresh prompts (annual tax-year summary, 80D premium capture).
   One (old vs new regime modelling) is deliberately deferred per docs/13
   §8's already-recorded "no tax-optimisation engine" decision.
5. **Seven UX / workflow gaps** across the individual, advisor and firm
   surfaces (§5, §6). Each is scoped as `S/M/L`, cited to file:line, and
   assigned an outcome. None is a blocker; several are cheap wins with real
   value the current backlog does not name explicitly.
6. **Product framing** is honest to a fault. The one place the framing could
   be sharper is the "we intentionally don't name funds" copy — a first-time
   individual user reading "you're short ₹8,400/month, invest more" reasonably
   asks "in what?" and the answer today is only implicit. Added to the
   backlog as a small, contained copy pass rather than a feature build.

The rest of this file is the evidence.

---

## 2. Methodology — how this review was conducted

1. **Docs baseline.** Read `docs/07`, `docs/10`, `docs/11`, `docs/13`,
   `docs/02` §4 in full, then CLAUDE.md's capability index, so no finding here
   re-flags a deliberate decision already on the record.
2. **Engine read.** `api/lib/PlanMath.php` in full — the file is
   963 lines and every public method carries a docstring stating its formula,
   its assumption, and (where relevant) what it deliberately does NOT do.
3. **Product surfaces.** Read the individual standing block
   (`PersonalPlanSummary.jsx`), the retirement target card
   (`RetirementTargetCard.jsx`), the foundations engine
   (`api/lib/FinancialFoundations.php`), and the firm analytics endpoint
   (`api/firm_analytics.php`). Sampled the advisor dashboard, practice
   analytics UI, and the goal-detail page for depth.
4. **Skill-based passes.**
   - `code-review` skill at **max** effort — surfaced the four cosmetic /
     a11y items above (all valid).
   - `security-review` skill — no findings on the recent changes; router
     change (unauth `/` → Landing) does not cross an auth boundary; landing
     page has no user input, no fetches, no unsafe rendering paths.
5. **Cross-check.** Every finding here was checked against docs/10's
   competitive backlog and docs/13's §8 "decisions on record" list before
   being included — a finding that duplicates an already-decided position is
   surfaced as **already decided** with a pointer, not as a new gap.

---

## 3. CFA lens — investment / planning methodology

### C-1 — Longevity horizon guidance is missing on the input · **Minor · Backlog**

**Where.** `base_plans.projection_horizon_years` is user-set with no
guidance on the input. CFA planning convention runs plans to
**age 90–95** (25–30 years post-retirement at a 60/65 retire age), often
tied to a mortality curve.

**Consequence.** A person picking "20 years" because it feels round is
implicitly modelling to age 80 — an under-estimate for anyone whose
lifestyle and healthcare access suggests otherwise. The steady/adverse
survival math above is correct; the horizon it runs over may be too short.

**Fix path (backlog · S).** A helper line on the horizon input on
`GoalDetail.jsx`: "*Common practice is to plan to age 90–95 — that's 25–30
years past a 60/65 retirement.*" No arithmetic change, no schema change.
Prompt in §7.

### C-2 — Return assumption's tax treatment is undisclosed · **Major · Backlog**

**Where.** `base_plans.accumulation_return_rate` and `drawdown_return_rate`
are single nominal figures. Neither the input UI nor the projection output
states whether the number is **pre-tax or post-tax**. `PlanMath.php` treats
returns as flat nominal.

**Consequence.** For an equity-heavy Indian retirement corpus, LTCG on
equity above ₹1L/year is 12.5% (post-July-2024). A "12% return" is
materially different to hold up against the withdrawal series depending on
how the corpus is redeemed. The chart is not wrong under the assumption
"gross return, tax paid from outside the corpus" — but the assumption is
not stated. A CFA would insist it is.

**Fix path (backlog · S).** A single disclosure line on the return-rate
input and on `RetirementTargetCard.jsx`: "*Return figures are gross
(pre-tax). Tax on redemptions reduces what actually reaches your account
— see the per-holding tax context on your portfolio.*" No arithmetic
change. Prompt in §7.

### C-3 — Withdrawal has no dynamic-guardrail option (Guyton-Klinger) · **Minor · Deferred**

**Where.** `steadyReturnSeries()` withdraws
`annualWithdrawal × (1 + inflation)^n` regardless of portfolio state.
`adverseSequenceSeries()` and the two-bucket variants do the same.

**Consequence.** Real planners commonly model **variable-withdrawal
guardrails** — cap or reduce the inflation adjustment in a year where the
portfolio has fallen materially, to preserve principal. HorizonPlan does
not model this. Not wrong; a real limitation vs. best-in-class planners
(Boldin's variable-withdrawal, ProjectionLab's guardrails).

**Decision.** **Not adding to the immediate backlog.** The current
deterministic "steady vs adverse at fixed spread" pair is deliberately
simple and explainable — introducing conditional withdrawal rules
introduces a modelling knob the copy would have to explain, and this
product's competitive posture is *"transparent arithmetic, never a
hardcoded regulatory authority"* (PlanMath.php class docstring). Revisit
if the demo group of advisors specifically asks for it.

### C-4 — Adverse-sequence spread (±4 pp) is hardcoded and only documented in code · **Minor · Backlog**

**Where.** `adverseReturnSequence()` at `PlanMath.php:947` uses a fixed
`$spreadPoints = 4.0`. The class docstring calls it "explicitly a simple
illustrative reordering per the spec, not a backtest or Monte Carlo draw"
— which is correct, but the *user* never sees this. The adverse chart on
`GoalDetail.jsx` shows an amber dashed line with no explanation of what
"adverse" actually models.

**Consequence.** A CFA reading the chart would want to know whether the
adverse case is (a) a real historical bad sequence, (b) a percentile-drawn
bad sequence, or (c) an illustrative spread. Today the answer is (c) but
you have to read PHP to find that out.

**Fix path (backlog · S).** A short caption under the sequence chart:
"*Adverse-sequence models the mean return with a ±4-point spread, sorted
so weak years land first — an illustration of sequence-of-returns risk,
not a backtest. See Historical Replay below for real market years.*"
Prompt in §7.

### C-5 — No glide-path / age-based asset shift · **Deferred**

**Where.** A goal holds a single `accumulation_return_rate` for the full
saving horizon. In practice, planners often glide equity share down as
retirement nears.

**Decision.** **Not adding to the immediate backlog.** The two-bucket
model (`liquid` vs `locked`) is the app's existing structural mechanism
for asset differentiation; the practitioner-friendly way to handle a
glide path in this shape is to shift the split as the client ages, which
is already possible through advisor-driven plan edits and the audit trail
captures it. A modelled glide path is a real feature, but it fights
docs/13 §8's "never invent a figure the person didn't state" rule unless
it is user-set — at which point it is what the two-bucket already is.

### C-6 — Monte Carlo probability framing · **Deliberately not doing**

Already on the record — `docs/13 §8`: "*No Monte Carlo. The deterministic
steady/adverse pair plus real historical replay is more explainable and
more honest than a probability cloud, and it matches the existing 'never
fabricate a figure' rule.*" This review agrees.

---

## 4. CA lens — Indian tax + compliance

### T-1 — No portfolio-wide annual tax summary · **Major · Backlog**

**Where.** `docs/12 D-2` shipped per-instrument tax context (holding
period, capital-gains treatment, illustrative unrealised gain per row).
There is no view that rolls those up into a **portfolio-wide
March-31 snapshot** — "here is what you owned, here is your unrealised
LTCG/STCG by regime, here is what would be filed if these were realised."

**Consequence.** A CA-ready client wants exactly this once a year at
filing time; today they can only get it by reading each row in turn.

**Fix path (backlog · M).** A printable "Tax posture as of <date>"
report on the client portfolio, computed from the existing per-holding
context. Extends what already exists rather than adding new tax logic.
Aligns with docs/10 P2-2 (tax reporting) but is smaller and does not
depend on P2-1 (live RTA feed). Prompt in §7.

### T-2 — Health-cover premium (80D) not tracked · **Minor · Backlog**

**Where.** `FinancialFoundations::healthCover()` records the cover
amount (`client_protection.health_cover`) but not the premium.

**Consequence.** 80D is a common Indian deduction (₹25K self /
₹50K parents / etc.). Capturing it would flow naturally into T-1 and
gives a CA one more year-end fact from the plan.

**Fix path (backlog · S).** Add nullable `health_premium_annual` to
`client_protection`, surface on `FoundationsUI.jsx`. Reference-only
display of the 80D headroom (illustration-framed, per house style).
Prompt in §7.

### T-3 — Old vs new regime modelling · **Deferred (already on record)**

Already decided in `docs/13 §8`: "*No tax-optimisation engine. docs/12
D-2 deliberately stopped at 'facts only' because there is no transaction
ledger. Old-vs-new-regime modelling would need one.*" This review
agrees.

### T-4 — Estate / nominee capture · **Minor · Backlog**

**Where.** Nothing in the schema captures the person's nominee or basic
estate posture (Will drafted yes/no, joint holdings, EPF nominee).

**Consequence.** Retirement planning without an estate posture is
half-done. A single check "have you named a nominee for your EPF/PPF/NPS,
and have you drafted a Will?" adds material planning value at almost no
build cost.

**Fix path (backlog · S).** A fifth check in `FinancialFoundations` for
"Estate basics recorded" — nominee-named, Will-drafted booleans. Never a
document, never a service — same planning-side-not-distribution rule as
insurance. Prompt in §7.

### T-5 — NRI / resident-status tax posture · **Deferred**

**Where.** No `resident_status` field anywhere. A person planning across
countries has completely different holding periods, DTAA implications,
and reporting duties (Schedule FA, remittance rules).

**Decision.** **Not adding to the immediate backlog.** Adding a resident
posture that then does nothing with it is worse than not asking. Real
NRI support means an NRI-specific tax context table, DTAA-aware
disclosure copy, and a decision about whether the individual tier serves
NRIs at all (regulatory implications). Record as a decide-then-build
open question.

### T-6 — HUF / joint holdings · **Deferred**

Same as T-5: a real Indian tax structure this product does not model.
Not lying about it is the honest position for now.

---

## 5. Individual UX gaps

### U-1 — "What to invest in?" is unanswered where a first-time planner asks it · **Minor · Backlog**

**Where.** `RetirementTargetCard.jsx:196` renders the levers ("*Closing
this takes ₹8,400 more per month — or retiring 3 years later.*"). The
first-time planner's next question is: "*More… in what?*" The disclosure
banner separately says HorizonPlan does not recommend products, but the
banner is elsewhere.

**Consequence.** The person's silence-answer becomes "so this app is
broken" instead of "so this app deliberately doesn't answer that
question, and here is who does." A single sharpening line closes it.

**Fix path (backlog · S).** One line under the levers, self-serve only:
"*HorizonPlan tells you the number — pick funds/instruments with your
adviser or a distributor of your choice.*" Prompt in §7.

### U-2 — No emergency-fund "how to build one" nudge when short · **Minor · Backlog**

**Where.** `FinancialFoundations::emergencyReserve()` returns status
'short' or 'partial' — the UI states the fact but never says "*here is
how you'd get from 1 month to 3.*" A person reading "short" with no next
step is exactly the "gap without a lever" pattern docs/11 E-1 fixed for
retirement.

**Fix path (backlog · S).** Same shape as gap-closing levers: state the
rupee delta to reach the floor (3 months × monthly expenses − liquid
today) and phrase it as a fact. Prompt in §7.

### U-3 — No goal prioritisation · **Deferred**

**Where.** Multiple goals render as an equal grid. There is no
`priority` on `base_plans`.

**Decision.** **Not adding.** Planners genuinely debate whether a user
should self-prioritise or whether the plan should compute it (typically:
mandatory > desired, hard-date > flexible-date). Both positions have
merit and picking one is a decide-then-build product decision. Recorded
as an open question, not a bug.

### U-4 — No income-shock scenario · **Deferred**

**Where.** The plan assumes continuous accumulation-phase income.
Sub-scenarios can express this manually (set `monthly_sip_amount` to
zero for a year), but nothing frames it as "what if I lost my job for
12 months?"

**Decision.** **Not adding.** The sub-scenario mechanism already
supports this and adding a dedicated "job-loss" wizard duplicates what
scenarios exist for. Worth revisiting once product analytics (docs/13
I-5, shipped) show how many people actually use scenarios.

---

## 6. Advisor + firm UX

### A-1 — No CRM signals (birthday / KYC-expiry / SIP-renewal) · **Already backlog**

Already on the record — `docs/10 P1-3` "*Lightweight CRM signals*". Not
built. Left as backlog; note it here so this review is complete.

### A-2 — No client-communication log · **Minor · Backlog**

**Where.** `change_log` records plan edits, not "I called Priya on
Monday and she wants to defer retirement by 2 years." Practitioner
tooling universally tracks contact touchpoints; HorizonPlan does not.

**Fix path (backlog · M).** A `client_touchpoints` table
(tenant-scoped) + a card on `ClientGoals.jsx`. Small schema, small UI.
Prompt in §7.

### A-3 — No batch operations (apply-template-to-N-clients) · **Deferred**

**Where.** `ApplyTemplateModal` operates per-goal. A senior advisor who
just approved a template naturally wants to apply it to a cohort.

**Decision.** **Not adding immediately.** This crosses into a "batch
edit" surface with real audit-trail implications — every batched change
must land as its own `change_log` row with a batch-id, and one bad batch
would be very hard to unwind. Worth doing but after billing (P2-4)
lands, because it's the sort of feature paying customers request.

### F-1 — PracticeAnalytics has no time series · **Minor · Backlog**

**Where.** `firm_analytics.php` returns *current* counts and sums, no
trend. A firm principal is often asked "*is the book getting healthier
over time?*" and the answer today is "here's a screenshot from three
months ago that we saved manually."

**Fix path (backlog · M).** A monthly snapshot of the analytics
payload (same schema/cadence as `client_net_worth_snapshots` — already
shipped in sql/032), and a small trend chart on `/practice`. Uses the
same principle sql/042 used for the individual: store the reading, do
not recompute historical values from today's assumption. Prompt in §7.

### F-2 — No firm-wide alerts view for firm_admin · **Minor · Backlog**

**Where.** `AlertsEngine.php` and `alerts_read.php` are per-client.
`clients_list.php` folds `has_goal_met` / `review_due` / `price_stale`
into per-client booleans for the dashboard's attention queue, but a
firm_admin cannot ask "*show me every drift alert across the whole book
today.*"

**Fix path (backlog · S).** A new `firm_alerts_read.php` (or extend
`firm_analytics.php`) returning the aggregated per-client alert bundles
across the tenant, gated to `sr_advisor`/`firm_admin` (same gate as
`firm_analytics.php`). No engine change — reuses `AlertsInputs.php`.
Prompt in §7.

### F-3 — Revenue attribution absent · **Deferred (dependency)**

Depends on P2-4 (billing). Recorded on docs/10 already.

---

## 7. Ready-to-run session prompts for the added backlog

Each prompt follows this repo's decide-then-build convention: state the
context, name the decisions to `AskUserQuestion` first, name the likely
shape, name the verification bar, name what NOT to build.

### Prompt — C-1 · Longevity horizon guidance

> **Context.** `base_plans.projection_horizon_years` is user-set with no
> guidance on the input. CFA-standard planning runs to age 90–95. A user
> picking "20 years" is implicitly modelling to age 80, an under-estimate
> for anyone whose lifestyle suggests otherwise. **Read docs/14 §3 C-1
> before starting.**
>
> **Decide first.** Whether to also add a *soft warn* on the input when
> `retirement_age + horizon_years < 90` (recommendation: yes, but the warn
> reads as a fact — "*This models your money to age N. Common practice is
> age 90–95.*", never as "*You should…*"). Whether to render the same
> guidance on both the advisor and the individual UIs (recommendation: yes,
> same input in both places).
>
> **Likely shape.** Copy addition on `GoalDetail.jsx` (advisor edit) and
> `PersonalOnboarding.jsx` (individual wizard, if this step exists there).
> No schema, no PlanMath change.
>
> **Verify.** Real browser: the note renders, the soft warn appears when
> the sum is under 90 and clears when raised. **Not building:** any
> hardcoded mortality table or default change — the point is to say the
> convention, not to enforce it.

### Prompt — C-2 · Return-assumption tax disclosure

> **Context.** `accumulation_return_rate` / `drawdown_return_rate` are
> nominal figures with no stated tax treatment. **Read docs/14 §3 C-2 and
> docs/12 D-2 (per-holding tax context, already shipped) before starting.**
>
> **Decide first.** Whether to state the disclosure once on the projection
> chart caption or under every rate-input field (recommendation: once on
> the chart caption + once under the accumulation-rate input, mirroring
> the existing "returns aren't guaranteed" copy). Whether to link to the
> per-holding tax context from the disclosure (yes — that's where the
> actual tax figures live).
>
> **Likely shape.** Copy addition on `GoalDetail.jsx`, and on
> `RetirementTargetCard.jsx`. No schema, no PlanMath change.
>
> **Verify.** Real browser: an individual and an advisor session both see
> the disclosure adjacent to the projection chart. **Not building:** any
> attempt to net-of-tax the projection numbers — that requires the
> transaction ledger this app deliberately doesn't have.

### Prompt — C-4 · Adverse-sequence chart caption

> **Context.** The amber-dashed adverse line on `GoalDetail.jsx` has no
> in-UI explanation of what it models — PlanMath's `adverseReturnSequence()`
> uses a fixed ±4-pp illustrative spread. **Read docs/14 §3 C-4.**
>
> **Decide first.** Whether to state the spread as a specific number
> (recommendation: yes — "*±4 points around your mean*" is more honest
> than "*some spread*"). Whether to add a "compare to Historical Replay
> below" pointer (yes — that's the answer to "*is this a real bad decade
> or a made-up one?*").
>
> **Likely shape.** Two-line caption under the sequence chart. No schema,
> no PlanMath change.
>
> **Verify.** Caption renders on every projectable goal (retirement +
> decumulation-only), doesn't render on target-based goals (which have no
> sequence chart). **Not building:** any change to `adverseReturnSequence()`.

### Prompt — T-1 · Annual tax-year summary

> **Context.** docs/12 D-2 shipped per-holding tax context; there is no
> portfolio-wide roll-up. A CA-ready client wants one page at
> year-end: "*here's what I owned as of March 31, here's the unrealised
> LTCG / STCG posture by regime.*" **Read docs/12 D-2 and docs/14 §4 T-1.**
>
> **Decide first.** Whether the report is client-visible or advisor-only
> (recommendation: both, same as the plan report). What "as of" date
> defaults to (recommendation: previous March 31 through today's date,
> user-selectable). Whether to include a print stylesheet (yes — this is
> the CA-facing artifact).
>
> **Likely shape.** A new `client_tax_posture.php` endpoint aggregating
> `client_portfolio_items` × `tax_reference`, and a new `TaxPosture.jsx`
> page under `/portfolio/tax`. Reuses existing tax rules; no new tax
> logic. Same "illustrative, never a filing figure" framing as D-2.
>
> **Verify.** Real HTTP: an advisor gets the report for any client in
> tenant; a client only for their own id. Cross-check a specific
> holding's roll-up figure against its per-row context.
> **Not building:** a filing-grade STCG/LTCG report (that's docs/10 P2-2,
> deferred; this is the planning-side summary that leads to that).

### Prompt — T-2 · Capture health-cover premium (80D)

> **Context.** `FinancialFoundations::healthCover()` records cover amount
> but not premium. 80D is a common Indian deduction worth capturing.
> **Read docs/14 §4 T-2.**
>
> **Decide first.** Whether to also capture the payer relationship
> (self / parents) — recommendation: no, one field, illustration-framed
> ("*this can typically be deducted under 80D up to Rs 25/50k depending
> on age*"). Whether the check surfaces in the Foundations card
> (recommendation: yes, as a small "*80D captured*" indicator, never as
> a passed-check).
>
> **Likely shape.** Add nullable `health_premium_annual` to
> `client_protection` (new migration). Update `FoundationsUI.jsx`. No
> deduction is claimed on behalf of the user — this is a captured fact,
> not a filing action.
>
> **Verify.** DB migration applies clean; existing rows have NULL
> premium; the check still passes for a cover-only, premium-null record.
> **Not building:** any filing/deduction engine.

### Prompt — T-4 · Estate + nominee basics

> **Context.** Nothing in the schema captures nominee or Will status. A
> Foundations check for "estate basics recorded" adds real planning
> value at almost no build cost. **Read docs/14 §4 T-4.**
>
> **Decide first.** Which basics to capture (recommendation: EPF/PPF/NPS
> nominee-named boolean, Will-drafted boolean, joint-holdings-noted
> boolean — three yes/no, no specifics, ever). Whether the checks
> surface as a fifth Foundations item (recommendation: yes).
>
> **Likely shape.** Add nullable `estate_nominee_named` /
> `estate_will_drafted` to `client_protection` (new migration). Update
> `FoundationsInputs.php`, `FinancialFoundations::summary()`, and
> `FoundationsUI.jsx`.
>
> **Verify.** Unrecorded reads as `not_recorded` (never `ok`). The check
> is per-person; the household summary does NOT aggregate estate status
> (each person's Will is their own). **Not building:** any document
> template, insurer/lawyer directory, or execution flow.

### Prompt — U-1 · Sharpen the "we don't name funds" answer

> **Context.** The gap-closing levers on `RetirementTargetCard.jsx` end
> with "*Closing this takes ₹8,400 more per month*", and a first-time
> individual reasonably asks "in what?" The disclosure banner says
> HorizonPlan doesn't recommend products, but the banner is not where
> the question is asked. **Read docs/14 §5 U-1.**
>
> **Decide first.** Whether the sharpening line is individual-only or
> also renders for an advisor session (recommendation: individual-only —
> an advisor knows why the app doesn't name funds; the sharpening is for
> a first-timer who does not). Exact copy — recommendation:
> "*HorizonPlan tells you the number — pick funds or instruments with
> your adviser, or through a distributor of your choice.*"
>
> **Likely shape.** One-line addition to `RetirementTargetCard.jsx` when
> `plain` is true and `lever` is non-null. No arithmetic change.
>
> **Verify.** Real browser: an individual sees the line under a
> non-null lever, doesn't see it when the goal is met. An advisor
> session never sees it.

### Prompt — U-2 · Emergency-fund gap-closer

> **Context.** `FinancialFoundations::emergencyReserve()` returns
> 'short' / 'partial' as a fact with no next step. docs/11 E-1 already
> fixed the same pattern for the retirement gap. **Read docs/14 §5 U-2
> and docs/11 E-1.**
>
> **Decide first.** Whether to phrase the delta as a total rupee amount
> or as a monthly saving target (recommendation: total rupee delta to
> the *floor* — "*You'd reach 3 months by adding ₹X to your liquid
> savings.*"). Whether it renders on the advisor Foundations card too
> (recommendation: yes — same fact-not-instruction rule applies).
>
> **Likely shape.** Extend `FinancialFoundations::emergencyReserve()` to
> return a computed `to_floor` figure when short/partial. Render on
> `FoundationsUI.jsx`. No new schema.
>
> **Verify.** Fact never contains an instruction word (existing test
> shape in `test_alerts_engine.php`). Not-recorded state still doesn't
> render the delta.

### Prompt — A-2 · Client-communication log

> **Context.** `change_log` captures plan edits; nothing captures "*I
> spoke to Priya on Monday and she wants to defer retirement by 2
> years.*" **Read docs/14 §6 A-2.**
>
> **Decide first.** Fields on the touchpoint row — recommendation:
> `channel` (phone/email/meeting/other), `summary` (short text),
> `occurred_at`, `follow_up_at` (nullable). Whether the client sees
> their own touchpoints (recommendation: no — an advisor's notes are
> for the advisor).
>
> **Likely shape.** New `client_touchpoints` table (tenant-scoped, per
> client). Endpoints `client_touchpoints_list.php` / `_upsert.php` /
> `_delete.php`. Card on `ClientGoals.jsx`.
>
> **Verify.** Full tenant-isolation test. Client session cannot read
> its own touchpoints (server 403). Full test coverage of the CRUD.

### Prompt — F-1 · Firm analytics over time

> **Context.** `firm_analytics.php` returns current values only. A
> principal is often asked "*is the book getting healthier over time?*"
> and can't answer today. **Read docs/14 §6 F-1 and sql/032
> (client_net_worth_snapshots — the existing precedent).**
>
> **Decide first.** Cadence (recommendation: monthly, same as
> client_net_worth_snapshots — reuses the same cron pattern). Retention
> (recommendation: keep forever, small row count). Client scope of the
> aggregated readiness (recommendation: same rule as today's
> `firm_analytics.php` — retirement goals only).
>
> **Likely shape.** New `firm_analytics_snapshots` table, monthly cron
> (`tools/firm_analytics_snapshot.php`), and a small trend chart on
> `/practice`. Same store-the-reading-do-not-recompute pattern
> `goal_snapshots.readiness_score` uses.
>
> **Verify.** DB: cron writes exactly one row per tenant per month
> (idempotent per day). UI: chart renders nothing below 2 points (same
> convention as `NetWorthTrend.jsx`).

### Prompt — F-2 · Firm-wide alerts view

> **Context.** `AlertsEngine.php` computes per-client alerts;
> `clients_list.php` folds three of them into a per-client boolean, but
> a firm_admin can't ask "*show me every drift alert across the book.*"
> **Read docs/14 §6 F-2 and CLAUDE.md's alerts-engine section.**
>
> **Decide first.** Whether this is a new endpoint or an extension to
> `firm_analytics.php` (recommendation: new endpoint,
> `firm_alerts_read.php`, gated same as firm_analytics.php — analytics
> loads for anyone in the firm, alerts loading may be heavier so
> keeping it separate lets the analytics page render fast). Whether to
> group by advisor (recommendation: yes, with a toggle "*group by
> client / by advisor / flat*").
>
> **Likely shape.** New endpoint, reuses `AlertsInputs.php`'s bundle
> gathering per client. New page `/practice/alerts`.
>
> **Verify.** Tenant-isolation test: firm A's admin cannot see firm
> B's alerts. Performance sanity: 200 clients × 5 alerts each returns
> under 1s locally.

---

## 8. What was fixed in this session

- **CSS bug fixed** — `--color-amber-ink` referenced in
  `PersonalPlanSummary.jsx:221` and `NetWorthTrend.jsx:127` but never
  defined; added to `index.css`'s `@theme` block. A decreasing readiness
  delta was silently rendering in the surrounding ink colour instead of
  the intended amber tint, muting the down-tick signal the code wanted.
- **WCAG contrast on coral CTAs** — white text on `--color-coral`
  (#E86A5C) computed to ~3.18:1, below WCAG AA 4.5:1 for normal text.
  All three affected CTAs on the landing page switched to
  `var(--color-ink)`, mirroring the paired teal buttons' contrast
  (~6:1).
- **Design-token consistency for coral shadows** — three inlined
  `0 6px 18px -6px rgba(232,106,92,0.45)` strings collapsed into
  `--shadow-coral` and `--shadow-coral-strong`, matching the existing
  `--shadow-teal` precedent.
- **Mobile-nav parity** — the desktop nav's coral dot next to "For
  individuals" was missing on mobile; added.

Every fix above landed with the review commit; the recorded diff is
in `docs/CHANGELOG_SESSION_HISTORY.md` on merge.

---

## 9. What is deliberately NOT being changed

Reproduced here for the same reason `docs/13 §8` reproduces its list — so
a future session sees the decision on the record rather than the finding.

- **No Monte Carlo / probability framing** — see C-6 and `docs/13 §8`.
- **No Guyton-Klinger dynamic withdrawal** — see C-3.
- **No glide-path** as a first-class modelled concept — see C-5.
- **No old-vs-new tax regime modelling** — see T-3 and `docs/13 §8`.
- **No NRI / DTAA support** — see T-5.
- **No HUF / joint holdings** — see T-6.
- **No goal prioritisation** — see U-3.
- **No income-shock wizard** (already possible via sub-scenarios) — see
  U-4.
- **No batch-edit surface** for cohort operations — see A-3.
- **No fund/product recommendation, ever** — this is the whole
  competitive posture and stands unchanged.

---

## 10. Sequencing recommendation

The eleven prompts in §7 sort by cost. Cheapest-first ordering that
delivers most CFA/CA credibility per hour:

1. **U-1** · Sharpen the "we don't name funds" answer *(one line, one
   file, high honesty payoff for first-timers)*
2. **C-2** · Return-assumption tax disclosure *(one line, two files,
   biggest CFA finding fixed)*
3. **C-4** · Adverse-sequence chart caption *(two lines, one file,
   removes the "what is this line?" question every practitioner asks)*
4. **C-1** · Longevity horizon guidance *(one line, one or two files)*
5. **U-2** · Emergency-fund gap-closer *(matches docs/11 E-1 shape;
   reuses existing pattern)*
6. **F-2** · Firm-wide alerts view *(reuses AlertsInputs.php; one
   new endpoint + one new page)*
7. **T-2** · 80D premium capture *(one column, one card update)*
8. **T-4** · Estate + nominee basics *(two columns, one Foundations
   check)*
9. **T-1** · Annual tax-year summary *(bigger, but reuses all existing
   tax context)*
10. **A-2** · Client-communication log *(new table, per-tenant CRUD)*
11. **F-1** · Firm analytics over time *(new snapshot table + cron)*

1–5 are half-a-day-each fixes; 6–11 are session-scale. Nothing in the
list needs a new external dependency, a new architectural principle,
or a schema change that violates the standing guardrails.
