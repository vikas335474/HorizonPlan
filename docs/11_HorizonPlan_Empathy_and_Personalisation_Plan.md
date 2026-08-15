# 11 — HorizonPlan: Empathy & Personalisation Plan

The self-serve individual tier (sql/033) and household tier (sql/035) gave a
person a plan they can build and edit alone. This document covers the next
problem: the plan is *arithmetically* correct and *personally* generic. It sets
out how to make it feel like it was built for one specific life, and — equally
important — where it must stop.

Read `docs/07` for the product thesis and `docs/10` for the backlog this sits
beside. `docs/02` still governs *how* anything here is built.

---

## 1. The defect that frames everything

The personal demo currently renders, on one screen:

- **Readiness 89/100**
- **44% of the target**

Both are computed correctly. That is the problem.

`PlanMath::readinessScoreForGoal()` asks one question — *does this corpus
survive being drawn down at its own withdrawal rate for the horizon?* It never
compares the corpus to what the person actually spends.
`PlanMath::retirementTarget()` (added later) does exactly that comparison, and
disagrees.

So the app can tell someone their plan is "Strong" while it funds under half of
their real life. **This is not a cosmetic inconsistency — it is the strongest
argument for personalisation.** Every number in the app is currently internally
consistent and externally unanchored. Personalisation is not decoration on top
of a working model; it is what gives the existing numbers a referent.

Fix this first, before adding a single new input.

---

## 2. Design principles (these govern every prompt below)

1. **No cold-start tax.** Ask ~5 questions, show a real number, *then* offer
   "want to make this more accurate?" Every additional answer must visibly move
   a figure on screen. A long intake before any output is where consumer
   finance tools die.
2. **"I don't know" is a first-class answer.** Every question needs a sourced
   default, and the UI must distinguish *the user's figure* from *our
   assumption* at a glance. The codebase already holds this line —
   `FinancialFoundations.php` keeps `not_recorded` strictly distinct from a pass
   — and it extends to every assumed number added here.
3. **Never pair a gap with silence.** "You're 44% there" with no next step is
   just anxiety. Every shortfall carries the smallest concrete lever that closes
   it ("₹8,400/month more", "retiring 2 years later"). This is the single change
   that converts the tool from judge to ally.
4. **Suggestions anchor, hard.** The moment the app says "a doctor costs ₹X",
   that number sticks even when wrong. Therefore: **sourced ranges with their
   drivers named, never point estimates, always editable, always attributed** —
   the same framing already used in `lib/strategyPresets.js` and
   `FinancialFoundations.php`.
5. **Ask about the financial consequence, not the personal fact.** See §5.

---

## 3. What external data actually exists (and what doesn't)

| Want | Reality | Use |
|---|---|---|
| City cost-of-living index | **No official Indian index exists.** | Use the **7th Pay Commission HRA classification (X / Y / Z cities)** — government-published, stable, citable. |
| City-wise inflation | Not published per city. | **Do not vary inflation by city.** MOSPI urban CPI only. See below. |
| Medical-course cost | NMC publishes fee disclosures; state counselling sites publish seat fees. | Range only: government MBBS ≈ ₹1–5L total; private ≈ ₹60L–1.2Cr. |
| Engineering-course cost | AICTE / institute fee disclosures. | Range only. |
| General "cost of living" sites | Crowd-sourced, unverifiable, ToS-restricted. | **Do not scrape.** |
| Retirement-spend anchor for someone who doesn't know their own spending | **MOSPI HCES** publishes average MPCE by state and urban/rural. | Sourced anchor **beside** the person's own figure, never pre-filled. See below. |

**Numbeo, specifically — asked for, and declined.** Numbeo is the canonical
example of the "general cost-of-living sites" row above, and the question came
up directly, so the answer belongs on the record rather than in a rule someone
has to infer. Verified against Numbeo's own terms:

- Automated collection (scraping/crawling) is **prohibited** without prior
  written permission.
- Commercial use requires a paid subscription, and that licence **"does not
  permit the republication or dissemination of Numbeo data through other APIs
  or public-facing data feeds without prior consent"** — which is arguably
  exactly what showing Numbeo-derived rupee figures to end users in this
  product would be.
- The data is crowd-sourced and its Indian coverage is thin outside the metros
  and skewed toward expatriate cost baskets — the wrong shape for a product
  whose market is largely non-metro India.

**MOSPI's Household Consumption Expenditure Survey is better on every axis that
matters here:** official, free, citable, no licensing surface, and it covers
every state and UT including rural. It is the source behind
`api/lib/LivingCostReference.php`.

**The trap, and how it is handled.** MPCE is *average per-capita consumption*,
not "what a comfortable retirement costs" — and this product's users spend well
above the population average. An anchor that reads low understates a retirement
target, which is the most harmful error this app could make. So the figure is
**never pre-filled** into the person's expense field; it renders beside it as
context, scaled from per-capita to household size, carrying the API's own
caveat verbatim, and saying out loud when it has fallen back to the national
average rather than their state's.

**Two non-obvious calls, both load-bearing:**

- **City tier adjusts the EXPENSE assumption, never the inflation rate.**
  Absolute cost differs a lot across cities; inflation differs far less.
  Applying a "tier-1 inflation rate" would compound a small error over 25 years
  into a large one. This is the kind of mistake that looks personalised and is
  actually just wrong.
- **Ask the cost driver, not the dream.** "Government seat or private?" moves
  the education number ~20×. "Doctor or engineer" moves it far less. Asking the
  driver is both more empathic and honestly computable; asking only the
  aspiration produces a confident number that is wrong for nearly everyone.

**Runtime constraint:** the app has **no runtime internet access** (Hostinger
shared hosting, static frontend + PHP). Any external figure must follow the
existing pattern — `tools/mf_nav_sync.php` → `mf_nav_cache`, a periodic CLI
cron that caches, with the app reading only the cache, never making a live call
inside a request. `market_history`'s "unverified data" flag is the precedent for
provenance on a figure the app didn't compute itself.

---

## 4. Sequenced prompts

Each prompt is standalone and runnable in a fresh session. Follow the repo's
**decide-then-build** convention: confirm the open choices with `AskUserQuestion`
*before* writing a migration.

---

### Prompt — E-1 · Reconcile the score, and name the lever

> **No new data, no migration. Highest value per line changed. Do this first.**
>
> `PlanMath::readinessScoreForGoal()` scores whether a corpus survives drawdown
> at its own withdrawal rate. `PlanMath::retirementTarget()` scores whether the
> corpus covers the person's actual expenses. They can disagree sharply — the
> personal demo shows 89/100 and 44%-of-target on one screen. Read both methods
> in `api/lib/PlanMath.php` and the `FoundationsCaveat` component before
> deciding anything.
>
> **Decide with the user first (`AskUserQuestion`):** should the readiness score
> (a) stay as-is with stronger adjacent framing, (b) be suppressed when a
> retirement target exists and contradicts it, or (c) be blended with target
> coverage into one number? Option (c) changes the meaning of an existing
> metric that advisors may already rely on — surface that trade-off rather than
> picking silently.
>
> **Then build, regardless of that choice:** a "smallest lever that closes the
> gap" line on `RetirementTargetCard`. Given the gap, solve for (i) the
> additional monthly SIP that closes it, and (ii) the retirement-age deferral
> that closes it. Both are pure arithmetic against existing `PlanMath`
> accumulation methods — add them as a tested pure helper, not inline in the
> component. Show whichever is smaller first; show both. Never render a gap
> without at least one lever.
>
> Verify per repo convention: real MariaDB, `tests/run_all.sh`, and a real
> Playwright run against the personal demo.

---

### Prompt — E-2 · Progressive personalisation ("make this more accurate")

> **Adds inputs. Migration required. Do after E-1.**
>
> Build an opt-in, progressive refinement flow on the self-serve plan: the plan
> works with what it already has, and offers a short queue of "want to make this
> more accurate?" questions, each of which visibly moves a number when answered
> and is individually skippable.
>
> **Scope the first three:**
> 1. **City tier** — X / Y / Z per the 7th Pay Commission classification.
>    Adjusts the **expense** assumption only, as a **visible, user-overridable
>    multiplier**. Never touches the inflation rate (see §3).
> 2. **Dependants' education stage** — per child: current age, and the **cost
>    driver** (government / private / overseas), not the aspiration. Feeds
>    existing target-based education goals.
> 3. **Ongoing medical cost** — a rupee figure, not a condition (see §5).
>
> **Decide with the user first:** whether these live on the existing
> `client_protection` table, a new `client_context` table, or on the goal.
> Storage shape determines whether a household shares them — and per sql/035,
> reserve/debt aggregate across a household while cover stays per-person, so
> each new field needs that same explicit scope call.
>
> **Hard requirements:** every suggested figure renders as a **range with its
> source and as-of date**, visibly marked as an assumption until the user edits
> it; every field defaults to unset; **no backfill** for existing tenants;
> `not_recorded` never renders as a pass. Follow `FinancialFoundations.php` —
> it is the worked example for all of this.

**Built.** Decisions taken (no interactive user was available in that session
to confirm them live, so they're recorded here per the decide-then-build
convention, all following FinancialFoundations' existing precedent):
- **Storage:** a new `client_context` table (one row per client — city tier +
  an optional multiplier override + monthly medical cost, mirroring
  `client_protection`'s shape) plus a new `client_dependants` table (one row
  **per child**, since `client_protection`'s single-row shape can't hold a
  list). sql/036.
- **City tier is HOUSEHOLD-shared** (falls back to a household partner's
  answer, same precedent as reserve/debt); **medical cost stays PER-PERSON**
  (same precedent as the existing cover fields). Dependants are
  household-shared by the same read-time aggregation `foundations_read.php`
  already uses for `cash_flow_items`.
- **What the city tier actually adjusts:** the retirement-YEAR spend inside
  `PlanMath::retirementTarget()` only (a genuine "I'll retire somewhere
  cheaper/costlier" scenario) — never the real recorded cash-flow expenses
  E-1 already prefers, and never the inflation rate.
- **Education driver → goal link:** explicit, nullable `goal_id` on each
  dependant row, chosen from the person's own education-type goals — never
  auto-matched by title or date.
- Reference ranges (city-tier multipliers derived from 7th CPC HRA rates;
  education cost ranges by driver) live as sourced code constants in
  `api/lib/PersonalisationReference.php`, the same seed-data role
  `lib/strategyPresets.js` plays elsewhere — Prompt E-3's `reference_costs`
  cron supersedes this file's constants as its seed/fallback, not a second
  source of truth.

---

### Prompt — E-3 · The sourced reference library ("shall I look this up for you?") — BUILT

> **Adds a cron + a table. Do last — E-2's UI is its only consumer.**
>
> **Status: built.** `reference_costs` (sql/037 — bumped from 036 on merge:
> E-2's own session independently claimed 036 for `client_context` /
> `client_dependants`, in a parallel run that finished first), plus
> `api/lib/ReferenceCosts.php`, `tools/reference_costs_sync.php`, and the
> read endpoint `api/reference_costs_get.php`, are all in place exactly as
> scoped below.
>
> This session started before E-2 had shipped and initially pulled forward a
> standalone UI slice (an opt-in suggestion card directly on an education
> goal's target-amount field), reasoning E-2's queue didn't exist yet to be
> the consumer. By the time this branch was ready to merge, E-2 *had* shipped
> — with its own `DependantsCard` (per-child, driver-based, explicitly linked
> to an education goal, sourced from `PersonalisationReference.php`'s
> constants) already doing that exact job, better, from linked per-child
> data rather than a bare goal-type check. Running both at once would have
> shown two independently-sourced "typical cost" ranges for the same goal
> that can silently disagree — so the pulled-forward UI slice was removed at
> merge time in favour of E-2's. What remains from this session is the
> backend E-3 actually scoped: the table, the cron, and the read endpoint,
> verified against a real MariaDB + `tests/run_all.sh` + a real HTTP round
> trip + a real Playwright run (pre-merge) confirming the now-removed card
> rendered and wrote correctly.
>
> **Reconciled with `PersonalisationReference.php` in a follow-up session**
> (confirmed with the user first via `AskUserQuestion`, per the decide-then-
> build convention — this was a real product decision, not a mechanical
> merge fixup): `reference_costs`' education subcategories were widened from
> the course-specific split (`engineering_government`/`engineering_private`/
> `medical_government`/`medical_private`) to the same generic driver enum
> `client_dependants.cost_driver` actually uses
> (`government`/`private`/`overseas`) — seeded with
> `PersonalisationReference`'s own already-live figures, so the reconciliation
> changed no behavior for anyone using the queue. `educationRangeForDriver()`
> now reads `reference_costs` first when a `$db` is passed (e.g.
> `dependants_list.php`) and falls back to the hand-authored constants when
> the cache has no row yet — `reference_costs` is now the real source of
> truth for education ranges, not a second, drifting copy. The sync
> (`syncReferenceCosts()`) also gained a **prune** step: a `(category,
> subcategory)` the current dataset no longer defines is deleted, not left
> as an orphan (verified end to end against a real MariaDB — the sync's
> first post-reconciliation run correctly removed all 7 pre-reconciliation
> rows and reseeded the 4 reconciled ones).
>
> **City tier was deliberately NOT reconciled the same way** — the user chose
> to drop the duplicate rather than force a merge. `PersonalisationReference`'s
> multiplier is an exact derivation from a published 7th-CPC HRA percentage
> (X=1.00 baseline by construction), not an uncertain estimate; a "sourced
> range" cache table is the wrong shape for a precise formula. The earlier
> `city_expense_multiplier` rows also used a different, incompatible baseline
> (relative to a national average, not tier X) that was never wired to
> anything — so `reference_costs` now holds no city-tier rows at all, and
> `cityTierMultiplier()` stays a pure code-constant derivation. See
> `api/lib/ReferenceCosts.php`'s header for the full writeup.
>
> Build `reference_costs`: a table of cited cost ranges (education by stage and
> driver, healthcare, city expense multipliers), each row carrying `category`,
> `low`, `high`, `source_name`, `source_url`, `as_of_date`, and an
> `is_verified` flag mirroring `market_history`'s unverified-data precedent.
>
> Populated by a **periodic CLI job under `tools/`**, following
> `tools/mf_nav_sync.php` exactly: CLI-only guard, logic in `api/lib/`, failure
> leaves the existing cache untouched rather than writing partial data. The app
> reads only the cache — **never a live outbound call inside a request**, which
> the hosting could not support anyway.
>
> Sources must be **named and citable** (MOSPI, RBI, NMC, AICTE, 7th CPC).
> **Do not scrape general cost-of-living sites** — unreliable, licence-risky,
> and it would put unattributable numbers into a retirement decision.
>
> Surface as an **opt-in prompt**, never a silent default: *"Typical private
> engineering degree: ₹8–16L total (AICTE fee disclosures, 2025). Use this? —
> you can change it."* Document the cron setup in `DEPLOY.md` alongside the MF
> NAV job.

---

## 5. Where I would not go, and why

**Medical history.** Storing conditions or diagnoses makes this **sensitive
personal data under the DPDP Act 2023**, and adds consent, retention and breach
obligations to a product that currently carries none of that surface.

It is also unnecessary. The arithmetic needs the **financial consequence**, not
the diagnosis: *"Does anyone in your household have an ongoing condition that
adds regular medical cost?"* → a rupee figure, plus a cover-adequacy check
against `FinancialFoundations::healthCover()`. This is more empathic *and*
materially safer — asking about someone's budget rather than their body.

**Aspiration-driven auto-suggestion.** Dynamically re-costing a plan because a
child's stated ambition changed is emotionally appealing and financially
misleading — the ambition is not the cost driver (§3). Re-cost on the driver;
let the aspiration be a label on the goal.

**The advice line.** The more the tool tailors to a specific life, the closer it
moves to *advice* rather than *illustration*. HorizonPlan is deliberately on the
planning side of that line, and `docs/06`'s guardrail — never author a
recommendation on the firm's behalf — was written for this exact pressure.
Everything above ships the **mechanism and the sourcing** and leaves the
judgment with the user. That line is worth defending explicitly as this gets
more personal; it should not be crossed without a deliberate decision on record.

---

## 6. Open items carried in from prior sessions

- Migrations **033, 034, 035, 036, 037** are not yet applied on the production
  deployment — run manually via hPanel (see `DEPLOY.md`), and run
  `tools/reference_costs_sync.php` once after 037 lands (the "shall I look
  this up for you?" affordances render nothing until the cache is populated).
- The guided tour still renders advisor vocabulary to self-directed users
  ("the number a **client** can hold onto", "adjust per **client**").
- **E-2 and E-3 reconciled** (education only — see E-3's status note above
  for the full writeup and the city-tier exception). `dependants_list.php`'s
  suggested ranges now come from `reference_costs` first, falling back to
  `PersonalisationReference`'s constants only while the cache is empty.
  Re-verified end to end: `tests/run_all.sh` green (including the new
  `tests/test_personalisation_reference_db.php`), plus a live HTTP proof —
  editing a cached row's `low`/`high`/`source_name` directly in the DB and
  re-hitting `dependants_list.php` returned the edited values, then a fresh
  `reference_costs_sync.php` run restored the dataset's own numbers.
- **Still open:** `PersonalisationCard`'s medical-cost question has no
  suggested-range affordance at all today (`MedicalCostQuestion` just asks
  the user to type a rupee figure) — `reference_costs`' `healthcare/
  ongoing_medical_annual` row is cached and readable via
  `reference_costs_get.php` but has no UI consumer yet. Wiring a "typical
  range: ₹X–Y/year" hint onto that question is a small, low-risk follow-up,
  not attempted in the reconciliation pass above since it's net-new UI
  rather than resolving a duplicate.
