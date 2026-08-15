# HorizonPlan — The Individual Flow: Design, Gaps and Build Plan

> Scope: the **self-serve individual tier** (`tenants.kind='personal'`, sql/033 +
> sql/035) — a person planning for themselves with no advisor. This document
> does not change the advisor product; where the two share a surface, the rule
> is that the individual gets *different copy and different ordering*, never a
> second data model (the standing rule from `PersonalOnboarding.jsx`).
>
> Read `docs/11` before adding any new *input* to this tier — it records what
> was deliberately ruled out and why. Read `docs/02` for how anything is built.
> This file governs *what the individual experience should be*.

---

## 1. The flow as it exists today

Verified by reading the code, not from the changelog.

| Step | Route | File | What happens |
|------|-------|------|--------------|
| 1 | `/start-free` | `PersonalSignup.jsx` | Email + password → creates a "tenant of one", issues a session, navigates to `/start` |
| 2 | `/start` | `PersonalOnboarding.jsx` | 7 questions → risk band → review → creates goals via `api.createGoal`, saves `dependants_count`, navigates to `/goals` |
| 3 | `/goals` | `GoalsList.jsx` | The person's home. Alerts, portfolio, cash flow, foundations, personalisation, dependants, partner, risk — **then** the goals |
| 4 | `/goals/:id` | `GoalDetail.jsx` | The real plan: charts, sliders, the retirement target and the gap-closing levers |

The engine underneath is strong. `PlanMath.php` already computes the
decumulation series, the adverse-sequence series, historical replay, the
readiness score, the retirement target, `is_met`, and — importantly —
`gapClosingLevers()`, which solves *both* "how much more per month" and "how
many more years" and names whichever is the smaller ask.

**The engine is not the problem. The routing of attention is.**

---

## 2. Gap analysis

Each gap below cites the file and line that demonstrates it.

### G1 — The home screen buries the thing the person came for · **Severity: high**

`GoalsList.jsx` renders, in order: `ClientAlertsCard` → `ClientPortfolioCard` →
`CashFlowCard` → `ClientFoundationsCard` → `PersonalisationCard` →
`DependantsCard` → `PartnerHouseholdCard` → `ClientRiskProfileCard` → **then**
the goals grid (`GoalsList.jsx:67–139`).

For an advisor-managed client that order is defensible — the advisor entered
the data, so the cards are read-outs. For an individual, every one of those
cards is an **empty form asking them to do work**, and their plan is below the
fold underneath eight of them. The person finishes onboarding excited and lands
on a chore list.

### G2 — The gap-closing answer never appears where the question is asked · **Severity: high**

`gapClosingLevers()` is the single most valuable output this app produces for an
individual: *"you're short — either ₹8,400 more a month, or three more years."*

It is computed in `goals_projection.php:281` and rendered in exactly one place —
`RetirementTargetCard.jsx`, reachable only by opening a goal
(`GoalDetail.jsx:586`). It is absent from `/goals` and absent from the
onboarding review screen.

So the person is shown their shortfall on the home screen (via the readiness
score and alerts) and told what to do about it only if they click through. The
answer exists and is already tested — it is simply not where the question is.

### G3 — The empty state is a dead end · **Severity: high**

`GoalsList.jsx:123–131` tells a person with no goals: *"You haven't set up a
plan yet. Answer a few questions and we'll build one you can change any time."*

There is no link. `/start` is reachable **only** from
`PersonalSignup.jsx:39`, immediately after signup. Anyone who abandons
onboarding, or deletes their goals, is permanently stranded on an empty page
that describes an action they cannot take.

### G4 — Onboarding ends on the card list, not on the payoff · **Severity: medium**

`PersonalOnboarding.jsx:104` navigates to `/goals`. The person has just answered
seven questions and picked a risk band; the moment of proof — *their* chart,
*their* number, *their* countdown — is one more click away, behind G1's card
stack. The "aha" is deferred past the point of highest intent.

### G5 — Money-goals are created with no way to fund them · **Severity: medium**

`suggestGoals()` (`personalPlanner.js:167–204`) asks how much the person saves
each month and puts **all** of it on the retirement goal. Education and home
goals are created with `initial_net_worth: 0` and no SIP at all.

The result: a person who says "yes, one child" gets an education goal that
reads 0% funded, has no contribution, and no modelled path — permanently, until
they discover the edit screen. A goal that cannot move is worse than no goal.

### G6 — The risk band is chosen by default, not by the person · **Severity: medium**

`PersonalOnboarding.jsx:36` initialises `riskId` to `'balanced'`. The risk step
pre-selects it, so a person can press Continue without ever making a choice and
walk away with a 9% return assumption they never consented to. Every other
answer in this wizard is blocked until given (`canAdvance()`, line 59); this one
is not.

### G7 — Choice questions auto-advance on click · **Severity: medium (accessibility)**

`PersonalOnboarding.jsx:142` advances the step inside the button's `onClick`. A
mis-click is instantly committed with no confirmation, and for a screen-reader
or keyboard user the screen changes underneath them with no announcement. There
is also no `aria-live` region on the "3 of 9" progress indicator.

### G8 — Nothing brings the person back · **Severity: high (business)**

There is no individual-facing lifecycle email anywhere. `Mailer.php` exists and
`PlanReviewMailer.php` uses it — for advisors. The monthly snapshot cron
(`tools/progress_snapshot.php`) faithfully records a person's progress and then
tells them nothing about it.

A retirement plan is checked a few times a year at best. With no nudge, the
self-serve tier has no retention mechanism at all.

### G9 — Zero product analytics · **Severity: high (business)**

There is no events/telemetry table in `sql/` — confirmed, none of the 39
migrations creates one. Nothing records where people drop out of the seven-step
wizard, which questions cause abandonment, whether anyone opens a goal after
onboarding, or which of the eight home cards is ever touched.

Every prioritisation decision after this document would be guesswork.

### G10 — Partner planning is invisible until you scroll past seven cards · **Severity: low**

sql/035 supports a couple planning together, and it is genuinely good work. But
`PartnerHouseholdCard` sits seventh on the home screen and the word "partner"
appears nowhere in onboarding — even though onboarding already asks *"does
anyone depend on your income?"*, which is exactly the moment the question is
natural.

---

## 3. Where this sits competitively

Two distinct markets, and HorizonPlan's individual tier is currently pointed at
the wrong one by default.

**Indian consumer apps — INDmoney, Kuvera, ET Money.** These are
**distribution-led**: goal planning is the funnel, fund selection and SIP
execution are the product. ET Money and similar ask four to five onboarding
questions and propose an SIP split; Kuvera adds tax harvesting and rebalancing;
INDmoney leads on multi-asset aggregation. Planning is the hook, not the thing
being sold.

**Global planning tools — Boldin (formerly NewRetirement), ProjectionLab.**
These sell **planning depth itself**, subscription-priced, with no product to
push. Boldin models 250+ data points, runs Monte Carlo, and charges $12/month;
ProjectionLab runs 10,000 Monte Carlo scenarios. Both lean on scenario
comparison and tax-strategy modelling.

**HorizonPlan's individual tier belongs in the second category, sold into the
first market — and nobody occupies that seat.** There is no "Boldin for India":
a planning tool that takes Indian retirement seriously (rupee inflation, the
3–3.5% India withdrawal baseline from `docs/05`, EPF/PPF/NPS, city-tier costs)
and sells the plan rather than the fund.

That is a real wedge, and the existing architecture already backs it: no order
routing, no commission, an explicit "facts, never instructions" rule in
`AlertsEngine.php`, and every threshold sourced rather than recommended. The
honesty posture that looks like a limitation against Kuvera is exactly the
credential that matters against Kuvera.

**What the global tools do that HorizonPlan does not, and whether it matters:**

| Their feature | HorizonPlan today | Verdict |
|---|---|---|
| Monte Carlo (1k–10k runs) | Deterministic steady vs. adverse + real historical replay | **Keep the current approach.** Historical replay of real market years is more honest and more explainable than a probability cloud. Consider borrowing only the *framing* ("in 8 of the last 30 starting years…"). |
| Account aggregation (Plaid) | CAS import + NAV cron | **Deliberate no** (docs/10 — not a transaction back-office). |
| Side-by-side scenario compare | Sub-scenarios exist but are viewed one at a time | **Real gap.** Cheap to close and high value. |
| Tax-strategy modelling (Roth conversion) | Per-instrument tax context, facts only (docs/12 D-2) | India analogue would be old-vs-new regime and 80C/NPS headroom. **Defer** — needs a tax engine this app deliberately does not have. |
| AI assistant over your own plan | None | **Worth a spike, not a build.** See §5. |

---

## 4. The design: what the individual flow should be

One sentence per screen, in the language the screen should actually use.

### 4.1 Home (`/goals`) — invert it

The individual's home screen answers three questions in this order:

1. **Where do I stand?** — one number, one sentence. The retirement countdown
   and the readiness score, with the plain-language caveat already written for
   `ReadinessScore.jsx`.
2. **What's the one thing to do next?** — a single card, never a list. The
   smaller of the two gap-closing levers, or the highest-severity alert, or the
   one unanswered foundations question. **One.**
3. **My goals** — the grid, immediately below.

Everything else — portfolio, cash flow, foundations detail, personalisation,
dependants, partner, risk — moves **below** the goals, or behind a "Refine your
plan" section that is collapsed by default and shows a completion count
("3 of 6 added").

This is a reordering, not new components. The cards already exist and already
work. Ship the order before shipping anything new.

### 4.2 Onboarding — end on the chart

Add one screen after "Create my plan": the person's own retirement goal, its
chart, its countdown, and — if there is a gap — the smaller lever, phrased as a
question rather than an instruction:

> You're about ₹42 lakh short of the number.
> Two ways to close it: **₹8,400 more a month**, or **retiring 3 years later**.
> Try either — nothing is saved until you say so.

Then a single button through to the plan. This is the aha moment, and today it
happens on a page the person has to go looking for.

### 4.3 Copy rules (already the house style — keep enforcing them)

`PersonalOnboarding.jsx` gets this right and it should be written down:
no "decumulation", no "corpus", no "drawdown". "How long your money lasts",
"what you've saved". Every percentage is labelled an illustration, never a
recommendation. Every alert states a fact and never contains an instruction
word — `AlertsEngine.php` already asserts this in tests, and the individual
surface must hold the same line.

---

## 5. Analytics — what to build, and the honest limit

There is no telemetry today (G9), so this is greenfield. The trap is building a
surveillance layer for a product whose whole credibility rests on restraint.

**The rule: record what the person DID in the product, never what they ARE.**
Step numbers, screen names, whether a lever was tried. Never a rupee figure,
never an age, never a goal label, never anything that would be a second copy of
financial data that already lives in a properly tenant-scoped table.

**Proposed `product_events` (new migration):**

| Column | Purpose |
|---|---|
| `tenant_id`, `user_id` | who, tenant-scoped like everything else |
| `event_name` | a closed enum, not free text |
| `event_context` | small JSON — step number, screen name, lever type. **Never a figure.** |
| `occurred_at` | when |

**The ten events worth having on day one:**

`personal_signup_started` · `onboarding_question_answered` (with step index) ·
`onboarding_abandoned` · `onboarding_risk_chosen` · `plan_created` ·
`first_goal_opened` · `lever_previewed` · `plan_edited` ·
`refinement_answered` · `partner_invited`

**The five questions those answer:**

1. Which of the seven onboarding questions loses the most people? (G5/G6/G7 are
   hypotheses — this measures them.)
2. What share of people who create a plan ever open a goal? (Tests G1 and G4
   directly.)
3. Does anyone try a gap-closing lever? (Tests whether G2 is worth the fix.)
4. Which refinement questions get answered and which are ignored? (docs/11 E-2
   shipped six; nobody knows which earn their place.)
5. Do people come back at all, and after how long? (The G8 retention case.)

**Explicitly out of scope for this table:** anything that would let someone
reconstruct a person's financial position from the event log. If a metric needs
a rupee value, it is a query against the real tables under tenant scope, not an
event.

---

## 6. Prioritised backlog

> **Build status.** P0, P1 and P2 are built. I-6 was **investigated and
> deliberately not built** — see its entry for why; it turned out to rest on a
> false premise in this document's own G5. I-12 remains a spike, not a
> commitment.

### P0 — Fix the flow before adding to it

**I-1 · Invert the individual home screen.** ✅ **Built.** `GoalsList.jsx` now
branches on `tenant.kind === 'personal'`: standing → next action → goals →
everything else inside a collapsed `RefineSection`. Advisor-managed clients
keep the original order, unchanged. *(§4.1, closes G1)*

**I-2 · Surface the gap-closer where the gap is stated.** ✅ **Built** — on the
home standing block (`PersonalPlanSummary.jsx`), via the *same*
`goals_projection.php` call the goal page uses, so the two can never disagree.
`leverLine()` was **exported** from `RetirementTargetCard.jsx` rather than
copied, following the `FoundationsInputs.php` precedent.

**Scoped down from the original proposal**, which also said "on the goal card".
Per-goal levers would need a projection call per goal on the roster — the same
cost asymmetry `clients_list.php` already refuses for `goal_drift`. The
standing block carries it once, for the retirement goal, which is the only goal
type with a solvable gap anyway. *(§4.2, closes G2)*

**I-3 · Make the empty state a door.** ✅ **Built.** The `GoalsList` empty state
now links to `/start`. *(Closes G3)*

**I-4 · End onboarding on the chart.** ✅ **Built.** A `PlanCreated` proof screen
fetches the real projection and states the countdown, whether the plan reaches
its number, and — if not — what closes the gap. *(§4.2, closes G4)*

**I-5 · Ship `product_events`.** ✅ **Built.** `sql/040`, `api/lib/ProductEvents.php`,
`api/product_event_log.php`, `api.logEvent()`. The privacy rule is enforced by
`tests/test_product_events.php`, which asserts a rupee figure cannot reach the
table through an unlisted key, a whitelisted integer key, or a numeric string.
Wired into the onboarding funnel and the lever click. *(§5, closes G9)*

### P1 — Correctness and consent

**I-6 · Split the monthly saving across goals.** ❌ **Investigated, deliberately
not built.** G5's premise was wrong, and this is worth recording rather than
quietly dropping.

The proposal was to allocate the stated monthly saving across the suggested
goals. Attempting it surfaced three facts:

1. `goals_create.php:130` explicitly nulls `monthly_sip_amount` for any
   non-retirement goal, so a SIP sent from the wizard would be silently
   discarded.
2. `PlanMath::targetGoalFunding()` ignores contributions by design and
   documents itself as *"a floor, not a forecast"*.
3. `CLAUDE.md`'s standing rule is that target-based goals carry **no return
   assumption and the app will not invent one** — and projecting a
   contribution requires one.

So the real gap is not "the wizard doesn't split the SIP". It is that
**target-based goals have no accumulation model at all**, which is a
deliberate, documented product position. Changing it means giving education and
home goals a return assumption and adding a projected figure alongside the
existing no-growth floor — a decide-then-build product decision, not a wizard
tweak. `personalPlanner.js` now carries a comment saying exactly this, and
`test_personal_planner.mjs` asserts the current behaviour so it cannot drift
silently.

**Open question for the next session:** should a self-serve individual's
target-based goals project contributions using the risk band they already
chose? The person picked that rate, so using it invents nothing — but it
changes what `covered_pct` means. Needs an explicit decision.

**I-7 · Require an explicit risk choice.** ✅ **Built.** `riskId` starts `null`
and `canAdvance()` gates on it, with a hint saying why Continue is disabled.
*(Closes G6)*

**I-8 · Accessibility pass on the wizard.** ✅ **Built.** Choice questions are
now a real `radiogroup` and no longer auto-advance; the step counter is a
`role="status" aria-live="polite"` region; currency and number inputs are
`type="text" inputMode="numeric"` so a stray scroll wheel cannot silently
change a value. **Also found and fixed while in there:** `min`/`max` on the
questions were never actually enforced — an age of 200 passed straight through
— so `canAdvance()` now checks the range and `advanceHint()` explains any
block. *(Closes G7)*

### P2 — Retention and depth

**I-9 · The monthly "here's where you are" email.** ✅ **Built.** `sql/041`,
`api/lib/PersonalDigestMailer.php`, `tools/personal_digest_send.php`,
`personal_digest_pref.php`, `DigestPreference.jsx`.

The body is composed from **AlertsEngine's existing facts** rather than a second
view of the plan — a digest that computed its own numbers would eventually
contradict the app, and being told two different things about your own money is
worse than being told nothing. `tests/test_personal_digest.php` asserts no
instruction word survives into a composed body, the same guard the alerts engine
holds itself to.

**Changed from the plan: opt-IN, not opt-out.** This document said "opt-out from
day one". That contradicts `CLAUDE.md`'s standing "default new toggles OFF" rule
*and* the direct precedent in `PlanReviewMailer` (docs/10 P0-3), whose own header
states a client is only ever emailed when someone explicitly opted them in. The
column defaults to `0`, so applying the migration cannot start emailing anybody.
The opt-in is offered where it is actually relevant — the post-onboarding proof
screen and Settings — rather than assumed.

Someone with nothing worth reporting is skipped and **not** marked sent: silence
rather than a "no change this month" email, and they are reconsidered next run.
*(Closes G8)*

**I-10 · Side-by-side scenario compare.** ✅ **Built.** `ScenarioCompare.jsx`, on
`GoalDetail` behind a "Compare side by side" toggle, shown only for a retirement
goal with at least one scenario.

Read-only on purpose: editing stays in `ScenarioPanel`. A grid where every cell
is editable is a spreadsheet, and a spreadsheet is what this product exists to
replace. It calls the same `goals_projection.php` once per column — no new
backend, and no second computation that could disagree with the panel a column
came from. Includes the **adverse-sequence** row, which names the year the money
runs out rather than showing a flattering steady-return ending balance. *(§3)*

**I-11 · Offer partner planning at the natural moment.** ✅ **Built.** The invite
appears on the proof screen, but **only** when the person answered that someone
depends on their income. Placed after plan creation rather than mid-wizard: it
is a natural next step once a plan exists, and interrupting the questions to
ask for someone else's email would cost more completions than it gains
partners. *(Closes G10)*

**I-13 · Say who the plan is about, and how its answer has moved.** ✅ **Built.**
Three additions to the standing block, from a review that weighed the
practitioner position against what individuals demonstrably ask for.

*What individuals ask for:* essentially every personal-finance product sold to
consumers leads with **net worth over time** — Notion templates, Sheets
trackers, Empower, Popadex. The line going up *is* the product. The second
recurring want is "am I on track?", and the honest answer is against the
person's own goal, never a peer benchmark — which validates the existing
readiness score over any "compare to others your age" feature.

*Where the practitioner view overrides it:* a **month-over-month** delta is the
wrong instrument, for three separate reasons. (1) Benartzi & Thaler's myopic
loss aversion is the documented result — more frequent evaluation means more
observed small losses, lower equity allocation, worse lifetime outcomes; a
person with a 19-year horizon should not be handed a monthly scoreboard. (2)
The data cannot support it: `initial_net_worth` is static and deliberately
decoupled from the NAV-tracked portfolio (docs/02 §4.1), so a goal-corpus
monthly chart would flat-line and read as broken. (3) It answers a portfolio
question when this product exists to answer a planning one.

So what shipped:

1. **Identity + assumption strip.** Name, current age, retirement age, with a
   "Not right? Change it" link. `current_age`/`retirement_age` set the horizon
   every other number is computed over and were visible only inside a goal — a
   wrong age silently invalidated the entire projection with nothing on the
   surface to catch it. `session.php` now carries `display_name`/`email`;
   `displayNameFor()` in `AuthContext.jsx` holds the single fallback chain
   (display_name → email local part → no greeting at all rather than a greeting
   addressed to nobody), because sql/035 left the column nullable and
   un-backfilled.

2. **`NetWorthTrend.jsx`** — the missing consumer for a series that already had
   a table (sql/032), a monthly cron, an endpoint and an API method, and no UI
   whatsoever. Delta framed **"since you started tracking"** with the start
   month named; renders nothing below **three** points, because two joined dots
   read as a trend when they are only the two facts held.

3. **Readiness delta.** `goal_snapshots.readiness_score` (sql/042) stores the
   score *as the plan stood* on each date — stored, not recomputed, for the same
   reason 032 stores `expected_value`: recomputing would let a return-assumption
   edit today rewrite what the score was last March. `readiness_change` in
   `goal_progress_read.php` spans **scored points only** (NULL means
   target-based, zero-corpus, or pre-migration — never zero) and is null below
   two of them.

**Advisor dashboard deliberately unchanged**, and the asymmetry is the finding.
An advisor's question is not "how am I doing" but "which of my 200 clients need
me this week, and what changed since I last looked". A per-client time series is
noise at 25 rows a page and already lives correctly on the client page. The real
advisor-side gap is that `needsAttention()` is **stateless** — it recomputes
every load, so an advisor who cleared the queue Monday sees the identical banner
Friday. That is the already-recorded `alert_ack` deferral (docs/12 D-4), not
this one. Book-level trend (AUM, readiness spread over time) would be the
advisor-appropriate series if one is ever built.

### P3 — Spikes, not commitments

**I-12 · "Ask your plan" spike.** A natural-language question box over the
person's own numbers ("what if I retire at 58?"). Boldin ships this. It is
genuinely useful and genuinely risky: this app's whole posture is *facts, never
advice*, and a model that answers freely will produce advice. **Spike only** —
prove it can be constrained to restating computed figures before any commitment
to build it.

---

## 7. Test cases

Repo convention: pure tests are plain PHP under `tests/`, run by
`tests/run_all.sh`, using `assertTrue`/`assertClose` (see
`tests/test_goal_progress.php`). DB-touching tests take the `*_db.php` suffix
and self-skip without a configured database.

### 7.1 `tests/test_personal_planner.mjs` (new, pure) — ✅ built, 32 assertions

`suggestGoals()` had no test file. **Note the extension:** this document
originally specified a `.php` test for a JavaScript module, which is not
possible. It runs on node's own runtime with no test framework and no new
dependency, using the same PASS/FAIL convention as the PHP tests, and
`run_all.sh` skips it when node is absent — the same self-skipping posture the
DB tests use.

- Always suggests a retirement goal, whatever the answers.
- `kids: 'none'` produces no education goal; `'one'` produces one; `'many'`
  produces one goal sized for two.
- `home: 'owned'` and `'no'` produce no home goal; `'yes'` produces one.
- `projection_horizon_years` floors at 20 even when `retire_age` is 75.
- **After I-6:** the monthly saving is distributed across the suggested goals
  and the parts sum to the stated total — no rupee invented, none lost.
- `retirementCountdown` returns `reached: true` at and past the retirement age,
  and never counts below zero.

### 7.2 `tests/test_gap_lever_surfacing_db.php` (new, DB)

Guards I-2 against regression at the contract level.

- A goal with a shortfall returns a non-null `gap_closing_levers` from
  `goals_projection.php`, with `smaller_lever` set to `'sip'` or `'deferral'`.
- A goal that is met returns `null` — never a zero-value lever.
- The block is present for a **personal-tenant client session**, not only for an
  advisor session. (This is the actual bug risk in I-2: a client-session
  response that quietly omits the field.)

### 7.3 `tests/test_product_events.php` (new, pure) — ✅ built, 24 assertions

Shipped as a **pure** test rather than the `*_db.php` originally specified: all
the enforcement lives in `sanitiseProductEventContext()`, which takes no PDO,
so the privacy rule is testable with no database and therefore actually runs in
this environment.

- `event_name` outside the closed set is rejected; a typo is not silently
  recorded as a new event.
- **A financial value cannot reach `event_context`** — asserted for an unlisted
  key, a whitelisted *integer* key (out-of-range guard), a whitelisted key
  carrying a numeric *string*, and a float. This is §5's rule enforced by the
  suite rather than by good intentions.
- Arrays, booleans, nulls and over-long strings are dropped rather than coerced.

**Still worth adding (not built):** a `*_db.php` companion asserting the row is
tenant-scoped through `TenantScopedDb` and that the FK cascade clears events
with the user.

### 7.4 Additions to existing files

- `tests/test_financial_foundations.php` — a personal tenant with
  `dependants_count = 0` reports life cover `not_applicable`, and an *unanswered*
  count reports `not_recorded`. These must never render the same; this is the
  module's whole design and deserves an explicit assertion at the individual
  tier.
- `tests/test_household_self_service_db.php` — after I-11, a partner invited
  from onboarding lands in the same household as one invited from the home card.

### 7.5 Browser checks (the repo's real bar)

Per `CLAUDE.md`, the standard is a real Playwright run, not static review:

1. Sign up at `/start-free` → complete `/start` → land on the proof screen (I-4)
   → reach the goal detail page.
2. Delete every goal → confirm the empty state offers a route back to `/start`
   (I-3).
3. Load `/goals` as a personal tenant → confirm goals render above the fold and
   the refinement cards are collapsed (I-1).
4. Complete the wizard using **keyboard only** → confirm no step advances
   without an explicit Continue, and the progress change is announced (I-8).
5. Load `/goals` as a **firm-managed** client → confirm the ordering and
   affordances are unchanged. This is the regression that matters most: none of
   this work may leak into the advisor product.

---

## 8. Decisions on record (deliberately not doing)

- **No Monte Carlo.** The deterministic steady/adverse pair plus real historical
  replay is more explainable and more honest than a probability cloud, and it
  matches the existing "never fabricate a figure" rule. Revisit only if user
  research shows people actively want a probability framing.
- **No account aggregation.** Already settled in docs/10 — this is a planning
  tool, not a transaction back-office.
- **No fund or product recommendation, ever, on the individual tier.** This is
  the entire competitive position described in §3. The moment the app names a
  fund, it is Kuvera with fewer features.
- **No tax-optimisation engine.** docs/12 D-2 deliberately stopped at "facts
  only" because there is no transaction ledger. Old-vs-new-regime modelling
  would need one.
- **Analytics never store financial values.** §5. Enforced by test 7.3, not by
  convention.

---

## 9. Suggested sequencing

P0 is one focused session: I-1 through I-4 are the same surface and share a
Playwright pass; I-5 is independent and can run in parallel.

Then **stop and read the data** before starting P1. I-5 exists precisely so that
G5, G6 and G7 stop being my hypotheses and start being measured facts. Every
item in P1 and P2 above should be re-ranked against what the funnel actually
shows — including the possibility that some of them do not matter.
