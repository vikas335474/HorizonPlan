# Deploying HorizonPlan

HorizonPlan is a pre-compiled React SPA served **same-origin** with the PHP API
out of Hostinger's `public_html`. Hostinger has no Node runtime, so the frontend
is built in CI and the *compiled* output is published to a dedicated **`deploy`
branch** that mirrors `public_html` exactly. Hostinger's native Git deployment
pulls that branch — no manual file-moving, and `.htaccess` is never dropped.

```
Source (main)                  CI (GitHub Action)                deploy branch  ==  public_html
  frontend/  ── npm run build ─► frontend/dist/  ─┐
  api/       ────────────────────────────────────┼─► _site/  ── push ─►  /index.html
                                                  │                       /assets/…
                                                  │                       /.htaccess
                                                  └─────────────────────► /api/…
```

## How the automated pipeline works

`.github/workflows/deploy.yml` runs on every push to `main` that touches
`frontend/`, `api/`, or the workflow itself (and can be run on demand from the
Actions tab). It:

1. `npm ci && npm run build` in `frontend/`.
2. Assembles a `public_html`-shaped tree: `frontend/dist/` contents at the root
   (including the `.htaccess` dotfile) plus `api/` under `/api`.
3. Publishes it to the `deploy` branch, **building on top of that branch's
   history** (never force-pushing) so Hostinger's `git pull` always
   fast-forwards.

`api/db_config.php` is explicitly stripped before publishing — the real
credentials file lives only on the server (see below).

## One-time Hostinger setup (do this once)

1. **hPanel → Advanced → GIT.**
2. **Create a new repository:**
   - Repository: your GitHub repo URL (`https://github.com/vikas335474/HorizonPlan.git`).
     For a private repo, use a deploy key / access token per Hostinger's Git help.
   - Branch: **`deploy`**  ← not `main`.
   - Directory: **`public_html`**  (leave blank if it defaults there).
3. Click **Create**. Hostinger clones the `deploy` branch into `public_html`.
4. **Create the credentials file once, on the server** (it is intentionally not
   in Git). In hPanel → File Manager, copy `public_html/api/db_config.example.php`
   to `public_html/api/db_config.php` and fill in the real DB host / name / user /
   password. Every future deploy is a `git pull`, which only updates *tracked*
   files, so this untracked file is never touched.
5. **Enable Auto-Deployment** (toggle in the Git panel) so each push to `deploy`
   pulls automatically. Without it, click **Deploy** in the Git panel after a
   build finishes.

After this, the flow is just: merge to `main` → Action builds → `deploy` updates
→ Hostinger pulls. Nothing to move by hand.

## Database migrations

SQL in `/sql` is **not** run automatically. After any deploy that adds a
migration, run it manually via hPanel → Databases → phpMyAdmin.

## Staging environment setup

Production and admin/dev/testing activity have shared one instance for a
while — fine when the only traffic was admin-created tenants and the seeded
demo dataset, less fine now that real self-serve trial signups (see the
"Self-serve trial signup" session in `CLAUDE.md`) can land on the same
database. Staging is a **second, fully separate** site: its own subdomain,
document root, MySQL database, and `db_config.php` — never the production
database with a different URL in front of it. It reuses the exact same
deploy mechanism as production (a dedicated publish branch Hostinger's Git
deployment pulls), just pointed at a different branch and directory.

```
Source (develop)               CI (GitHub Action)              deploy-staging branch == staging subdomain's docroot
  frontend/  ── npm run build ─► frontend/dist/  ─┐
  api/       ───────────────────────────────────┼─► _site/  ── push ─►  /index.html, /assets/…, /.htaccess, /api/…
                                                  (VITE_APP_ENV=staging, so AppHeader shows a "STAGING ENVIRONMENT" banner)
```

**One-time Hostinger setup:**

1. **hPanel → Domains → Subdomains.** Create a subdomain (e.g.
   `staging.yourdomain.com`). Hostinger creates its own document root, separate
   from `public_html` — note the path it gives you (e.g.
   `public_html/staging` or a sibling folder, depending on plan).
2. **hPanel → Databases → MySQL Databases.** Create a **new** database + user,
   distinct from production's. Nothing here should ever point at the
   production database.
3. **Run all of `/sql` against the new database**, in order, via phpMyAdmin —
   same manual process as any production migration, just once, against an
   empty schema.
4. **hPanel → Advanced → GIT.** Create a second Git deployment:
   - Repository: this repo's URL.
   - Branch: **`deploy-staging`** — not `deploy`, not `main`.
   - Directory: the staging subdomain's document root from step 1.
   - Enable Auto-Deployment, same as production.
5. **Create `public_html/staging/api/db_config.php` on the server** (copy
   `db_config.example.php`, fill in the *staging* database's credentials —
   never the production ones). Untracked, exactly like production's, so every
   `git pull` leaves it alone.
6. **Push a `develop` branch** in this repo (branched off `main` is fine to
   start) — `deploy-staging.yml` builds and publishes on every push to it,
   mirroring `deploy.yml`'s `main` → `deploy` flow exactly. Merge to `develop`
   first to try changes on staging before promoting the same commit to `main`.

After this, the flow is: push to `develop` → Action builds with
`VITE_APP_ENV=staging` → `deploy-staging` updates → Hostinger pulls into the
staging subdomain. The amber "DEMO ENVIRONMENT" banner (`platform_settings.
demo_mode`) and this dark "STAGING ENVIRONMENT" banner are independent — a
staging database can also have `demo_mode` on if useful for testing that
banner itself, but staging's own identity comes from the build flag, not from
any database row, so it renders even before a database connection exists.

**Populating staging with data:** run `php tools/seed_demo_data_full.php`
against the staging database (SSH/CLI) for a realistic, full-featured
dataset, or `php tools/bootstrap_admin.php` for a single clean admin account
to build up manually. Staging is exactly where to point anything that would
be too risky to run against production first — a new migration, the demo
reset endpoint, a rate-limit change — before it goes anywhere near real data.

## Daily MF NAV price-sync cron

`tools/mf_nav_sync.php` keeps mutual fund holdings' displayed value current by
pulling AMFI's daily NAV export — scoped, on purpose, to only the schemes
actually held in someone's portfolio (`client_portfolio_items.amfi_scheme_code`),
never AMFI's entire scheme universe. See `api/lib/MfNavSync.php` for the full
design writeup and `CLAUDE.md`'s "Daily MF NAV price-sync cron" session for
the reasoning.

**hPanel → Advanced → Cron Jobs → Create a new cron job:**
- Command: `php /home/<your-hostinger-user>/public_html/tools/mf_nav_sync.php`
  (adjust the path to wherever `public_html` actually is — check hPanel's own
  cron-job form, which usually shows the exact absolute path for you).
- Schedule: once daily, after AMFI typically publishes the day's NAVs — e.g.
  `30 21 * * *` (9:30 PM server time). AMFI does not publish on a fixed
  minute, so a run that finds no new NAV for a scheme yet just means try
  again tomorrow — it never overwrites the existing cache with a guess.
- A failed run (AMFI unreachable, network blip) is a safe no-op — it leaves
  the existing `mf_nav_cache` and every portfolio's displayed value exactly
  as they were, logged to the cron's own output/stderr, never silently
  zeroed or guessed at.

This is CLI-only, same as `tools/bootstrap_admin.php` and
`tools/seed_demo_data_full.php` — never reachable over HTTP, and never
shipped by the deploy pipeline (only `api/lib/MfNavSync.php`, which the two
API endpoints that need it in-request also `require_once`, is; `tools/`
itself stays server-side-only, run via cron or SSH).

## Google Sign-In setup

Google Sign-In (api/auth_google.php) needs one OAuth 2.0 Client ID, created
once in [Google Cloud Console](https://console.cloud.google.com/) → APIs &
Services → Credentials → Create Credentials → OAuth client ID → **Web
application**. Add the production domain (and `http://localhost:5173` /
whatever local dev origin is used) under **Authorized JavaScript origins** —
no redirect URI is needed, since the frontend uses Google Identity Services'
token flow, not a server-side redirect.

The resulting Client ID is not a secret (it ships inside the built frontend
JS), but it's still per-deployment config, kept out of git the same way
`db_config.php` is:
- Backend: set `GOOGLE_CLIENT_ID` in `api/db_config.php` (see
  `api/db_config.example.php`).
- Frontend: set `VITE_GOOGLE_CLIENT_ID` in `frontend/.env` before running
  `npm run build` (see `frontend/.env.example`).

Leaving either unset disables Google Sign-In cleanly — `auth_google.php` 503s
rather than accepting a token it can't actually verify, and the frontend
button doesn't render without a configured Client ID.

## Troubleshooting

### Site-wide `403` — "Access to this resource on the server is denied!"
This is a server/LiteSpeed condition, not an app bug (it persists even with no
`.htaccess`). Isolate it: put a plain `public_html/test.txt` containing `hello`
and open `/test.txt`.
- **`test.txt` also 403s** → `public_html` (or its files) has wrong
  **permissions or ownership** — e.g. files owned by `root` after a manual move,
  or the folder not `755`. File Manager can't change ownership; **Hostinger
  support fixes this in ~2 minutes.** Ask them to "check the document root and
  fix ownership/permissions on public_html."
- **`test.txt` serves** → only `index.html` is the problem: confirm it's really
  at the `public_html` root, lowercase, and `644`.

Switching to the Git deployment above avoids the usual cause entirely, because
files arrive via `git pull` (correct ownership, dotfiles intact) instead of a
manual drag that can leave root-owned files and strip the `.htaccess`.

### Blank page, JS/CSS 404
The compiled `index.html` references `/assets/…` (absolute paths). Every file
from the build must sit at the `public_html` **root** together — never leave
`index.html` at the root while `assets/` stays in a subfolder. The pipeline
guarantees this; it only happens with manual uploads.

A second cause hit the automated pipeline itself in the past: the publish
step's `rsync` didn't pass `--checksum`, so its default size+mtime quick-check
occasionally treated a changed `index.html` as unchanged (same byte size
build-to-build, mtime landing in the same CI second as the worktree checkout)
and skipped re-publishing it — while `assets/*.js`/`*.css` kept rotating to
new hashed filenames underneath it, since those are always "new" files to
rsync. The symptom: the live site's `index.html` references an asset hash
that no longer exists in `assets/` because a later deploy deleted it. Fixed by
adding `--checksum` to the `rsync` call in `deploy.yml`, which compares actual
file content instead of size/mtime. If this ever recurs, compare the deployed
`deploy` branch's `index.html` script/link tags against what's actually in its
`assets/` directory — a mismatch there is this bug, not a Hostinger caching
issue.
