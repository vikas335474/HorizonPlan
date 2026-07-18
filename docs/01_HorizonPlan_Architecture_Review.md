# HorizonPlan MVP — Architecture Review

**Reviewed:** `HorizonPlan_MVP_Architecture_Blueprint.pdf`
**Repo checked:** https://github.com/vikas335474/HorizonPlan (public, **empty** — no commits as of this review, so this is a review of the plan only, not of any existing code)
**Review date:** July 18, 2026

This is a direct technical and functional critique. It calls out what's solid, what's broken, and what's missing, without softening any of it. Severity tags: **Critical** (must fix before handling real client data), **High** (fix before launch), **Medium** (fix soon after launch), **Low** (worth doing, not urgent).

---

## 1. Functional Review

### 1.1 Target market inconsistency — Medium
The document names financial advisors, wealth management firms, and **mutual fund distributors (MFDs)** as the target users. MFD is an India-specific role. But the "peer-review forum analysis" in Section 7 cites r/CFP and r/DIYRetirement — both primarily US-audience communities — and the example feature ("switching from a standard low-tax bracket path to a dynamic pro-rata strategy across taxable and tax-deferred layers") is US retirement-account tax terminology (401k/IRA-style). India does not have a direct equivalent account structure.

This means one of two things is true, and it matters which:
- The market research cited is generic/unverified and not actually tied to the stated target buyer (MFDs), or
- The product is scoping US-specific tax/withdrawal logic into a platform meant for an Indian regulatory and product environment.

**Recommendation:** Pin down the actual target market before building the distribution/withdrawal engine, since the tax logic differs meaningfully between markets. If the target is Indian MFDs, the "99% Success Trap" and withdrawal-sequencing features need to be re-scoped around Indian instruments (equity/debt MF taxation, NPS, PPF, EPF) rather than the taxable/tax-deferred US framing.

### 1.2 RBAC tier model — good, no issues
Super Admin / Advisor / Client is a standard, well-understood B2B2C structure. No changes needed to the tier concept itself.

### 1.3 Global Inheritance Engine — good pattern, missing audit trail — High
The cascade-with-override pattern (`is_overridden` flag protecting locally-customized scenarios from parent updates) is a legitimate, clean solution to a real problem: letting an advisor update a baseline assumption without silently destroying a client's custom stress-test scenario.

**Gap:** there is no history/versioning on these cascades. When an advisor changes a baseline inflation assumption and it propagates to every non-overridden sub-scenario, nothing records what assumption a client was shown, or when. For a product giving financial guidance, this is a real gap — if a client later disputes what they were told, or a regulator asks what basis a recommendation was made on, there's no record.

**Recommendation:** Add a lightweight `plan_snapshots` or `change_log` table capturing (tenant_id, base_plan_id, changed_field, old_value, new_value, changed_by, changed_at) at minimum for base_plan mutations that trigger cascades.

---

## 2. Technical Review

### 2.1 What's done correctly
- All SQL shown uses PDO prepared statements with bound parameters — no SQL injection surface in the samples given.
- `active_sessions` query checks `expires_at > NOW()` — session expiry is actually enforced in the query, not just assumed.
- Explicit `tenant_id` stamping on every table is a reasonable compensating control given the deliberate choice to avoid a framework with native Row-Level Security (e.g., Supabase/Postgres RLS). Given the hosting constraint (PHP/MySQL on shared hosting), this is the right instinct.
- Debouncing the Live Timeline Slider to fire a single update on drag-release rather than per-frame is correct, sensible API hygiene.

### 2.2 Confirmed code bug — Critical
```php
$input = json_encode(file_get_contents("php://input"), true);
```
`file_get_contents("php://input")` returns the raw JSON request body as a **string**. `json_encode()` on a string re-wraps it as a JSON string literal — it does not parse JSON into a PHP structure. This should be:
```php
$input = json_decode(file_get_contents("php://input"), true);
```
As written, `$input['sub_scenario_id']` and `$input['custom_inflation']` would fail — PHP does not support associative-array-style access on a plain string (illegal offset / warning, or a fatal type error depending on PHP version). The endpoint as documented would not function on a real POST request. This is not a style note; it's a functional defect in the reference implementation shown in the blueprint.

### 2.3 Security gaps — this matters more than usual because the data is financial PII

| Gap | Severity | Detail |
|---|---|---|
| No MFA | High | Net-worth and retirement data behind single-factor password auth only. |
| No brute-force/rate limiting on login | High | Nothing in the gatekeeper code or design addresses repeated failed login attempts. |
| Token storage/transport unspecified | High | Code reads a bearer token from an `Authorization` header, implying the SPA holds it client-side. If that's `localStorage`, it's XSS-exposed. This needs to be an explicit decision — httpOnly, secure, sameSite cookie is the safer default for a PHP-session-style model. |
| "BCRYPT... encrypted" | Low | Terminology error — bcrypt is a hashing algorithm, not encryption. Worth being precise about in a security section of a fintech document. |
| No audit logging | High | No record of who changed what financial assumption, when. See 1.3. |
| No column-level encryption at rest | Medium | On shared MySQL hosting, sensitive financial fields (net worth, custom inflation assumptions) sit in plaintext in the database. |
| Single-role RBAC check has no cross-role access model | Medium | `verifyAccess()` blocks anyone whose role isn't an exact match (or super_admin). There's no path for an advisor to legitimately act on a client-scoped endpoint (e.g., viewing/editing a client's plan on their behalf), which is a core advisor workflow. |
| No CSRF protection mentioned | Medium | Relevant if any part of the auth flow ever relies on cookies. |
| No password reset / email verification flow | Medium | Not addressed anywhere in the blueprint. |

### 2.4 Infrastructure assumption that needs validating — High
Checked directly against Hostinger's current published limits rather than assumed:
- Default PHP `max_execution_time` on Hostinger is 30 seconds. It can be raised via `.htaccess`, but only up to whatever ceiling the specific plan allows — it is not unlimited.
- Premium-tier plans have a fixed PHP worker/process ceiling (third-party comparisons put Premium around 40 concurrent workers vs Business around 60 — treat as approximate and verify current numbers in hPanel, since Hostinger revises plan specs periodically).
- Premium **does** include SSH access (restricted to the home directory) — this was verified and corrects an initial assumption; the hosting choice is less restrictive than "no shell access" would suggest.

**Why this matters:** if "dynamic wealth forecasting" involves anything resembling Monte Carlo simulation across many scenarios, running that synchronously inside a single PHP web request on shared hosting risks hitting the execution-time ceiling as scenario complexity grows. Nothing in the blueprint addresses this — there's no background job / queue architecture, and no documented computational complexity ceiling for the MVP.

**Recommendation:** Either (a) explicitly cap MVP scenario complexity to what's provably fast enough to run synchronously within the execution-time budget, or (b) design a simple polling-based background job pattern (a `jobs` table + a cron-triggered PHP worker script, which Hostinger cron jobs support) for anything heavier.

### 2.5 Maintainability — Medium
Each endpoint is shown as a standalone PHP file (`update_timeline.php`) that re-implements the tenant check inline. There is no shared data-access layer centrally enforcing tenant scoping — it works only if every future endpoint remembers to add `AND tenant_id = :tenant_id` to every query, by hand, forever. One missed WHERE clause on one future endpoint is a cross-tenant data leak — which is precisely the failure mode this whole architecture is trying to prevent.

**Recommendation:** Before writing more than the 2-3 endpoints already sketched, write one small `TenantScopedRepository`-style PHP helper that all data access routes through, so tenant scoping is enforced in one place instead of copy-pasted into every file.

### 2.6 Missing entirely from the blueprint
- Backup/disaster recovery approach (Hostinger Premium includes weekly backups by default — worth confirming this is sufficient for financial data, or whether more frequent backups are needed).
- Secrets management — where `db_config.php` credentials live and whether that file risks being committed to the git repo.
- Any testing strategy (unit/integration) for the security-critical tenant-isolation logic specifically.
- API documentation approach as endpoint count grows.
- Environment separation (dev/staging/prod).

---

## 3. Bottom line

The plan gets the one hard architectural question right: how to enforce tenant isolation without a database-native RLS layer, using explicit `tenant_id` stamping plus an application-level gatekeeper. That's a legitimate, defensible choice for the stated hosting constraint.

But as written, it is not ready to hold real client financial data. The blocking issues are the confirmed code bug, the absence of MFA/rate-limiting/audit-logging on a product handling net-worth data, the unresolved US-vs-India market/tax-logic mismatch, and the lack of a central tenant-scoping enforcement point before the endpoint count grows. None of these are reasons to abandon the plan — they're reasons to close them before writing more code, since several (especially the tenant-scoping pattern and the audit trail) are far cheaper to build in now than to retrofit later.
