# HorizonPlan — Product Vision & Differentiation Plan

**Status:** strategy + build-sequencing doc. Companion to `02` (spec), `04` (roadmap),
`05` (validation review), `06` (Phase 2 implementation plan). This document does not
replace the phase gates in `04` — it sharpens *what* gets built inside them and *why*,
based on an honest audit of where the product stands after Phase 1 + the template system.

---

## 1. The thesis: sell the meeting, the follow-through, and the record

Every Indian MFD platform (Investwell, IFA-Planet, JezzMoney, theMFBox) sells
**back-office plumbing**: transactions, reconciliation, AUM reports. HorizonPlan should
not compete there — `04` already made that call correctly. But "we have a sequence-risk
chart" is a *feature*, not a product thesis. The thesis that turns the current build into
a superb product is:

> **An advisor's business is won in a 45-minute client meeting, kept through disciplined
> annual reviews, and defended by the paper trail. HorizonPlan should be the best tool in
> India at all three — the meeting, the follow-through, and the record.**

Everything below maps to one of those three jobs. Anything that maps to none of them
(transactions, KYC, CRM) stays out, exactly as `04` says.

| Job | What the advisor fears | What we ship |
|---|---|---|
| **The meeting** | Losing the client's attention/trust in the room | Meeting Mode, historical replay, readiness score |
| **The follow-through** | 200 clients, no system for who needs review | Review Engine: snapshots, drift alerts, "needs attention" queue |
| **The record** | SEBI scrutiny, mis-selling accusations | Compliance Vault: exportable assumption + disclosure history |

---

## 2. Honest audit — where the current product is "basic"

Naming the gaps precisely, because each one becomes a bet below:

1. **Templates are decorative.** The Phase 1 template system (create/fork/customize/audit)
   stores allocations and return assumptions — but *nothing applies a template to a goal*.
   `template_audit_log` has a `used_in_plan` action that no endpoint ever writes. The
   library is a filing cabinet with no connection to the planning math. This is the single
   most important loop to close.
2. **The differentiator is synthetic.** The adverse-sequence chart reorders a made-up
   return series. It's honest and explainable — but "here is your plan if you had retired
   in January 2008" lands emotionally in a way "here is a reordered synthetic sequence"
   never will. The math stays deterministic either way.
3. **No number a client can hold onto.** The projection chart is for the advisor to
   narrate. Clients remember one number. We don't give them one.
4. **No reason to return.** After a plan is built, the product offers the advisor nothing
   until the client asks a question. Retention SaaS needs a Monday-morning reason to log
   in. We have none.
5. **The report is English-only and generic.** The volume segment (`02` §0) is tier-2/3
   MFDs whose clients often don't read English comfortably. `04` parks language support in
   Phase 4; for the *client-facing report only*, that's too late.
6. **The audit trail is write-only.** `change_log` faithfully records everything and shows
   none of it to anyone. Compliance data that can't be exported is a liability ledger, not
   a selling point.

---

## 3. The seven bets

Each bet: what it is, why it wins in this market, build cost on this stack, compliance
posture, and where it sits against `04`'s gates. All of them are deterministic arithmetic
within Hostinger's 30s budget — nothing here needs Monte Carlo or breaks `02` §2/§3.5.

### Bet 1 — Close the template loop: templates must move numbers
**What:** `goals_apply_template.php` — applying a template (or customization) to a
retirement goal writes its `return_assumption_pct` into the goal's
`drawdown_return_rate` (and `accumulation_return_rate` when Phase 2 adds it), records
`used_in_plan` in `template_audit_log`, writes `change_log` rows, and respects the
existing `is_overridden` cascade rules. Store `applied_template_id` /
`applied_customization_id` on `base_plans` so provenance flows *into* the plan, and usage
counts on the library become real numbers instead of zeros.

**Approval gate (from `06`, guardrail 2 — now enforced in data):** add an approval state
to `template_strategies` and `template_customizations` (`draft` / `approved`, with
approver + timestamp). An unapproved template can be browsed and forked but **cannot be
applied to a real client's goal**. Seeded global templates ship as `draft`. This is what
makes the template system compliance-grade instead of a toy: the firm's principal signs
off on the numbers before they touch a client plan, and the audit log proves it.

**Why it wins:** every competitor has "model portfolios" as static PDFs. A template that
applies in one click, cascades through the inheritance engine, and carries a signed
approval trail is a genuinely better mechanism — and it's 80% built already.
**Cost:** one endpoint, two small migrations, one button in the UI. **Do this first.**

### Bet 2 — Historical sequence replay: "What if you retired in 2008?"
**What:** a `market_history` table (year, Indian equity total return %, CPI %) seeded from
published public index/CPI data, clearly labelled as historical illustration. `PlanMath`
gains `historicalSequenceSeries(startYear)` — same O(horizon) loop, but the return and
inflation for year *n* come from the actual historical series starting at the chosen year.
UI: alongside "steady" and "adverse sequence," a third option — *"Replay history from…"*
with a handful of meaningful start years (1994, 2000, 2008, 2011, 2020).

**Why it wins:** `05`'s competitive scan found nobody models forward-looking withdrawal
survivability at all. This deepens the moat where it already exists: the competitor gap
goes from "they don't have the chart" to "they don't have the chart *and* we can replay
the client's actual worst fear." It is still deterministic, still explainable in one
sentence ("this line is what actually happened to markets from 2008"), still not a
prediction. **Compliance:** historical-illustration labelling + the §3.6 disclosure
adjacent to the control, same as the existing chart. **Cost:** one data table, ~30 lines
in PlanMath, one dropdown.

### Bet 3 — Retirement Readiness Score (0–100)
**What:** one deterministic, transparently-computed number per retirement goal, from
inputs we already have: (a) corpus multiple vs. the Indian-calibrated target band,
(b) how many of the horizon years the steady projection survives, (c) how many the
adverse/historical-worst sequence survives. Formula published in-app (a "how is this
calculated?" popover) — transparency is the compliance posture. Weights live in data,
not code, so a future per-tenant calibration doesn't need a rebuild.

**Why it wins:** clients don't remember charts; they remember "I'm at 68, and last year I
was at 61." The score is what makes annual reviews a *story* and gives Bet 4 something to
track. **Compliance:** framed as an illustration score with distribution-mode copy — it
measures the plan's arithmetic, it does not rate products or recommend actions.
**Cost:** pure PlanMath + UI; no schema.

### Bet 4 — The Review Engine: the Monday-morning queue
**What:** the retention moat. A `plan_snapshots` table written monthly by a Hostinger
cron script (the first real use of `02` §3.5 infrastructure, sized well within budget):
per goal, the assumption set + readiness score at that date. On top of it, a
**"Needs attention"** queue on the advisor dashboard:
- score dropped more than X points since last snapshot,
- no plan review recorded in 12 months,
- goal target date within 24 months,
- scenario overridden and untouched for 6+ months.

Plus a one-click "mark reviewed" that writes to `change_log` — which feeds Bet 7.

**Why it wins:** an MFD with 200 clients has no system for review discipline; this is the
single most commonly named practice-management failure. None of the four scanned
competitors do plan-level drift detection — their alerts are transactional (SIP bounced),
not planning-level (your assumptions went stale). This is what makes HorizonPlan a
weekly-use tool instead of a plan-creation tool. **Cost:** one table, one cron script, one
dashboard panel. All queries via `TenantScopedDb`.

### Bet 5 — Meeting Mode
**What:** a full-screen, keyboard/tap-advance guided presentation assembled from
components that already exist — four steps: *your goals → what inflation does → will the
corpus survive → what a bad early decade does (replay) → your readiness score*. White-label
branding front and center, §3.6 disclosure on every screen, optional advisor speaking
notes per step.

**Why it wins:** this is what sells in a demo, and it's what the advisor actually does
with the product — the entire Phase 1 differentiator was justified by "this is what gets
shown in a client meeting" (`04` Phase 1). Making the meeting a first-class mode instead
of scrolling a dashboard in front of a client is cheap and dramatic. **Cost:** almost
entirely frontend composition; zero schema, zero endpoints.

### Bet 6 — Vernacular client report + WhatsApp handoff
**What:** scope discipline is the whole trick here. NOT full app i18n (`04` Phase 4 is
right that that's later). Only the **client-facing report** (`PlanReport.jsx`) gets a
string table — Hindi first, then one or two regional languages chosen by early-tenant
geography. Advisor picks the client's language per report. Plus a share-ready A4 PDF and
an image summary card, and a `wa.me` deep-link share (no WhatsApp Business API dependency;
that stays a logged Phase 2+ item per `04`).

**Why it wins:** the buyer (`02` §0) is the tier-2/3 MFD; their client's spouse and
parents read the report in Hindi/Marathi/Gujarati or not at all. Every competitor lists
"WhatsApp" as marketing; a genuinely vernacular client artifact is rarer and matches the
actual demographic. **Compliance:** translated disclosure strings are compliance copy —
translate once, review with the RIA partner, version them (see Bet 7).

### Bet 7 — Compliance Vault
**What:** turn the write-only audit trail into a sellable artifact: a per-client
exportable record (print-view first, PDF later) assembling what `change_log` +
`template_audit_log` already know — every assumption change with old/new values and who
made it, every template application with its approval state at the time, every "marked
reviewed" event, and **which version of the disclosure copy the client was shown** (add a
`disclosure_versions` table + a lightweight render-log; the strings are already treated
as compliance-relevant per `02` §3.6 — versioning them is the missing half).

**Why it wins:** SEBI's scrutiny of mis-selling to retirees is named in `05` as an active
pattern. No MFD tool sells *audit defense*. "If a client ever disputes what you told
them, here is the timestamped record of every assumption and disclosure" is a purchase
justification that lands with the firm principal, not just the advisor — and ~80% of the
data is already being captured. **Cost:** mostly a read-side report; one small table.

---

## 4. Sequencing — mapped onto the existing gates

`04`'s validation-gate discipline stands. This orders the bets *inside* it. Each session
stays small and focused per `CLAUDE.md`.

| Session | Builds | Rationale |
|---|---|---|
| **A (next)** | Bet 1 (apply-template + approval state) + Bet 3 (readiness score) | Both small; Bet 1 makes the just-shipped template system real, Bet 3 needs no schema. Together they make the existing product feel finished. |
| **B** | Bet 2 (historical replay) + Bet 5 (Meeting Mode) | The demo-day pair: deepens the differentiator and packages it for the room. |
| **C** | Phase 2 core per `06` (accumulation, risk profiler) | Unchanged — `06` already plans this well; the risk profiler feeds the Vault. |
| **D** | Bet 4 (Review Engine) | Needs real tenants with plans old enough to drift; first cron infra. |
| **E** | Bet 6 (vernacular report) + Bet 7 (Compliance Vault) | Vault after the risk profiler exists so the record is complete; languages chosen from actual early-tenant geography. |

**Gate check before Session D/E:** the `04` Phase 1 exit gate (real MFD tenants using the
projection with real clients) should be met or clearly in progress. Bets 4–7 are
retention/record features — they only pay off with real usage to retain and record.

---

## 5. What this plan still refuses to build

Unchanged from `02` §6 and `04`, restated because ambition is when discipline matters most:

- No Monte Carlo before Phase 3's gate — the deterministic replay (Bet 2) is deliberately
  the strongest thing short of it.
- No tax-optimized withdrawal sequencing until the RIA partner has endorsed a specific
  Indian-market spec.
- No transaction execution, KYC, CRM, AUM reconciliation — that's the incumbents' moat,
  not ours.
- No self-serve advisory mode, ever. No suitability language for distribution-mode
  tenants — the readiness score and templates are illustrations there, and the approval
  state (Bet 1) exists precisely so numbers a client sees were signed off by a human
  professional.
- No speculative per-tenant configurability (score weights, glide-path editors) before a
  tenant asks — data-not-code is the design, shipping the editor can wait.

---

## 6. Success metrics — how we'll know it's superb, not just shipped

| Bet | Metric that proves it |
|---|---|
| 1 | % of new retirement goals created with a template applied; approval-before-use rate = 100% by construction |
| 2, 5 | Plans *presented* (Meeting Mode sessions / historical replays run) per advisor per month |
| 3 | % of client reports where the score appears; score deltas discussed at review |
| 4 | % of active plans reviewed in the last 12 months (the number an MFD can't state today) |
| 6 | % of reports generated in a non-English language; report shares per plan |
| 7 | Vault exports per tenant per quarter; mentioned in sales conversations as a close reason |

The single north-star: **plans reviewed per advisor per month** — it's the one number
that proves the product is the meeting, the follow-through, and the record, not a
calculator that gets opened once per client.
