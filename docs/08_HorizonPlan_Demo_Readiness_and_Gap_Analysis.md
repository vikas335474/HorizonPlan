# HorizonPlan — Demo Readiness & Gap Analysis

> **STATUS NOTE (added during a later documentation-accuracy pass): this
> document is a point-in-time snapshot** from an early session — most of the
> "Gaps, prioritized" list below has since been built. It is kept verbatim as
> the historical record of what the product looked like at that point (per
> this repo's convention of not rewriting history — see
> `docs/CHANGELOG_SESSION_HISTORY.md`); each item is annotated inline with
> its **current** status. For what's actually built today, `CLAUDE.md`'s
> "Current state — what's built" capability index is the live source of
> truth, not this file.

Written after a session focused on firm onboarding UX, demo-data preparation, and a full test pass — not a new build phase. Companion to `04_HorizonPlan_Feature_Roadmap.md` (which governs *what* belongs in each phase) and `07_HorizonPlan_Product_Vision_Differentiation.md` (the differentiation bets already shipped). This document answers a narrower question: **is the product, as it stands today, something you could put in front of a real MFD/IFA or SEBI-RIA firm and have it read as a credible SaaS product, and what's the prioritized list to close the remaining gaps?**

No phase was skipped to write this. Everything in this session is either (a) UX polish on already-shipped Phase 1 scope (tenant onboarding was always Phase 1 — see `04`'s "Tenant onboarding (admin-created, not self-serve signup)" — this session made it feel like onboarding a customer instead of filling out a database record, it didn't add new scope), or (b) demo-data/testing hygiene that doesn't correspond to a roadmap phase at all. Phase 2's candidate items (cross-goal allocation, WhatsApp sharing, advisory-mode activation flow) are untouched and still gated behind their validation criteria in `04`.

## What changed this session

- **Firm onboarding wizard** (`AdminConsole.jsx`): the single cramped "create firm" modal is now a 3-step wizard (firm + compliance mode → optional branding with a live preview → optional first advisor), ending in a setup-progress checklist instead of a bare success message.
- **Firm detail view**: a firm's advisors, stats (advisor/client/goal counts), compliance mode, and branding now live in one consolidated "Manage firm" view (`api/tenant_detail.php`, new) instead of three separate inline expand panels cluttering the firm list.
- **Platform stats bar**: total firms/advisors/clients/advisory-mode split, so the console reads as a real admin dashboard, not a bare list.
- **Advisor invites are emailed** (`tenants_create.php`, `admin_advisor_create.php`, via the existing `Mailer.php`), not just flashed once on screen — the temp password still shows on screen too, as a fallback for when Hostinger's local `mail()` doesn't land, since there's no SMTP dependency in this stack yet.
- **Demo data seeder** (`tools/seed_demo_data.php`, new, idempotent): one command creates a fully-populated firm — 2 advisors, 5 clients, 6 goals spanning every field combination shipped so far (accumulation+decumulation, liquid/locked corpus composition, decumulation-only, non-retirement goal types), 2 sub-scenarios (one live, one frozen/overridden), a client portfolio ledger, an approved risk questionnaire with 2 captured profiles, and one strategy template approved and genuinely applied to a goal (the real `used_in_plan` audit path). Run `php tools/seed_demo_data.php`; sign-in details print at the end.
- **Real bug found and fixed while building the seeder**: both `tools/seed_templates.php` and the new seeder assumed a "system tenant ID 0" for global templates. `tenants.id` is `AUTO_INCREMENT`, and this project's `sql_mode` does **not** include `NO_AUTO_VALUE_ON_ZERO`, so MySQL silently treats an explicit `VALUES (0, ...)` as "assign the next ID," not literal 0 — every later `tenant_id = 0` reference then fails its FK check. This had never actually been exercised end-to-end before (confirmed: `template_strategies` had 0 rows in the test database prior to this session, even though `seed_templates.php` predates it). Fixed in both scripts: find-or-create the system tenant by `company_name = 'HorizonPlan System'` and use its real ID, since a system template's cross-tenant visibility comes from `is_system_template`/`is_published` flags (`TenantScopedDb::selectGlobalPublishedTemplates()` has no `tenant_id` filter at all), not from which tenant ID it happens to carry.

Verified end-to-end: full `tests/run_all.sh` suite passes unchanged; a live HTTP session (`php -S` + `curl`, cookie-jar login/CSRF flow) confirmed `tenant_detail.php`'s success/400/404 paths and `tenants_create.php`'s non-blocking email path; a real browser (Playwright) walked the full wizard (continuous typing in every step — the modal focus-loss bug from an earlier session was specifically re-checked, no regression), opened the firm detail drawer, and typed continuously into the branding form with zero console errors.

## Competitive assessment

Is this demo-ready for a real advisor or distributor conversation? **Yes, for the core planning experience** — multi-goal planning, the sequence-of-returns chart, the readiness score, meeting mode, and now a firm onboarding flow that doesn't look like a raw admin panel. That core is genuinely differentiated against the named competitive set in `05_HorizonPlan_Practitioner_Validation_Review.md` (Wealth Elite, NJ Fundz, IFA-Planet, JezzMoney, Investwell Mint, theMFBox) — none of them show sequence-of-returns risk during withdrawal.

**Not yet, for the operational maturity a distributor pitch will probe.** A distributor evaluating this as a platform to resell (not just an advisor evaluating it as a planning tool) will ask about things below that a planning-tool demo doesn't surface but a platform-evaluation conversation will.

## Gaps, prioritized

**Security / compliance (carried over from `docs/02`'s Phase 8 audit — still open, highest priority regardless of demo needs):**
1. **MFA enrollment is not mandatory.** Unenrolled users still get a full session on password alone. Needs a deliberate decision (block at login vs. admin-forced enrollment) before any real client data goes in. — ***Built, but still defaulted OFF.*** The full mechanism (RFC 6238 TOTP + Google Sign-In as an equivalent second factor, server-side mandatory-enrollment gate) shipped; `platform_settings.mfa_enforcement` is deliberately left off for the early-access period (CLAUDE.md "Known open items"). Re-enabling it before real data is still the one open action item, not a build gap.
2. **No rate limiting beyond login.** `goals_*`/`subscenarios_*` endpoints have none. Low urgency at current scale, worth a decision. — ***Still open, deliberately deferred*** (docs/09 Session 3) — revisit only on a named abuse case, per CLAUDE.md.
3. **Per-field validation gap in `goals_update.php`** — only `projection_horizon_years` is range-checked; `initial_net_worth`, `inflation_rate`, `target_amount`, `target_date`, `withdrawal_rate`, `drawdown_return_rate` accept anything. — ***Built.*** `GoalFieldValidation.php` now validates every field, shared by create/update (CLAUDE.md "Plans & scenarios").

**Onboarding / admin UX (this session closed the biggest piece; smaller items remain):**
4. **Advisor invites are still a temp password, not a magic sign-in link.** — ***Built.*** Magic-link invite activation now reuses the password-reset token infrastructure (CLAUDE.md "Platform & access").
5. **Advisor dashboard / client list is still weak.** — ***Built.*** A persona-aware dashboard with a firm-wide attention banner, a book-health bar, and server-side search/sort/scope/pagination shipped (CLAUDE.md "Advisor & admin UX"), plus the alerts engine (docs/12 D-4) layered dynamic per-client signals on top.
6. **No in-app onboarding beyond the new firm wizard.** — ***Built.*** An onboarding checklist + spotlight, a guided demo tour, and an in-app feature guide (`/guide`) all shipped (CLAUDE.md "Advisor & admin UX").

**Platform-evaluation gaps (what a distributor, not an advisor, will ask about):**
7. **No billing/subscription/plan-tier concept.** — ***Still open*** (docs/09 Session 8) — needs a real business decision on tiers/billing model before it's an engineering task, per that session's own guardrail.
8. **No advisor-facing analytics/engagement dashboard** — ***Partly built, partly still open.*** Per-firm **Practice analytics** (`/practice`, sr_advisor/firm_admin: per-advisor load, readiness/risk distribution, review pipeline, coverage gaps) shipped and is documented in CLAUDE.md. What's still open is the *different* audience this item originally meant — a super_admin-facing view of *advisor engagement with the platform itself* (login frequency, feature usage) — see docs/09 Session 9, which now explicitly distinguishes the two so they don't get conflated again.
9. **No audit-log UI for admins beyond template history.** — ***Built.*** A read-only firm-wide audit log (`change_log_list.php`, per-goal History card + `/activity`) shipped (CLAUDE.md "Firm governance").
10. **Mobile responsiveness hasn't been explicitly audited** — ***Built/audited.*** A dedicated mobile-responsiveness pass (Session 7) plus tablet/client-role follow-ups shipped; `AppHeader` collapses to a hamburger below `sm` (CLAUDE.md "Advisor & admin UX").

**Lower priority / explicitly out of scope per the roadmap (listed here only so nothing looks accidentally forgotten):**
11. WhatsApp report sharing, cross-goal portfolio allocation, advisory-mode activation flow — Phase 2 candidates in `04`, correctly not built yet, gated behind Phase 1's validation criteria.
12. Multi-language support, mobile app — Phase 4, speculative by design.
13. Background job infrastructure — explicitly re-evaluated and skipped in Phase 7/8 for lack of demonstrated need; still true, nothing computationally heavy has been added since.

## Recommended next session

Given you're stepping away: the highest-leverage next session is **either** (a) mandatory MFA enrollment — the one remaining pre-launch security blocker, small and well-scoped — **or** (b) the advisor dashboard/client-list redesign you already picked, now that the admin side has a matching bar of polish to design toward. Both are self-contained, testable in one session, and don't require a new validation gate to justify (unlike anything in the Phase 2 candidate list).

> **Both of these were subsequently done** (see the status annotations above) — this recommendation is left as written for the historical record. For what's actually next, see `docs/10`'s prioritized backlog and CLAUDE.md's "Next-build backlog" line.
