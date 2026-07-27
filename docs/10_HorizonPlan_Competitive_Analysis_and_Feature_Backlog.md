# 10 — HorizonPlan: Competitive Analysis & Prioritized Feature Backlog

**Purpose:** an honest, unbiased read of where HorizonPlan sits against the best-known Indian MFD/RIA software, what those tools do better, what HorizonPlan does better, what real users complain about, and a prioritized backlog of value-adds. Read this before deciding *what to build next* — `docs/08` covers demo readiness; this covers competitive position.

> **Sourcing discipline (read first).** Every external claim below is tagged **[sourced]** (from a cited vendor/review page), **[market-general]** (from an aggregate/industry summary, not a single vendor's official figure), or **[inference]** (a reasoned conclusion, not a quoted fact). Where a competitor's pricing or feature is not publicly disclosed, it says so — it is **not** guessed. HorizonPlan's own capabilities are tagged **[verified]** (present in this repo's endpoints/pages as of this writing) so the comparison isn't self-flattering. This file was written in July 2026; **re-verify competitor pricing before quoting it to anyone** — vendor pricing changes and several figures below are AUM-tier-dependent.

---

## 1. The category distinction that frames everything

The most important honest point: **HorizonPlan is not in the same category as the famous Indian MFD platforms — it overlaps with only one slice of them.**

The well-known tools (Wealth Elite, Investwell, NJ Wealth, Fintso, and peers like AssetPlus, IFA-Planet, Mindstack) are **transaction back-office + CRM + portfolio-tracking platforms**. Their core job is: onboard an investor (Video KYC), *place and route real orders* (BSE StAR MF / MFU / NSE NMF-II), *track live folios* via CAMS/KFintech RTA feeds, reconcile *brokerage/commission*, and generate *capital-gains and portfolio statements*. Planning tools (goal calculators, "Goal GPS") are usually a **secondary module** bolted onto that transaction spine.

HorizonPlan is a **planning-and-conversation tool**: goal/retirement modelling, the sequence-of-returns story, the client meeting, and the record of what was shown. It deliberately does **not** execute transactions, do KYC, or reconcile commissions. **[verified]** — there is no order-routing, KYC, or brokerage endpoint in the codebase.

So the honest framing for positioning is:

- HorizonPlan is **not a replacement** for a distributor's back-office platform. A working MFD still needs one of the big tools to actually transact.
- HorizonPlan is a **complement / wedge** on the axis those tools are consistently weakest on per user feedback: **planning depth and client experience** (see §4). It is *deeper and more modern* on the narrow thing it does, and *absent* on the broad transaction/back-office surface.
- The strategic question this doc informs: **do we stay a focused planning/conversation layer (and integrate with a back-office), or grow toward the transaction spine ourselves?** The backlog in §5 is ordered on the assumption we win first by being *unmistakably better at planning + the client relationship*, then decide on transactions separately (it's a large, regulated, separate build — see §5, P3).

---

## 2. The competitor landscape

| Platform | What it primarily is | Core strengths | Commercial model |
|---|---|---|---|
| **Wealth Elite** (REDVision) | White-labelled multi-asset MFD SaaS | Video KYC, online transactions, portfolio reporting & **re-balancing**, "Goal GPS" planning, BI dashboards, research, mobile app **[sourced: wealthelite.in]** | Paid SaaS; a "MF Elite" entry tier is offered to MFDs with **AUM < ₹25 Cr [sourced]**. Specific list price not publicly disclosed on the pages reviewed. |
| **Investwell Mint** | Cloud MFD/IFA/RIA back-office | Portfolio analytics (asset allocation, **capital-gains reporting**, SIP monitoring), built-in **CRM with automated alerts** (SIP renewal, KYC expiry, birthdays), used by 4,500+ distributors **[sourced: investwellonline.com]** | **AUM-based, single plan, all features. From ₹25,000/yr for AUM ≤ ₹25 Cr; +₹1,000/yr per additional ₹1 Cr above 25 Cr [sourced: investwellonline.com/pricing]** |
| **NJ Wealth** | National Distributor (ND) platform | Full transaction + reporting stack bundled with becoming an **NJ sub-broker/partner**; large partner network | **Different model:** tech comes as part of distributing under NJ's ARN/ND umbrella, not a standalone license you buy **[inference from ND model]** |
| **Fintso** | Integrated digital wealth PaaS for advisors | Platform-as-a-service to let advisors offer multi-product digital wealth; Series A, Mumbai (2019) **[sourced: crunchbase/tracxn]** | Public pricing **not disclosed** — contact-sales / partnership model **[sourced: sourceforge lists "starts at free" but no real tier detail]** |
| **AssetPlus / IFA-Planet / Mindstack / others** | MFD onboarding + transaction + reporting | Similar transaction/back-office feature sets, often aimed at newer/smaller MFDs | Varies; **market-general range ₹30,000–₹1,00,000/yr depending on features [market-general]** |

**Note on Fintoo:** it appears in searches but is an adjacent B2C/assisted-advisory product, **not** a core MFD back-office comparator — excluded from the head-to-head.

**Pricing takeaway:** the anchor data point is **Investwell's ₹25,000/yr AUM-tiered, all-features-included** model. That is the number to price *against* — it sets buyer expectations for "one predictable annual fee, everything included, scales with my book." **[sourced + inference]**

---

## 3. Feature comparison — where each side wins

### Where the big platforms are ahead of HorizonPlan (real, current gaps)

These are genuine absences in HorizonPlan today, **[verified]** by their absence from the codebase:

1. **Transaction execution** — no order placement/routing (BSE StAR MF / MFU / NSE NMF). The big tools let an MFD actually transact; HorizonPlan cannot.
2. **KYC / onboarding-to-invest** — no Video KYC or investor onboarding-to-transact flow.
3. **Live RTA portfolio tracking** — HorizonPlan tracks holdings via **manual entry + CAS/MFCentral CSV import + a daily NAV price cron** on MF holdings **[verified]**, *not* an automatic live folio feed from CAMS/KFintech. The big tools reconcile folios automatically.
4. **Commission / brokerage reconciliation & reporting** — absent. A core back-office function for distributors.
5. **Capital-gains / tax statements** (STCG/LTCG, ELSS proofs) — absent.
6. **CRM automation** — no birthday / KYC-expiry / SIP-renewal alerts, no campaign/drip automation. (HorizonPlan has an *audit log* and *client health signals*, not lifecycle CRM.)
7. **Native mobile app** — HorizonPlan is responsive web only **[verified]**; several competitors ship native apps (advisor + investor).
8. **Multi-asset breadth** — HorizonPlan models MF/retirement corpus; it does not track stocks/bonds/PMS/AIF/insurance as live products (only as manual ledger lines).
9. **Research / fund screener** with live data, and **model-portfolio rebalancing with execution.**

### Where HorizonPlan is ahead (its actual differentiation)

These are **[verified]** present and are genuinely deeper/more modern than the "Goal GPS"-style calculators bolted onto the big platforms:

1. **Retirement Readiness Score** — a single 0–100 number blending steady-return survival, adverse-sequence survival, and a corpus-multiple-vs-Indian-band check. A "number the client can hold onto," not a projection table.
2. **Sequence-of-returns risk, made visible** — steady vs. adverse-order chart from the *same* average return. Most calculators only show an average-return line; this is the risk spreadsheets hide.
3. **Real historical sequence replay** — replays actual historical market years (with an explicit unverified-data flag until figures are source-checked), not just a synthetic bad sequence.
4. **Meeting Mode** — a full-screen, presentation-ready, keyboard/tap-navigable client-conversation flow. This is a genuinely different artifact from a PDF report.
5. **Accumulation + decumulation as one continuous lifecycle** with SIP step-up, plus **corpus composition** (liquid vs. locked buckets, spend-liquid-first) — modelling nuance most goal calculators skip.
6. **Client self-service** — clients log in to see their own goals, run their own what-if sliders, and view their portfolio/reports read-only.
7. **Firm governance built-in** — firm roles (Jr/Sr/Firm Admin), a **Jr→Sr plan-approval workflow**, approval-gated strategy templates and risk questionnaires, and a full **audit trail** of every plan change. This is a compliance/oversight posture the transaction-first tools generally treat as an afterthought.
8. **SEBI-aware compliance disclosure** rendered on every client-facing plan view, tied to advisory-vs-distribution mode.
9. **Modern, fast, mobile-responsive UX** — directly targets the single most common complaint about incumbent tools (see §4).

### Honest net read

HorizonPlan **loses decisively** on transaction/back-office breadth and **wins decisively** on planning depth, the client conversation, governance, and UX modernity. It is not currently a standalone "run my whole MFD business" tool. Its credible wedge is *"the planning and client-relationship layer that's better than the module bolted onto your back-office — and pleasant to use."*

---

## 4. What real users say about the incumbents (sourced, with caveats)

**Caveat:** deep, quotable, verified end-user reviews for these specific India-only products were **thin** in available search results (G2/Capterra/SourceForge carry sparse India-MFD coverage, and vendor-hosted testimonials are not independent). The themes below are drawn from **industry buyer-guide / "things to avoid" content [sourced: kfintech, redvision, optimumfintech buyer guides]** and are **recurring pain points in the category**, not attributed complaints about one named vendor. Treat as directional, not as verified per-vendor verdicts.

Recurring category pain points:

1. **Complicated / dated UI** — "software should be easy to use and understand"; a complicated UI is explicitly called out as a thing to avoid. Slow adoption follows a clunky interface. **[sourced: buyer guides]**
2. **Poor fit as you scale** — off-the-shelf tools "rarely fit a real distributor's workflow, especially past a few hundred clients or multiple AMCs." **[sourced]**
3. **Missing / weak mobile** — "Mobile Compatibility Missing" is named as a thing to avoid. **[sourced]**
4. **Onboarding friction & reporting gaps** — the wrong platform "slows down onboarding, creates reporting gaps, and makes it harder to retain clients." **[sourced]**
5. **Support quality** — support responsiveness is a common differentiator buyers are told to check (implying it's inconsistent). **[sourced/inference]**

**Why this matters for HorizonPlan:** items 1 and 3 (dated UI, weak mobile) are exactly where HorizonPlan already invests — a modern React SPA with a completed mobile-responsiveness pass **[verified]**. That is a real, defensible edge *if* we keep it and market it. Items 2 and 4 (scale, reporting) are areas HorizonPlan must not regress on as it grows.

---

## 5. Prioritized feature backlog (the value-add list)

Ordered by **impact on the wedge above ÷ build cost**, not by novelty. Each item notes whether it *deepens the moat* (planning/conversation/governance — our strength) or *closes a table-stakes gap* (things buyers expect). **P0 = do next; P3 = deliberate, large, separate bets.**

Each P0/P1 item has a matching **session prompt in §6**, in this repo's established decide-then-build style.

### P0 — high impact, contained cost (do next)

- **P0-1 · Aggregate household / family planning.** Today a goal is per-client and explicitly *not* a shared pool (docs/02 §4.1). Advisors repeatedly need a **household view** (spouse + dependents, combined corpus, joint retirement). *Deepens the moat* — planning depth incumbents do shallowly. Medium build (schema for household grouping + aggregate projection view).
- **P0-2 · PDF export of the client report + Meeting Mode summary.** `PlanReport.jsx` is print-optimised but there's no first-class "download PDF" / server-rendered PDF. Advisors need a *leave-behind artifact* and buyers expect exportable reports (a named reporting-gap pain point). *Closes a table-stakes gap.* Small–medium.
- **P0-3 · Scheduled / emailed plan reviews.** A recurring "here's your updated plan" email (quarterly/annual) to the client, reusing the existing report. Incumbents lean on CRM automation here; a *light* version is high-retention, low-cost. *Closes a gap + retention.* Small (reuses Mailer + report + a cron like the NAV sync already shipped).
- **P0-4 · Client risk-profile visibility for the client.** Flagged as a known gap in CLAUDE.md — `RiskProfileSummary` is advisor-page-only; the client can't see their own risk band. Symmetry with the portfolio view already shipped. *Closes an obvious gap.* Small.

### P1 — high impact, larger cost

- **P1-1 · Goal-progress tracking over time.** Snapshot corpus vs. plan at intervals and show "on track / behind" drift — turns a one-time projection into an ongoing relationship artifact. Needs a valuation-history table (the NAV cron already lays groundwork). *Deepens the moat.* Medium.
- **P1-2 · Native mobile app (or installable PWA first).** Directly answers the "mobile missing" category complaint. Start with a **PWA** (installable, offline-lite) before a native build — cheap first step, big perceived parity. *Closes a named gap.* PWA small; native large.
- **P1-3 · Lightweight CRM signals** — KYC-expiry / SIP-renewal / review-due nudges on the advisor dashboard (not full campaign automation). Reuses the existing client-health dashboard. *Closes a gap, contained.* Medium.
- **P1-4 · Insurance & liability adequacy module** — term/health cover adequacy and loan/EMI stress in the plan. Indian advisors sell protection alongside MF; a *planning-side* (not distribution) module fits the moat. *Deepens the moat.* Medium.

### P2 — valuable, defer until P0/P1 land

- **P2-1 · Live RTA portfolio feed (CAMS/KFintech)** — replace manual/CSV holdings with an automatic folio feed. Real integration + credential surface; big step toward parity but heavy. *Closes a major gap.* Large.
- **P2-2 · Tax / capital-gains reporting** on tracked holdings (STCG/LTCG). Expected by buyers; depends on P2-1 for accurate cost basis. *Closes a gap.* Large.
- **P2-3 · Model portfolios + rebalancing suggestions** (advice only, no execution) — fits advisory mode. Medium–large.
- **P2-4 · Billing / subscription** for HorizonPlan itself (docs/09 Session 8 already scoped) — needed before real monetization at scale. Medium.

### P3 — deliberate, large, separate bets (decide before building)

- **P3-1 · Transaction execution (order routing).** The biggest possible expansion — turns HorizonPlan from planning layer into back-office. Real money movement, SEBI/exchange integration, a whole new compliance and ops surface. **Do not start without an explicit strategic decision** that we want to compete on the transaction spine rather than integrate with it.
- **P3-2 · Video KYC / onboarding-to-invest.** Only meaningful alongside P3-1.
- **P3-3 · Commission / brokerage reconciliation.** Only meaningful if we route transactions.

### What NOT to build (anti-scope)

- A generic CRM/campaign engine — the market is saturated; our edge is planning + conversation, not marketing automation.
- Multi-asset *trading* (stocks/PMS/AIF execution) — far outside the wedge.
- Chasing transaction parity *before* winning the planning/experience axis — it would spread us thin against entrenched incumbents on their home turf.

---

## 6. Session prompts for the top backlog items

Written in this repo's established **decide-then-build** style (see `docs/09`). Each is a standalone prompt for a focused session. Confirm the open decisions with a human via `AskUserQuestion` before writing schema, exactly as prior sessions did.

### Prompt — P0-2 · PDF export of client report + Meeting Mode summary

> **Context.** `PlanReport.jsx` is a print-optimised single-goal client report; there is no first-class PDF export. Buyers expect a downloadable/emailable leave-behind (a named category "reporting gap"). **Read `CLAUDE.md` and `docs/07` (the report is "the record" pillar) before starting.**
>
> **Decide first (AskUserQuestion):** (1) client-side print-to-PDF (browser `window.print()` with a print stylesheet — zero new deps, but variable output) vs. server-side rendering (headless Chromium/`dompdf` — consistent output, new dependency; note the pre-installed Chromium in this environment). (2) Whether the PDF is watermarked with the firm's white-label branding (it should — reuse tenant branding). (3) Include the readiness score + sequence chart as images, or a simplified static layout.
>
> **Likely shape.** A dedicated print stylesheet + a "Download PDF" action on `PlanReport.jsx`; if server-side, a new `api/goals_report_pdf.php` reusing the same projection data `goals_projection.php` returns. No new schema. Distribution-mode disclosure MUST render on the PDF (docs/02 §3.6 — it's client-facing).
>
> **Verify:** generate a real PDF for a seeded goal, confirm the disclosure banner, branding, readiness score, and survival chart all render; confirm a decumulation-only goal (no accumulation section) renders cleanly. **What NOT to build:** bulk/batch report generation for all clients at once (a P1 concern).

### Prompt — P0-3 · Scheduled / emailed plan reviews

> **Context.** No recurring client touchpoint exists. A light "your plan, refreshed" email on a cadence is high-retention and cheap — reuse `Mailer.php`, the existing report, and a cron pattern (the daily NAV sync in `027_mf_nav_sync` is the reference for a scheduled job in this stack). **Read the NAV-sync session write-up and `Mailer.php` first.**
>
> **Decide first:** (1) cadence options (advisor-set per client: off / quarterly / annually) — default off, opt-in per guardrail-style caution, never spam. (2) What the email contains — a link back to the client's own login (client self-service already exists) vs. an attached PDF (depends on P0-2). (3) Whether it respects `platform_settings.demo_mode` email-suppression (it must — that plumbing exists).
>
> **Likely shape.** A nullable `base_plans.review_cadence` (or a per-client setting), a cron script (`tools/` + documented CLI, like the NAV sync) that finds due reviews and calls `Mailer.php`, and an advisor UI toggle. Every send writes to `change_log` or a new `review_emails` audit row.
>
> **Verify:** against a real DB + the dev SMTP/error_log path, confirm a due review triggers exactly one email, an off/not-due one triggers none, and demo_mode suppresses sending. **What NOT to build:** a full campaign/drip engine (anti-scope §5).

### Prompt — P0-4 · Client-visible risk profile

> **Context.** `CLAUDE.md` already flags this: `RiskProfileSummary` renders only on the advisor's `ClientGoals.jsx`, never on the client's own `GoalsList.jsx`, even though `risk_profile_read.php` already permits a client to read their own profile (forced to their own id server-side). This is the **same gap shape** as the portfolio view already fixed — a read the backend allows but no client route surfaces. **Read the "client self-service" write-up in CLAUDE.md first.**
>
> **Decide first:** whether the client sees the *suggested return assumption* (approval-gated per docs/06 guardrail 2) or only their band/score. Recommendation: show band + score always; show suggested return only when the backend already returns it (it self-gates), mirroring the advisor view exactly.
>
> **Likely shape.** Render a read-only `RiskProfileSummary` variant on `GoalsList.jsx`, no `clientId` passed (server forces own id). Zero backend change. **Verify** as a real client session: profile renders, no advisor-only mutation controls, no leaked 403 text (the same sweep the portfolio-view session ran). **What NOT to build:** letting the client *retake* the questionnaire unprompted (advisor-initiated only, per existing design).

### Prompt — P0-1 · Household / family aggregate planning

> **Context.** A goal is per-client and deliberately *not* a shared household pool (docs/02 §4.1). But advisors plan for *households* (spouse + dependents, joint retirement corpus). This is the single most-requested planning-depth capability incumbents do shallowly. **This is a real schema + modelling decision — read docs/02 §4.1 and the accumulation/decumulation write-ups in CLAUDE.md before touching anything.**
>
> **Decide first (this is the crux):** (1) Does a "household" group existing client records, or is it a new entity clients attach to? (2) Is the household projection a *sum* of independently-modelled members' goals, or a jointly-modelled corpus with shared assumptions? (Recommendation: sum of members first — it preserves the "a goal is not a shared pool" guardrail while giving the aggregate view; joint modelling is a bigger, later step.) (3) Tenant-isolation: a household never crosses tenants. (4) Does client self-service show the household or only the individual? (Recommendation: individual only at first — privacy between spouses is a real concern to decide explicitly.)
>
> **Likely shape.** A `households` table (tenant-scoped) + a join from `users`(client) to a household; an aggregate projection endpoint that sums member goals through the *existing* `PlanMath` (no new math), reusing readiness scoring per-member and at the household level. **Verify** the aggregate equals the sum of independently-fetched member projections exactly, and that cross-tenant isolation holds. **What NOT to build:** joint/shared-assumption modelling (defer), and any automatic pooling of one member's corpus into another's goal (violates the guardrail).

### Prompt — P1-2 (first step) · Installable PWA

> **Context.** "Mobile missing" is a named category complaint; HorizonPlan is responsive web only. A **PWA** (web app manifest + service worker) is the cheap first step to "installable app" parity before any native build. **Read the mobile-responsiveness session write-up in CLAUDE.md first — the responsive work it depends on is already done.**
>
> **Decide first:** (1) offline scope — recommendation: cache the app shell + last-viewed data read-only; never queue offline *writes* (a plan edit that silently syncs later is a correctness risk in a financial tool). (2) Whether the demo/staging banners must render in installed mode (they must). (3) Icon/splash from tenant white-label vs. HorizonPlan default (default first; white-label PWA is a later refinement).
>
> **Likely shape.** A `manifest.webmanifest`, a conservative service worker (Vite PWA plugin or hand-rolled), icons, and an "Add to home screen" nudge. No backend change. **Verify** installability in a real browser (Lighthouse PWA checks), that an installed session still gates MFA/roles identically, and that no offline write path exists. **What NOT to build:** offline write/sync, push notifications (separate, later), or a native wrapper (that's the large P1 build this defers).

---

## 7. One-line summary for the pitch

> **HorizonPlan isn't trying to be your back-office. It's the planning and client-conversation layer that's actually deep, modern, and pleasant — the retirement story, the meeting, and the record — for the ~90% of that experience the transaction tools treat as a bolt-on.**

Marketing one-pager (public-facing) lives at `docs/HorizonPlan_One_Pager.html`.
