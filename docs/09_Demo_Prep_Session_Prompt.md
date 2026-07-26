# Demo Prep Session — Next-Session Prompt

> **Copy-paste this entire document as the prompt for the next Claude Code session.**
> It is self-contained: every decision is already made, every ambiguity resolved.
> The implementer should read `CLAUDE.md` and the files referenced below before writing code, not assume this prompt alone is enough.

---

## Goal

Make HorizonPlan demo-ready: a SaaS-provider admin can toggle the platform between **production mode** (mandatory MFA, real emails) and **demo mode** (MFA skipped, emails suppressed, one-click data reset), and the demo itself is populated with a rich, realistic dataset spanning 4 firms, 16 advisor-tier employees across a firm-level role hierarchy, and 160 clients with varied portfolios and life stages.

Three pieces, in build order (each depends on the previous):

---

## Piece 1 — Platform Settings + Demo Mode Toggle

### Schema

**Migration 019:** `platform_settings` — a single-row configuration table for platform-wide knobs. Only one row ever exists (enforced by the seeder/bootstrap, not a DB constraint — keeps it simple).

```sql
CREATE TABLE platform_settings (
    id                  INT UNSIGNED PRIMARY KEY DEFAULT 1,
    mfa_enforcement     ENUM('enabled', 'disabled') NOT NULL DEFAULT 'enabled',
    demo_mode           ENUM('off', 'on') NOT NULL DEFAULT 'off',
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (id) VALUES (1);
```

**`demo_mode = 'on'` means all three of:**
1. MFA enforcement is skipped (regardless of `mfa_enforcement` column — demo mode implies MFA off).
2. All outbound emails are suppressed (`Mailer.php` returns early without calling `mail()`).
3. A "Reset demo data" button is available in the super_admin console.

**`mfa_enforcement = 'disabled'` without demo mode** is also valid — allows disabling MFA independently for testing without the other demo behaviors.

### Backend changes

- **`api/lib/security_gatekeeper.php`**: `requireMfaEnrollment()` checks `platform_settings.mfa_enforcement` and `platform_settings.demo_mode` before blocking. If either is `'disabled'`/`'on'` respectively, skip the MFA check. Use a simple `SELECT` cached per-request (not per-call — read once, store in a static variable).
- **`api/lib/Mailer.php`**: Check `platform_settings.demo_mode` at the top of the send function. If `'on'`, return early (log to `error_log` that email was suppressed for demo mode, so it's debuggable).
- **`api/platform_settings_read.php`** (GET, super_admin-only): Returns current settings.
- **`api/platform_settings_update.php`** (POST, super_admin-only): Updates `mfa_enforcement` and/or `demo_mode`. Writes to `change_log` (action: `platform_settings_updated`).
- **`api/session.php`**: Include `demo_mode` and `mfa_enforcement` in the session response so the frontend knows without a separate call.

### Frontend changes

- **`AdminConsole.jsx`**: New "Platform" tab (first tab, before Firms/Templates) with:
  - Toggle: "MFA enforcement" (enabled/disabled) with a warning when disabling.
  - Toggle: "Demo mode" (off/on) with a clear description of what it does.
  - "Reset demo data" button (only visible when demo mode is on) — calls `api/demo_reset.php`.
- **`AppHeader.jsx` or layout wrapper**: When `demo_mode === 'on'`, render a fixed amber banner across the top: "DEMO ENVIRONMENT — data is illustrative, not real client information."
- **`ProtectedRoute.jsx` / `Settings.jsx`**: When MFA enforcement is off (from session response), skip the MFA-required redirect and hide the forced-enrollment UI. The MFA settings page itself remains available for voluntary enrollment.

### New endpoint: `api/demo_reset.php`

POST, super_admin-only, only works when `demo_mode = 'on'` (403 otherwise). Drops and re-runs the demo seeder (Piece 3's script) — truncates demo-created data and re-seeds. Must NOT touch the super_admin's own account or `platform_settings`. Idempotent.

---

## Piece 2 — Firm-Level Role Hierarchy

### Schema

**Migration 020:** Add `firm_role` column to `users`.

```sql
ALTER TABLE users
    ADD COLUMN firm_role ENUM('jr_advisor', 'sr_advisor', 'firm_admin') NULL
    AFTER role;
```

- `firm_role` is `NULL` for `super_admin` and `client` roles — it only applies to `advisor`-role users.
- Existing `advisor` users get `NULL` (treated as `sr_advisor` by default for backward compatibility — existing tests and endpoints don't break).
- The `role` column stays unchanged (`super_admin`, `advisor`, `client`) — `firm_role` is a sub-classification within `advisor`, not a replacement.

### Permission rules (decided)

| Capability | `jr_advisor` | `sr_advisor` | `firm_admin` | `super_admin` |
|---|---|---|---|---|
| View clients & goals | Yes | Yes | Yes | Yes |
| Create/edit clients & goals | Yes | Yes | Yes | Yes |
| Create sub-scenarios | Yes | Yes | Yes | Yes |
| Create templates/customizations | Yes | Yes | Yes | Yes |
| **Approve** templates | No | Yes | Yes | Yes |
| **Approve** risk questionnaires | No | Yes | Yes | Yes |
| Add/manage advisors in firm | No | No | Yes | N/A (cross-tenant) |
| Edit firm branding/settings | No | No | Yes | N/A (cross-tenant) |

### Backend changes

- **`api/session.php`**: Include `firm_role` in the session response.
- **`api/templates_approve.php`**: Check `firm_role` — reject if `jr_advisor` with a clear message ("Approval requires senior advisor or firm admin privileges").
- **`api/risk_question_set_approve.php`**: Same check.
- **`api/admin_advisor_create.php`**: Accept `firm_role` param when creating an advisor.
- **`api/tenant_update.php`**: Check `firm_role` — only `firm_admin` (or `super_admin`) can update firm branding/settings. Currently super_admin-only; widen to include `firm_admin` for their own tenant.
- **Helper**: Add a `requireFirmRole(array $session, array $allowedFirmRoles)` function in `security_gatekeeper.php` that checks `$session['firm_role']` against the allowed list. For backward compatibility, `NULL` firm_role on an advisor is treated as `sr_advisor`.

### Frontend changes

- **`AdminConsole.jsx` / firm detail**: Show `firm_role` next to each advisor in the advisor list. When adding an advisor, include a role selector (jr_advisor / sr_advisor / firm_admin).
- **`AdvisorTemplates.jsx`**: Hide/disable the "Approve" button for jr_advisors.
- **`RiskQuestionnaireBuilder.jsx`**: Hide/disable the "Approve" button for jr_advisors.
- **`AppHeader.jsx` or profile area**: Show the firm_role label so the logged-in user knows their permission level.

---

## Piece 3 — Rich Demo Seeder

### Script: `tools/seed_demo_data_full.php`

Replace (or extend) the existing `tools/seed_demo_data.php`. Idempotent — checks for the demo super_admin tenant, skips if already seeded.

**All accounts use the same password: `DemoPass@2026`** (same as current seeder). No MFA secrets set on any demo account (demo mode means MFA is skipped anyway).

### Data structure

**1 SaaS Provider Admin:**
- Email: `platform.admin@demo.horizonplan.in`, role: `super_admin`
- Tenant: "HorizonPlan Platform" (or reuse the existing bootstrap tenant)

**4 Firms (tenants):**

| # | Company Name | `advisory_mode` | Branding |
|---|---|---|---|
| 1 | Nirvana Wealth Advisors | `advisory` | Logo color: teal `#0f766e` |
| 2 | Artha Financial Advisors | `advisory` | Logo color: indigo `#4f46e5` |
| 3 | Dhanvantri MFD Services | `distribution` | Logo color: amber `#d97706` |
| 4 | Lakshmi Mutual Fund Distributors | `distribution` | Logo color: emerald `#059669` |

**4 employees per firm (16 total):**

Each firm gets:
- 1 `firm_admin` — e.g. `admin@nirvana.demo.horizonplan.in`
- 1 `sr_advisor` — e.g. `senior@nirvana.demo.horizonplan.in`
- 2 `jr_advisor` — e.g. `junior1@nirvana.demo.horizonplan.in`, `junior2@nirvana.demo.horizonplan.in`

All have `role = 'advisor'` with the appropriate `firm_role`.

**10 clients per advisor-role employee (160 total):**

Clients distributed across realistic Indian age brackets and life stages:

| Age bracket | Count per firm (of 40) | Profile |
|---|---|---|
| 25–30 (early career) | 8 | Accumulation-heavy, no retirement withdrawal yet, aggressive risk band |
| 31–40 (mid career) | 12 | Mixed accumulation + some early retirement planning, moderate risk |
| 41–50 (peak earning) | 10 | Full retirement planning, corpus composition (liquid + locked), moderate-conservative |
| 51–60 (pre-retirement) | 6 | Decumulation-focused, conservative risk, higher corpus |
| 60+ (retired) | 4 | Pure decumulation, low withdrawal rates, conservative, highest corpus |

Each client gets:
- 1–3 goals (retirement + optionally education/home_purchase) based on age bracket
- A client portfolio ledger (mix of liquid + locked assets, liabilities)
- A captured risk profile (if the firm has an approved risk questionnaire)
- Some clients have sub-scenarios (demonstrating live vs frozen/overridden)
- Some retirement goals have accumulation fields set, some don't
- Some goals have corpus composition (liquid/locked split), some are single-corpus
- At least 1 goal per firm has a strategy template applied

**Per-firm extras:**
- Each firm has 1 approved risk questionnaire (different question sets per firm to show customization)
- Each advisory-mode firm has 1 approved strategy template + 1 draft customization
- Each distribution-mode firm has the disclosure banner visible on all client views (existing behavior)

**Readiness score distribution** (important for demo — shows the dashboard health signals):
- ~25% of clients should score < 40 ("Needs attention") — low corpus, high withdrawal rate
- ~50% should score 40–70 ("On track") — typical mid-range
- ~25% should score 70+ ("Strong") — conservative assumptions, adequate corpus

This ensures the "Needs attention only" filter and sort-by-readiness on the advisor dashboard have meaningful data to demonstrate.

### Reset endpoint: `api/demo_reset.php`

- POST, super_admin-only, requires `demo_mode = 'on'`
- Deletes all demo-seeded data (identified by email domain `@*.demo.horizonplan.in` or by a `demo_seeded` flag if cleaner)
- Does NOT delete the super_admin account or `platform_settings`
- Re-runs the seeder
- Returns the list of demo login credentials

---

## Verification plan (same rigor as prior sessions)

1. **DB tests**: `tests/test_platform_settings.php` (toggle read/write, MFA bypass when disabled), `tests/test_firm_roles.php` (approval blocked for jr_advisor, allowed for sr_advisor/firm_admin, backward compat for NULL firm_role)
2. **Live HTTP tests**: `php -S` + curl cookie-jar — demo mode toggle, MFA skip in demo mode, email suppression, approval rejection for jr_advisor, demo reset
3. **Playwright**: Walk through as super_admin (toggle demo mode, see banner, reset data), then as each firm_role (jr tries to approve → blocked, sr approves → succeeds), then as a client (portfolio, goals visible)
4. **Full test suite**: `tests/run_all.sh` passes unchanged (backward compatibility for NULL firm_role)
5. **CLAUDE.md update**: Document all three pieces following the existing narrative style

## Build order

1. Migration 019 (platform_settings) → platform settings endpoints → `security_gatekeeper.php` MFA bypass → `Mailer.php` suppression → AdminConsole Platform tab → demo banner → verify
2. Migration 020 (firm_role) → `requireFirmRole()` helper → approval endpoint gates → frontend permission checks → verify
3. Full demo seeder → demo reset endpoint → verify end-to-end
4. CLAUDE.md update, commit, push, PR, CI, merge

---

## What this session should NOT build

- No changes to the existing 3-role enum (`super_admin`, `advisor`, `client`) — `firm_role` is additive
- No multi-tenant super_admin dashboard beyond the existing AdminConsole + new Platform tab
- No client self-registration (still admin-provisioned per docs/04)
- No billing/subscription system
- No changes to the TOTP mechanism itself (`Totp.php`, `mfa_enroll.php`, `mfa_verify.php`) — only the enforcement gate changes
