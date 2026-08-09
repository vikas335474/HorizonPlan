# 12 — HorizonPlan: Dynamic Portfolio, Tax Context & the Alerts Engine

The self-serve tiers (docs/11) gave an individual a plan they can build alone.
This set makes two different things true:

1. The **inputs** a plan rests on — what someone actually owns — become
   complete, clear to enter, and honest about what an import does.
2. The **outputs** stop being static. A goal's target moves as prices move; a
   dashboard surfaces what changed since the person last looked; the tax
   treatment of what they hold is stated plainly next to it.

None of it crosses the line HorizonPlan defends on purpose. Read `docs/07` for
the product thesis, `docs/10` for the competitive framing this sits inside, and
`docs/11 §5` for the advice-line guardrail that governs the tax work below.
`docs/02` still governs *how* anything here is built.

---

## 1. What this set is, and the one line it does not cross

HorizonPlan is the **planning and conversation layer**, not the transaction
back-office (docs/10 §1). This set deepens the planning side — better inputs,
live outputs, factual context — and deliberately stops short of three things
that would turn it into something else:

- It does **not** compute a tax *liability* to file, or generate an STCG/LTCG
  statement. That is docs/10 P2-2, and it depends on a real cost-basis feed
  (P2-1) we don't have.
- It does **not** tell anyone to *sell*, *harvest*, or *withdraw in a
  particular order*. That engine is explicitly gated to advisory-mode tenants
  in `docs/04` ("the feature most likely to read as advice"), and the
  decide-then-build call on this set (below) is **facts-only, for everyone** —
  the actionable engine is documented here as a deferred, gated bet, not built.
- It does **not** auto-pool a client's portfolio into any goal's corpus. A
  goal's corpus is the goal's own recorded figure (docs/02 §4.1); the portfolio
  is reference data an advisor or individual pulls *from*, never an automatic
  binding.

Everything below ships the **mechanism and the sourcing** and leaves the
judgment with the person — the same posture as `FinancialFoundations.php`,
`lib/strategyPresets.js`, and the `reference_costs` library.

### Decisions taken (confirmed with the user via `AskUserQuestion`)

- **Tax: facts only, for everyone.** Surface the *applicable treatment* per
  instrument and *factual* figures (realised/unrealised gain, LTCG headroom)
  where the data exists — never a "sell X now". Safe for self-serve,
  distribution, and advisory tenants alike.
- **Import: reconcile by holding.** Match on scheme/folio, update existing, add
  new, flag missing — in a preview, before anything changes. No more silent
  duplication on re-import.
- **Build now:** all three of portfolio-input overhaul, the alerts/rules
  engine, and goal-target re-evaluation. The gated actionable-tax engine is
  planned here but deferred.

---

## 2. Design principles (these govern every prompt below)

Carried straight from `CLAUDE.md`'s non-negotiables and `docs/11 §2`:

1. **Facts, not advice.** Any tax figure is a *treatment* ("equity held >12mo
   is LTCG; gains above the annual exemption are taxed at the LTCG rate") or an
   *illustrative* computation from the person's own recorded numbers — never a
   recommendation to act. If it would read as "you should…", it does not ship
   in this set.
2. **Sourced, and flagged until verified.** Tax rates and thresholds change with
   every budget. Every external figure follows the `market_history` /
   `reference_costs` precedent: a named source, an `as_of` date, and an
   `is_verified` flag that starts **0** until a human confirms it against the
   authority. The UI shows "illustrative — verify" until it's flipped. A
   financial tool must never present a stale tax rate as authoritative.
3. **Never fabricate a figure.** `not_recorded` stays distinct from a real
   value everywhere (the `FinancialFoundations.php` worked example). No cost
   basis → no gain figure, stated as "not recorded", never a zero or a guess.
4. **The cache is read in-request; the cron writes it.** No live outbound call
   inside a request (Hostinger can't support it anyway) — the
   `tools/mf_nav_sync.php` → `mf_nav_cache` pattern is the law here.
5. **Alerts surface a fact or a nudge, never an instruction.** "Goal X reached
   its target corpus" is a fact. "Your equity has ₹40k of LTCG headroom left
   this year" is a fact. "Sell to use it" is advice — out.
6. **Same engine, both audiences, scope differs.** Everything an individual
   sees about their own plan, an advisor sees across their book — the exact
   persona-aware pattern the dashboard already uses (`clients_list.php`'s
   book-wide attention count vs. the individual's own goals). No parallel
   second implementation.

---

## 3. Where things stand today (so we build on, not beside)

| Capability | Today | This set changes |
|---|---|---|
| Portfolio ledger | `client_portfolio_items` (asset/liability, liquid/locked, net worth on read). Categories exist: MF, stocks, FD, savings, gold, PPF, EPF, NPS, real_estate, + liabilities. | Add **bonds, REITs, cash**; make the existing (buried) categories discoverable; attach tax-treatment context. |
| CAS/CSV import | `client_portfolio_import.php` — **always inserts** (re-import duplicates everything), **mutual-fund only**. | **Reconcile by holding** (update/add/flag-missing + preview); a real "how to export your CAS" guide. |
| Price freshness | Daily NAV cron (`mf_nav_sync.php` → `mf_nav_cache`) on MF holdings; manual "Refresh prices" reads the cache. | Feeds the **stale-price alert** and the **moving goal target**. |
| Goal tracking | `targetGoalFunding()`, `readinessScoreForGoal()`, `progressStatus()` (±5% dead zone), `goal_snapshots`. | Surface a **live moving target** + a clean **"goal met"** state that re-evaluate as prices move. |
| Dashboard signals | Attention queue, "behind plan" badge, book-health bar, practice analytics — mostly **read-time, per-page**. | A unified **alerts engine** makes them a first-class, dynamic surface on both dashboards. |
| Tax | **Absent** (docs/10 §51). | **Facts-only** treatment context + illustrative gain/headroom where cost basis exists. |

---

## 4. Sequenced prompts

Each is standalone and runnable in a fresh session, in this repo's
**decide-then-build** style: confirm the open choices with `AskUserQuestion`
*before* writing a migration. Dependency order is D-1 → D-2 → D-3 → D-4; D-4
(the engine) reads the signals the others produce plus the existing
foundations/progress signals.

---

### Prompt — D-1 · Portfolio input overhaul (reconcile-by-holding + full instruments + discoverability) — BUILT

> **Adds columns + rewrites the import path. The foundation — everything
> downstream reads the portfolio. Do first.**
>
> **Status: built** (sql/038). The `AskUserQuestion` decide-first step below
> got interrupted mid-session; building proceeded on the strongest reasoned
> default for each open choice, with one deliberate deviation from this
> prompt's own suggested wording — recorded here rather than silently:
>
> - **Match key: folio number alone (not "AMFI scheme code + folio")**, falling
>   back to a normalised scheme name. On inspection, a real CAS/KFintech/
>   MFCentral export carries a folio number, not an AMFI scheme code (that
>   code is specific to this app's own `mf_nav_cache`/AMFI NAV-file
>   integration) — so "AMFI code + folio" was never a mappable pair of CSV
>   columns in practice. Folio alone is in fact the *more* robust key than
>   this prompt's original "folio + description" framing would have been: it
>   deliberately ignores the description entirely, so a fund's display name
>   drifting slightly between two statements (a plan-suffix change, an
>   AMC rename) can't break a match — see `PortfolioReconcile.php`.
> - **Buckets:** bonds and REITs/InvITs both liquid, `cash` liquid and
>   distinct from `savings` — as recommended.
> - **Reconciliation scope:** `client_portfolio_items.source`
>   (`'manual'|'cas_import'`, `NOT NULL DEFAULT 'manual'`) — as recommended,
>   and backfilling every pre-migration row to `'manual'` needed no separate
>   step (every row before this migration genuinely was hand-entered; the
>   old import path never tagged anything either).
>
> Shipped exactly the "likely shape": `client_portfolio_reconcile_preview.php`
> (read-only diff) → `client_portfolio_import.php` (rewritten to recompute
> the diff server-side rather than trust a client-submitted one, and apply
> it in one transaction). No auto-delete path exists anywhere — removal
> requires both a user-ticked checkbox in the preview AND the server's own
> fresh recomputation still flagging that same id (`confirmedPortfolioRemovalIds()`,
> proven live over HTTP: a request that snuck a manual holding's id into
> `remove_ids` had it silently dropped). The instrument/discoverability piece
> (b) and the guided-import copy/steps (c) both shipped as scoped. Verified
> per repo convention: real MariaDB (`sql/038` applied cleanly), full
> `tests/run_all.sh` (two new files — pure diff logic, DB-level source/tenant
> scoping), a real import → re-import HTTP cycle proving update-not-duplicate
> and correct missing-holding flagging, and a real Playwright run through
> upload → guide → configure → reconcile preview → confirm.
>
> Three problems, one session:
>
> **(a) Import reconciles, it no longer duplicates.** `client_portfolio_import.php`
> today forces `mutual_fund`/`liquid`/`asset` and *inserts every row*, so a
> second CAS upload silently doubles the portfolio. Rewrite to **match on a
> stable key** and, in a preview the user confirms before any write: update the
> value of a matched holding, insert a genuinely new one, and **flag** a holding
> that's in the app but absent from the file — never silently delete it (a plan
> input must not vanish on the user; "flag" means surface it as "not in this
> statement — keep, or remove?", the removal always an explicit click).
>
> **(b) The instrument set is completed and surfaced.** Add **bonds, REITs, and
> cash** (distinct from `savings`). The existing categories (EPF, NPS, gold, FD,
> real_estate, PPF) already exist but are buried in a flat dropdown — group them
> by asset class so a user discovers "yes, my PF and my flat belong here".
>
> **(c) Entering it is guided.** A step-by-step "how to get your CAS" (CAMS /
> KFintech / MFCentral, where to download, that it's a CSV export) and a plain
> statement of *what import does* ("we'll match by folio and update values;
> nothing is deleted without you confirming").
>
> **Decide with the user first (`AskUserQuestion`):** (1) the **match key** —
> AMFI scheme code + folio where present (exact), falling back to normalised
> description (fuzzy, riskier)? (2) the **bucket** for the new instruments —
> bonds and REITs are liquid/market-linked, cash is liquid; confirm bonds
> aren't wanted as a locked sub-type. (3) whether reconciliation is **scoped to
> import-sourced rows only** (a `source` marker on the row) so a hand-entered
> holding is never touched by a CAS reconcile — recommended, and it needs a
> nullable `source` column.
>
> **Likely shape.** A nullable `client_portfolio_items.source`
> ('manual'|'cas_import') + the new category enum values; the import endpoint
> returns a **diff** (to-update / to-add / to-flag) the frontend previews, then
> a confirm call applies it in one transaction. No auto-delete path anywhere.
>
> **Verify** per repo convention: real MariaDB, `tests/run_all.sh`, a real
> import → re-import cycle proving the second upload updates rather than
> duplicates, and a Playwright run through the guided flow. **What NOT to
> build:** a live RTA/Account-Aggregator feed (P2-1, separate), or any
> auto-deletion of a holding the statement omits.

---

### Prompt — D-2 · Per-instrument tax-treatment context (facts only) — BUILT

> **Adds a sourced tax-reference cache (+ optional cost-basis fields). Do after
> D-1.**
>
> **Status: built** (sql/039). All three decide-first choices went with the
> recommended default: `tax_reference` as a table+cron (`tools/tax_reference_sync.php`,
> matching `reference_costs` exactly), cost-basis capture built alongside the
> treatment labels in this same session, and a new `client_portfolio_items.fund_type`
> (`equity`/`debt`/`hybrid`) field — an open question this prompt's own text
> didn't originally call out, added because a generic "mutual fund" category
> cannot get one correct tax note: equity funds keep a concessional LTCG
> rate, debt funds have been taxed entirely at slab rate with no LTCG
> concept since April 2023. An unset `fund_type` shows all three regimes
> side by side, never a guess (`PortfolioTaxContext.php::resolveTaxSubcategories()`).
>
> **One deliberate scope correction from this prompt's own wording, recorded
> rather than silently narrowed:** "(b)" below says "how much LTCG exemption
> headroom remains this year" — that figure needs every realised gain from
> every sale in the financial year, and this app has no transaction/sale
> ledger, only a snapshot of current holdings. What's actually buildable and
> shipped is an **illustrative UNREALISED gain** (current value − recorded
> acquisition value) on a still-held position, plus whether it would count
> as short- or long-term if sold today — never a claim about exemption
> already used or remaining. See `api/lib/PortfolioTaxContext.php`'s header
> for the full reasoning; a fabricated headroom number would be worse than
> no number at all (docs/12 §2 principle 3).
>
> 14 categories seeded: mutual_fund (×3 fund types) / stocks / bonds / reits
> / gold / real_estate / savings / fd / cash / ppf / epf / nps. Every row
> `is_verified = false` — this is a good-faith, no-live-internet-access
> summary of Indian capital-gains/income-tax rules as of the 2023/2024
> budgets, not fetched from a live source, and must be checked against
> current Finance Act/CBDT guidance before a real client meeting leans on an
> exact rate. Surfaced on `ClientPortfolioCard` as a collapsed-by-default
> "Tax context" panel per asset row (never on liabilities — out of scope),
> for both advisor and self-serve individual sessions alike, always opening
> with "Illustrative, not tax advice — always verify against current rules."
>
> Verified: real MariaDB (`sql/039` applied cleanly, `source_name` widened
> to VARCHAR(500) mid-build after a real Finance-Act citation string
> exceeded 255 chars), full `tests/run_all.sh` (three new/extended test
> files — pure holding-period/gain math, dataset+sync/prune DB coverage,
> and `client_portfolio_items` schema coverage), a real HTTP round trip
> through four scenarios (resolved equity fund with full gain, no-cost-basis
> stock, unresolved mutual fund showing all three regimes, an `other_asset`
> row correctly reporting `applicable: false`), and a real Playwright run
> confirming all of the above render correctly, including the add-item
> form's new fund-type/purchase-price/purchase-date fields.
> Attach, next to each holding, the **applicable tax treatment** — never a
> filing figure, never "act now". Two layers, deliberately separable:
>
> **(a) Treatment labels — no new per-holding data.** A sourced reference of how
> each instrument category + holding period is taxed (e.g. listed equity / equity
> MF: STCG vs LTCG at the >12mo line and the annual exemption; debt MF post-Apr-2023:
> slab; REITs, bonds, gold, real_estate: their own rules). Built as a cache the
> same shape as `reference_costs` (a `tax_reference` table or a
> `PersonalisationReference`-style code constant behind a CLI sync), every row
> carrying `source_name` / `as_of_date` / `is_verified` — **seeded is_verified=0**,
> because tax rules move every budget and this tool must never show a stale rate
> as authoritative. Renders as "illustrative — verify against current rules".
>
> **(b) Illustrative gain + LTCG headroom — needs cost basis, so it's opt-in.**
> To state a *realised/unrealised gain* or *how much LTCG exemption headroom
> remains this year*, the app needs **acquisition cost + date**, which today's
> portfolio rows don't carry. Add them **nullable, no backfill**; when present,
> show the illustrative gain and the remaining-headroom fact; when absent, show
> the treatment label only and say "add purchase details to see your gain" —
> never a fabricated zero.
>
> **Decide with the user first:** (1) `tax_reference` as a table+cron (matches
> E-3's `reference_costs` exactly) vs. a code constant (matches
> `PersonalisationReference`) — recommend the table+cron, since tax rates are
> precisely the kind of figure a non-deploy human update should be able to
> correct. (2) Whether cost-basis capture is in this prompt or its own — recommend
> here, since (b) is the payoff. (3) The exact instruments to cover in the first
> seed.
>
> **Likely shape.** `tax_reference` (category, holding-period band, rate note,
> exemption note, source, as_of, is_verified) + `tools/tax_reference_sync.php`
> following `mf_nav_sync.php` exactly; nullable `acquisition_value` /
> `acquisition_date` on `client_portfolio_items`; a pure helper computing the
> illustrative gain/headroom (no DB, unit-tested). **Verify:** real DB +
> `tests/run_all.sh` + a holding with and without cost basis rendering the two
> states correctly. **What NOT to build:** a capital-gains *statement* or filing
> figure (P2-2), any indexation/edge-case engine, or a "harvest now" prompt.

---

### Prompt — D-3 · Goal target re-evaluation (moving target + "goal met")

> **Mostly surfacing existing math. Do after D-1 (needs the completed
> portfolio) — can run parallel to D-2.**
>
> A goal's target and how today's money tracks it should **move as prices move**,
> not sit frozen at the number last typed. The math largely exists
> (`targetGoalFunding()` inflates a target to its date; `readinessScoreForGoal()`;
> `progressStatus()`'s ±5% dead zone). The new work is (a) recomputing the
> "corpus/target needed by date" against **current** NAVs/prices, and (b) a clean,
> explicit **"goal met"** state on the goal page and dashboard.
>
> **Decide with the user first — this is the crux:** *what counts as a goal's
> "current corpus"?* The guardrail (docs/02 §4.1) says the portfolio is **not**
> automatically any goal's corpus. So re-evaluation must run on either the goal's
> **own recorded corpus/plan inputs** (safe, unchanged binding) or an
> **explicit, user-made link** from a goal to specific portfolio holdings (a real
> feature, opt-in, never auto-matched — same "explicit `goal_id` link, never
> auto-matched" precedent E-2 set for dependants↔education goals). Recommend:
> ship the own-recorded re-evaluation first (no new binding, immediate value from
> the NAV cron already moving those numbers), and offer the explicit
> goal↔holdings link as the follow-on.
>
> **Likely shape.** A pure re-evaluation helper over existing `PlanMath`
> (target inflated to date, current coverage, met/not-met with a dead-zone so it
> doesn't flicker at the boundary); a "goal met" surface on `GoalDetail.jsx` and
> a dashboard badge; if the explicit link is chosen, a nullable join with an
> opt-in picker. **Verify:** real DB + `tests/run_all.sh` proving the re-evaluated
> target equals a hand-computed inflation-to-date, that "met" latches correctly
> across the dead zone, and a Playwright run. **What NOT to build:** auto-pooling
> the portfolio into a goal (violates §4.1), or a "you've over/under-saved, do X"
> recommendation.

---

### Prompt — D-4 · The alerts / rules engine (what makes the dashboards dynamic)

> **The unifying surface. Do last — it reads D-1..D-3's signals plus the
> existing foundations/progress ones.**
>
> A single engine that computes **typed, factual triggers** and surfaces them on
> both dashboards. Candidate triggers, all facts or nudges, none instructions:
> *goal reached its target corpus* (D-3), *goal drifted beyond the ±band*
> (existing `progressStatus`), *a tracked price is stale / "price pending"*
> (the NAV cron's own signal), *a scheduled review is due* (existing
> `plan_review_schedules`), *LTCG exemption headroom remains this year* (D-2, a
> fact — never "so sell"), *a foundations gap* (existing `FinancialFoundations`:
> reserve short, cover unrecorded, debt costlier than the plan's return). Each
> alert is a `{type, severity, subject, factual_message, deep_link}` — the
> message states the fact, the link goes to the page to act on it, the app never
> says what to do.
>
> **Same engine, both audiences (principle 6):** an individual sees their own
> alerts; an advisor sees them **rolled up across the book** with per-client
> attribution, exactly like `clients_list.php`'s firm-wide attention count. No
> second implementation.
>
> **Decide with the user first:** (1) **stateless vs. stateful** — are alerts
> derived fresh on read (simplest, always current, no dismiss), or persisted with
> an **acknowledge/snooze** state (an advisor working a book wants to mark "seen")?
> Recommend a **hybrid**: compute the current set on read, persist only the
> *acknowledgement* (a small `alert_ack` table keyed by type+subject+as_of) so a
> dismissed alert stays quiet until its underlying fact changes. (2) **Thresholds:**
> reuse the existing sourced ones (the ±5% drift dead zone, foundations' reference
> points) and default any *new* firm-configurable threshold **OFF**, per
> guardrail-style caution — ship the mechanism, not an opinion. (3) **Cron vs.
> read:** time-based triggers (review due) ride a cron like the existing ones;
> state-based triggers compute on read (cheap). No new live outbound.
>
> **Likely shape.** A pure `AlertsEngine` helper (given a client's already-loaded
> plan/portfolio/foundations/progress state → a typed alert list, fully
> unit-testable, no DB); an endpoint per audience (individual: own; advisor:
> book roll-up, tenant-scoped); an optional `alert_ack` table; a dashboard
> alerts panel replacing the ad-hoc attention banner with the unified set.
> **Verify:** real DB + `tests/run_all.sh` for the pure engine's every trigger,
> tenant-isolation on the advisor roll-up, and Playwright on both dashboards.
> **What NOT to build:** an alert whose action is transactional, a campaign/drip
> engine (docs/10 anti-scope), or any trigger that phrases a fact as an
> instruction.

---

## 5. Where I would not go, and why (this set's advice line)

**A tax liability to file.** We are a planning tool, not a return preparer. An
STCG/LTCG *statement* (docs/10 P2-2) needs authoritative cost basis from an RTA
feed (P2-1) we don't have; computing one from partial data and presenting it as
filing-grade would be both wrong and out of category. We show *treatment* and,
only from the user's own recorded cost basis, an *illustrative* gain — flagged
as illustration, exactly like every other sourced figure here.

**"Harvest now / withdraw from X first."** The tax-optimized withdrawal-*sequencing*
engine is the single feature `docs/04` singles out as most likely to read as
advice, and gates to advisory-mode tenants only. The decision on this set is
**facts-only for everyone**; the actionable engine is documented as a deferred,
**advisory-mode-gated** bet (see below), not built. Surfacing the *headroom*
fact is fine; recommending the *action* is the line.

**Auto-binding the portfolio to a goal.** docs/02 §4.1 is explicit — a client's
assets are not automatically any one goal's corpus. Re-evaluation (D-3) runs on
the goal's own inputs or an *explicit, opt-in* link, never an automatic pool.

**A CRM/campaign engine.** docs/10 anti-scope §5. The alerts engine surfaces
*planning* facts on the dashboard; it is not birthday/KYC/marketing automation.

**A live RTA/Account-Aggregator feed.** P2-1, a separate large bet with its own
credential/consent surface. This set stays on manual + CAS-CSV + the NAV cron.

---

## 6. Deferred, on the record (decide before building)

- **Actionable tax engine (advisory-mode-gated).** Harvest-timing prompts and
  tax-optimized withdrawal ordering — the docs/04 feature. Only meaningful with
  a human fiduciary in the loop (advisory tenants), and only worth building on
  top of D-2's facts layer + real cost basis. A deliberate, separate decision.
- **Capital-gains / tax statement (P2-2).** Depends on accurate cost basis,
  which realistically depends on the live RTA feed (P2-1).
- **Explicit goal ↔ holdings link (D-3 follow-on).** Lets a goal re-evaluate
  against specific tracked holdings rather than its own recorded corpus — opt-in,
  never auto-matched, per the §4.1 guardrail.
- **Push notifications for alerts.** The engine (D-4) produces the signals; a
  delivery channel beyond the in-app dashboard (email digest reusing `Mailer`,
  or PWA push once the PWA lands, docs/10 P1-2) is a later, separate step.

---

## 7. Migration / cron footprint (so deploy stays predictable)

New migrations this set would add (numbers assigned at build time, after the
current highest in `/sql`): `client_portfolio_items.source` +
`acquisition_value`/`acquisition_date` + new category enum values (D-1/D-2), a
`tax_reference` table (D-2), an optional `alert_ack` table (D-4), and — only if
the explicit link is chosen — a goal↔holdings join (D-3 follow-on). New crons:
`tools/tax_reference_sync.php` (D-2, infrequent — tax rules change per budget,
not daily), following `tools/reference_costs_sync.php` /
`tools/mf_nav_sync.php` exactly (CLI-only, logic in `api/lib/`, failure leaves
the cache untouched, prune stale rows). Everything is **nullable, no backfill,
default-off** per the non-negotiables. Document each in `DEPLOY.md` alongside
the existing NAV / reference-cost / plan-review crons.
