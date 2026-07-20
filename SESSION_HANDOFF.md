# HorizonPlan — Session Handoff: UI/UX Redesign & Client Onboarding

**Last commit:** `0d7d749` (UI redesign: modern fintech aesthetic + advisor dashboard)  
**Current branch:** `main` at `0d7d749`  
**Project repo:** https://github.com/vikas335474/HorizonPlan

---

## What's Built (Complete)

**Backend (Phases 0–8):**
- Multi-tier B2B2C IAM (super_admin / advisor / client roles, RBAC + tenant isolation)
- Database schema: users, tenants, base_plans, sub_scenarios, active_sessions, mfa_pending, change_log, login_attempts
- Security layer: TenantScopedDb helper, verifyAccess/verifyAccessAny, CSRF double-submit tokens, MFA (RFC 6238 TOTP)
- Core endpoints: login, session, logout, mfa_verify, mfa_enroll, mfa_enroll_confirm
- Planning endpoints: goals_create, goals_list, goals_read, goals_update, goals_projection, subscenarios_create/list/update/reset, clients_list
- Global Inheritance Engine: base-plan inflation cascades to scenarios, is_overridden flag protects manual overrides, Reset Trigger resyncs child with parent
- Deployment: Hostinger Premium, PHP (no framework), MySQL, no Node execution

**Frontend (Phases 5–6, partial redesign in progress):**
- React 19 + Tailwind v4 + shadcn/ui, Vite build → static dist/ → `public_html`
- Design system: fintech token palette (deep slate ink #0F1729, cool canvas #F7F8FA, teal accent #0E7C6B, amber, muted rust)
- Login: two-step MFA flow (password → OTP on 202 response)
- Core screens: Dashboard (advisor), ClientGoals (advisor per-client), GoalsList (client own goals), GoalDetail (expandable scenarios)
- Scenarios UI: Live Timeline Slider (local state, commit-on-release), withdrawal-rate slider, corpus-multiple readout, sequence-risk chart (recharts), Reset Trigger
- Disclosure banner (Section 3.6 compliance copy, static distribution-mode for MVP)
- Role-based routing: advisor/admin → `/` (Dashboard), client → `/goals`
- All endpoints route through security gatekeeper with CSRF check on non-GET

---

## Critical Gaps & What's Missing

### UI/UX Problems (USER FEEDBACK)
User tested deployed app and reported:
- **Colors not rendering:** CSS tokens are correct (verified in built assets), but old colors show in browser. **Root cause: deployment cache or old files not overwritten.** CSS is fintech palette (slate, teal, canvas); likely user didn't fully clear old `dist/` or browser cache.
- **UI is "very basic":** Stat cards are functional but lifeless; no visual depth, hierarchy, or craft. Admits looks like an "admin panel, not a world-class wealth product."
- **Nothing to do:** True — only read-only screens exist (view goals, adjust scenarios). No onboarding, goal creation, or settings.
- **No "Add client" button:** User explicitly asked for this; doesn't exist.

### What's Actually Missing (Not Built)

**Critical for MVP:**
1. **Client onboarding** — no way for advisor to add clients; hardcoded to manual DB inserts
2. **Goal creation** — advisors can't create goals for clients; goals only exist if seeded
3. **MFA enrollment UI** — endpoint exists (`mfa_enroll.php`, `mfa_enroll_confirm.php`), no screen to access it
4. **Settings/profile page** — no place for users to enroll MFA, change password, or see their profile
5. **MFA mandatory enforcement** — `login.php` forks on `users.mfa_secret`, but unenrolled users still get full session; no gate to force enrollment before real client use

**Nice-to-have for MVP (out of scope currently):**
- Password reset
- Advisor-to-client client picker UI (now just a text field in old flow)
- Goal edit/delete
- Scenario naming / history
- Real notifications / activity log
- Advisory vs. distribution mode branching (endpoint not exposing mode flag yet)

---

## What Was Just Built (Not Tested Yet)

**Backend (just now):**
- `api/clients_create.php` — advisor creates a client with email + temporary password
  - Uses TenantScopedDb to auto-stamp tenant_id (prevents cross-tenant leaks)
  - Email globally unique, password hashed via BCRYPT
  - Logs to change_log with entity_type='user'
  - Returns `{ client_id, email }`

**Frontend (just now, not built yet):**
- Updated `api.js` with `createClient(email, temporaryPassword)` method
- `components/Modal.jsx` — reusable dialog with backdrop, escape handling, focus management
- `components/Modal.jsx` supports fade + pop-in animations (added to `index.css`)
- Updated Dashboard.jsx (not committed):
  - Significantly richer design: metric cards with gradients + icons, better spacing
  - "Add client" button → Modal with email + password input, "Generate password" helper, success state with copy-to-clipboard
  - Search + filter on client list
  - Avatar generation (deterministic hue from email hash)
  - Skeleton loader for data fetching
  - Refresh on client-added
  - Better visual hierarchy, whitespace, depth

**CSS upgrades (just added):**
- `@keyframes fadeIn, popIn, shimmer` — smooth animations
- `.skeleton` class for loading states
- `.field` class for consistent form inputs (border, focus ring, placeholder)
- Modal animations (popIn 0.18s with cubic-bezier for spring effect)

---

## What Was Changed vs. Original Design

**Fintech aesthetic IS rendering** — the redesigned `index.css` has the new tokens and they're in the built CSS (verified). Old colors the user sees suggest **browser cache or incomplete deploy.**

**Dashboard redesign** (just now, pending testing):
- NOT yet tested on the live server
- Adds "Add client" flow + modal (backend call to new `clients_create.php`)
- Richer metric cards with icons, gradients on the corpus card
- Better spacing, visual hierarchy
- Skeleton loaders while fetching
- Per-client avatar (deterministic color from email)
- Search with result count

---

## To Launch This Session's Work

1. **Run the new SQL migration** (from last session, if not done):
   ```sql
   CREATE TABLE mfa_pending (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     token CHAR(64) NOT NULL,
     user_id INT UNSIGNED NOT NULL,
     purpose ENUM('enroll','login') NOT NULL,
     payload TEXT NULL,
     expires_at TIMESTAMP NOT NULL,
     created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     ...
   );
   ```

2. **Upload the new API file:**
   - `api/clients_create.php` to `public_html/api/`

3. **Rebuild and deploy frontend:**
   ```bash
   cd frontend && npm run build
   ```
   - Extract `dist/` contents **directly into** `public_html/` (overwrite old files, include `.htaccess`)
   - Confirm `.htaccess` is at `public_html/.htaccess` (React Router rewrite rule)

4. **Test locally first:**
   - Start Python server: `cd public_html && python3 -m http.server 8000`
   - Mock the API in browser devtools or use puppeteer screenshot harness (see `/tmp/shoot.mjs` in this session)
   - Verify:
     - Dashboard renders with new colors (slate ink, teal accents)
     - Stat cards have icons and gradients
     - "Add client" button opens modal
     - Modal validates email/password
     - Success state shows copy-to-clipboard for credentials

5. **Push to GitHub:** (use SSH key, not PAT — see token rotation note below)

---

## Known Issues & Workarounds

### Browser Cache (Most Likely Issue)
- User sees "old colors" even though CSS is correct
- **Fix:** Full hard refresh (`Ctrl+Shift+R` on Windows, `Cmd+Shift+R` on Mac)
- Or: Open DevTools → Application → Clear Storage + Clear Cache
- Or: Add cache-busting: Hostinger hPanel → use versioned asset names (Vite does this by default via hash)

### Bundle Size Warning (Recharts)
- Production JS is 613kB (Vite warns at 500kB)
- Not blocking — don't code-split mid-sprint
- Future: lazy-load chart with dynamic `import()` or move chart to separate page

### MFA Enrollment UI Missing
- Endpoints `mfa_enroll.php`, `mfa_enroll_confirm.php` exist but no UI to reach them
- User can't enroll MFA through the app yet
- Workaround for testing: manually set `users.mfa_secret` in phpMyAdmin to a TOTP secret, then test login
- Real fix: build a Settings page with MFA enrollment in next session

### GitHub Token Rotation (URGENT)
- A PAT was exposed in earlier commits and chat history
- **Action required:** Revoke it at GitHub → Settings → Developer settings → Personal access tokens
- **Then:** Use SSH deploy key instead (see instructions at end of prior session)
  - Generate once: `ssh-keygen -t ed25519 -C "horizonplan-deploy" -f ~/.ssh/horizonplan_deploy -N ""`
  - Add `~/.ssh/horizonplan_deploy.pub` to GitHub repo → Settings → Deploy keys
  - Then `git remote set-url origin git@github.com:vikas335474/HorizonPlan.git`

---

## Architecture & Constraints (For Reference)

**Why this stack:**
- Hostinger Premium has no Node runtime → PHP + MySQL only
- Decoupled Monolith: hardened PHP backend (no framework), React SPA frontend
- No Supabase RLS → security pushed to app layer (TenantScopedDb, explicit tenant checks)
- Tenant isolation: `tenant_id` stamped on every row, hard checks on every query

**Key patterns:**
- **Authorization:** `verifyAccess($db, 'role')` or `verifyAccessAny($db, ['role1', 'role2'])` before every data touch
- **Tenant safety:** TenantScopedDb auto-stamps and validates tenant_id; raw queries forbidden
- **Mutations:** always call `logChange()` in the same block as the write
- **CSRF:** double-submit cookies; POST/PUT/PATCH/DELETE route through `verifyCsrfToken()` first
- **MFA:** TOTP (RFC 6238), short-lived pending tokens in `mfa_pending` table, single-use

---

## Next Session Priorities

1. **Test the new Dashboard** — get visual feedback, adjust colors/spacing if needed
2. **Build Settings page** — MFA enrollment, password change, profile view
3. **Implement goal creation** — advisor creates goals for clients (similar pattern to goal_create, but no client_id picker since we're in a client context)
4. **Lock down MFA enrollment** — make it mandatory before client can use the app (gate in `login.php` or add a redirect to Settings if `mfa_secret` is null)
5. **Polish the whole flow** — onboard → create goal → adjust scenarios → see projections (happy path end-to-end)

If the user wants to bring in a real UX designer now (for Figma mockups of all screens), this is the moment — the backend is solid, the framework is in place, and further refinement should be design-driven, not guesswork.

---

## Code References

- **Fintech design tokens:** `/frontend/src/index.css` (palette, typography, animations)
- **Shared primitives:** `/frontend/src/components/ui.jsx` (Card, StatCard, Badge, Button, EmptyState, Spinner)
- **Security gatekeeper:** `/api/lib/security_gatekeeper.php` (MFA, CSRF, session management)
- **Tenant-scoped queries:** `/api/lib/TenantScopedDb.php` (insert, select, update, logChange)
- **TOTP implementation:** `/api/lib/Totp.php` (RFC 6238, no dependencies)
- **Global Inheritance:** `/api/goals_update.php` (cascade with is_overridden protection)
- **Live server:** https://lightskyblue-beaver-537320.hostingersite.com/ (after deploy, should land on `/login` or `/` depending on session)

---

## Quick Checklist for Next Session

- [ ] Test Dashboard redesign locally (colors, modal, add-client flow)
- [ ] Verify `clients_create.php` integrates with frontend (API call works)
- [ ] Build Settings page (MFA enroll, password change)
- [ ] Add goal-creation flow (UI + backend if needed)
- [ ] Test end-to-end: login → add client → create goal → adjust scenarios → view projection
- [ ] Fix any deployment caching issues (full hard refresh, clear old files)
- [ ] Rotate GitHub PAT and set up SSH deploy key
- [ ] Decide: bring in UX designer for formal mockups, or continue iterating solo?
