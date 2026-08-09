# HorizonPlan Frontend

React SPA (Vite + Tailwind CSS + shadcn/ui) for the B2B2C retirement planning platform. Compiled output is served as static files from Hostinger's `public_html`.

## Architecture

**Same-origin model:** The compiled React app and PHP API sit on the same origin (`https://horizonplan.com/`). Auth rides on httpOnly session cookies (`hp_session`), and CSRF is enforced via double-submit cookie (`hp_csrf`). See `src/lib/api.js` and `DEVELOPER_GUIDE.md` section 2.

**Source of truth for API contract:** `src/lib/api.js` — one method per endpoint; all network calls go through this module. Component code never calls `fetch()` directly.

**File structure:**
```
frontend/src/
├── lib/
│   ├── api.js                  ← ONLY place that talks to backend
│   ├── personalPlanner.js      ← self-serve onboarding Q&A
│   └── strategyPresets.js      ← illustration-framed risk bands
├── context/
│   ├── AuthContext.jsx         ← session bootstrap + useAuth()
│   └── DemoTourContext.jsx     ← guided feature tour (demo only)
├── pages/                      ← top-level routes
│   ├── Home.jsx, LoginPage.jsx, Dashboard.jsx, ClientDetail.jsx, GoalDetail.jsx, …
├── components/                 ← reusable pieces (56 files)
│   ├── GoalCard.jsx, SequenceRiskChart.jsx, ClientPortfolioUI.jsx, …
│   └── ui.jsx                  ← shadcn/ui primitives + Recharts wrappers
└── index.css                   ← Tailwind + custom theme
```

**See `DEVELOPER_GUIDE.md` section 8 for the complete component index with descriptions.**

## Development

```bash
# Install dependencies
npm install

# Start dev server (proxies /api → localhost:8000, see vite.config.js)
npm run dev

# Build for production
npm run build

# Preview production build locally
npm run preview
```

The backend must be running on port 8000 (`php -S localhost:8000 -t ../api`) for the dev proxy to work.

## Key patterns

- **`useAuth()` hook** provides session identity (`user`, `tenant`, `role`, `firm_role`) to every page/component. The session is checked once on app bootstrap; MFA is enforced by `ProtectedRoute` wrapper, which redirects to `/settings` when unenrolled.
- **`api.js` methods** return `{ status, ... }` on success or throw `ApiError` on failure. Components never parse HTTP status codes; the error layer normalizes everything.
- **Role-gating in components** hides advisor-only affordances from client sessions (e.g., edit button in `GoalDetail.jsx` conditional on `isAdvisor`). **The server is the real gate** — every endpoint validates role server-side.
- **File-level header comments** in each page/component describe its role, props, which `api.js` calls it makes, and any role-gating.
- **Charts** use Recharts. Shared primitives (`SequenceRiskChart`, `LifecycleChart`) live in `components/ui.jsx`.
- **Disclosure banner** (`DisclosureBanner.jsx`) is required on every client-facing view (render it once per route that shows a plan).
- **Plain-language copy** for self-serve individuals (no "decumulation", no "corpus" on screen). Advisor views use full planning terminology.

## Deployment

The **CI pipeline** (`.github/workflows/deploy.yml`) automatically builds the React app on every push to `main` (when `frontend/`, `api/`, or the workflow itself changes) and publishes the compiled `dist/` to a `deploy` branch, which Hostinger's git-deploy watches. **The server does not run `npm run build` — it must be pre-compiled.**

For manual deployment: build locally (`npm run build`), then upload `dist/` to Hostinger's `public_html/` root via File Manager (watch for the hidden `.htaccess`).

See `DEPLOY.md` for full setup.

## Styling

**Tailwind CSS** for utility classes. **shadcn/ui** for pre-built components (buttons, modals, dropdowns, tables). **Custom theme** in `index.css` (color variables, overrides). Both light and dark themes via CSS variables.

## Testing

Component unit tests are **deferred**. The codebase's verification bar is a real Playwright browser run against actual dev servers (§verification in `CLAUDE.md`).

## Auth & security

- **Session:** httpOnly cookie set by `api/session.php` (8h TTL, SameSite=Strict)
- **CSRF:** double-submit cookie (`hp_csrf`), validated on every non-GET. `api.js` attaches the header automatically.
- **MFA:** enforced by `ProtectedRoute.jsx` (app-layer redirect to `/settings` if unenrolled). Server returns 202 + `mfa_required` flag if MFA is needed.
- **Roles:** `super_admin` (platform settings), `advisor` (firm tenant, subdivided by `firm_role`), `client` (self-serve or advisor-managed). Role gates are **UX only**; the server enforces every gate.

See `DEVELOPER_GUIDE.md` section 4–5 for the full security model.

## Troubleshooting

**Vite dev server not proxying to PHP backend:**
- Ensure `vite.config.js` has `/api` → `localhost:8000` proxy rule
- Ensure PHP server is running: `php -S localhost:8000 -t ../api`
- Check browser console for CORS errors (should be none; same-origin model)

**Build succeeds locally but 404s on Hostinger:**
- Compiled `dist/` files must land at `public_html/` root (not a subdirectory)
- Check that `.htaccess` was copied (it's a hidden dotfile; File Manager may drop it)
- Ensure `/assets/` paths are absolute (Vite default); relative paths break with the current hosting setup

**Session lost after deployment:**
- Session cookie depends on `APP_BASE_URL` in `api/db_config.php` being correct
- Verify the session cookie domain in browser DevTools (should match your Hostinger domain)

## Further reading

- `DEVELOPER_GUIDE.md` — architecture, request lifecycle, test harness, local dev setup
- `docs/02` — data model, tenant isolation, security rules (read before touching auth)
- `docs/04` — feature roadmap and out-of-scope list
- `docs/11` — personalisation plan (self-serve user input + sourced reference data)
