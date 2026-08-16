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

`api/db_config.php` **is committed and does ship** — see "Database credentials"
below for why that's correct, not a leak. It contains no secrets.

## One-time Hostinger setup (do this once)

1. **hPanel → Advanced → GIT.**
2. **Create a new repository:**
   - Repository: your GitHub repo URL (`https://github.com/vikas335474/HorizonPlan.git`).
     For a private repo, use a deploy key / access token per Hostinger's Git help.
   - Branch: **`deploy`**  ← not `main`.
   - Directory: **`public_html`**  (leave blank if it defaults there).
3. Click **Create**. Hostinger clones the `deploy` branch into `public_html`.
4. **Set up the credentials file** — see "Database credentials" below. Do this
   *before* step 5, or the first auto-deploy pull will find nothing to load and
   every page will 500.
5. **Enable Auto-Deployment** (toggle in the Git panel) so each push to `deploy`
   pulls automatically. Without it, click **Deploy** in the Git panel after a
   build finishes.

After this, the flow is just: merge to `main` → Action builds → `deploy` updates
→ Hostinger pulls. Nothing to move by hand.

## Database credentials

**This is not a plain git-ignored file living on the server.** That was the
original design, and it broke in production: Hostinger's Auto-Deployment does
not do a plain `git pull` — it resets/cleans the working tree on every push
(equivalent to `git clean -fd`), which wipes *any* untracked file inside
`public_html`, credentials included, no matter what it's named or how it's
permissioned. `chmod 600` does not help — permissions control who can *read* a
file, not whether git treats it as disposable. This surfaced as the daily NAV
sync cron suddenly failing with `FAIL: api/db_config.php not found` after a
deploy that never touched that file directly.

**The fix:** `api/db_config.php` is now a small, git-**tracked**, secret-free
*loader* (full reasoning in its own header comment). It requires an absolute
path **outside `public_html` entirely** — a location Hostinger's reset can
never reach because it isn't inside the git-managed directory at all. Every
`api/*.php` and `tools/*.php` file still requires the same relative path as
before (`db_config.php`); only that file's *content* changed, from "the real
secrets" to "a pointer to where the real secrets now live". Nothing on the
server needs editing after a deploy — the loader's path is a constant, set once
in a commit, and every future deploy carries it forward unchanged.

**One-time server setup, over SSH** (Hostinger Premium includes SSH access;
find it in hPanel → Advanced → SSH Access):

```bash
ssh yourusername@yourdomain.com

# Find your real home directory — Hostinger's layout varies by account
# (some are ~/public_html directly, others ~/domains/yourdomain.com/public_html)
pwd
find ~ -maxdepth 4 -iname "public_html" -type d

# Create the credentials file OUTSIDE public_html — a sibling to it, or
# anywhere else outside that tree. Example, given ~/domains/yourdomain.com/public_html:
cd ~/domains/yourdomain.com
cp public_html/api/db_config.example.php db_config.php
nano db_config.php
#   …fill in the real DB_HOST / DB_NAME / DB_USER / DB_PASS from
#   hPanel → Databases → MySQL Databases. GOOGLE_CLIENT_ID may stay empty.

chmod 600 db_config.php
```

Then edit `api/db_config.php`'s candidate list (the loader) to point at that
exact absolute path, commit, and push — the loader ships the same value on
every future deploy, so this is a one-time change to the *repo*, never to the
server.

**To move or rotate this file later:** edit the path in `api/db_config.php`,
commit, push — never edit the path by hand on the server; the next deploy would
silently revert it back to whatever's in git.

**To verify it worked**, over SSH:

```bash
cd ~/domains/yourdomain.com/public_html
php tools/mf_nav_sync.php
```

A real result (`MF NAV sync: done. ...`) confirms the loader found the file and
connected. `FAIL: No database credentials file found. Checked: ...` lists every
path it tried, in order — the fastest way to see exactly what's misconfigured.

## Database migrations

SQL in `/sql` is **not** run automatically. After any deploy that adds a
migration, run it manually via hPanel → Databases → phpMyAdmin.

Nothing records which migrations a given database has already had applied, and
they are **not idempotent** — there are no `IF NOT EXISTS` guards, so re-running
an applied migration raises a duplicate column/table error instead of quietly
doing nothing. The error is harmless, but don't discover the state that way.

**To find out what a database is missing**, paste
[`sql/check_applied_migrations.sql`](sql/check_applied_migrations.sql) into
phpMyAdmin. It is read-only, and reports one row per schema marker:

```
migration                                                          state
033_personal_tenants        (tenants.kind)                         applied
034_financial_foundations   (client_protection table)              MISSING
034_financial_foundations   (client_portfolio_items.interest_rate) MISSING
035_household_self_service  (users.display_name)                   MISSING
```

Apply anything marked `MISSING` in ascending numeric order, one file at a time,
checking each succeeds before starting the next. 034 carries two markers because
it makes two structural changes and can fail between them — a `CREATE TABLE`
that lands followed by an `ALTER` that doesn't would otherwise look complete.

### Currently pending on production

As of the self-serve individual and household work, **033, 034 and 035 have not
been applied to the production database**. Until they are, the deployed app will
error on any page touching a personal tenant, the financial-foundations cards,
or partner invites — the frontend ships those features whether or not the schema
is there. Run the check above, then apply what it reports missing:

| Migration | Adds | Feature it gates |
|---|---|---|
| `033_personal_tenants.sql` | `tenants.kind` | Self-serve individual tier (a "tenant of one") |
| `034_financial_foundations.sql` | `client_protection`, `client_portfolio_items.interest_rate` | Emergency reserve / cover / costly-debt checks |
| `035_household_self_service.sql` | `users.display_name` | Couples planning together in one personal tenant |

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

## Running a one-off script without SSH

Hostinger Premium has **no SSH**, so there is no way to run a PHP CLI script on
demand — only scheduled cron jobs. Two things follow.

**1. `tools/` is now published by the deploy pipeline.** It previously was not,
which meant every cron path documented below (`public_html/tools/<name>.php`)
did not exist on the server and no cron could ever have run. If your NAV sync
or progress snapshot has been silently doing nothing, this is why. Fixed in
`.github/workflows/deploy.yml`; the next push to `main` publishes them.

Serving them from `public_html` is safe because every tool opens with a
`PHP_SAPI !== 'cli'` guard that 403s an HTTP request. The workflow now
**asserts** that guard on every file it ships, so a new tool that forgets it
fails the build rather than quietly becoming a public endpoint. Seed and admin
scripts (`seed_demo_data*`, `bootstrap_admin`, `set_password`, `check_login`,
`seed_templates`) are deliberately stripped and never reach production.

**2. To run a sync once, use a throwaway cron.** Schedule it a few minutes out,
let it fire, then delete the cron job. That is the supported path for a
first-time population.

**Or paste the SQL.** For `reference_costs` specifically there is a generated
equivalent at **`sql/manual/reference_costs_seed.sql`** — paste it into
hPanel's database tool and the cache is populated with no CLI at all. It is
generated from the same dataset the sync writes, so it cannot drift by a
transcription error, and it is an upsert, so it is safe to run more than once
and safe to run before or after the real sync.

> **Apply `sql/043` before this seed (or use the copy embedded at the top of
> it).** Migration 037 created `reference_costs.unit` as `VARCHAR(20)`, and the
> living-cost rows carry `inr_monthly_per_capita` — 22 characters. Without the
> widening ALTER, MySQL rejects all 15 living-cost rows with
> `SQLSTATE[22001] Data too long for column 'unit'`, and the
> `reference_costs_sync` cron fatals part-way through on every run. The seed
> file repeats the ALTER at its top so pasting that one file is sufficient.

Regenerate the seed after changing the dataset:

```bash
php -r 'require "api/lib/ReferenceCosts.php"; /* see the file header */' \
  # the full generator command is in the file's own header comment
```

The other syncs (`tax_reference_sync`, `mf_nav_sync`) have no pasteable
equivalent — use the throwaway-cron method for those.

---

## Monthly goal-progress snapshot cron

`tools/progress_snapshot.php` records, once a month, what each goal's corpus
actually was and what the plan expected it to be on that date — the history
behind the "Progress over time" chart and the dashboard's "Behind plan" badge
(docs/10 P1-1). Without it the feature still works, but only from readings an
advisor takes by hand via "Record now"; the cron is what makes the series
accumulate on its own.

**hPanel → Advanced → Cron Jobs → Create a new cron job:**
- Command: `php /home/<your-hostinger-user>/public_html/tools/progress_snapshot.php`
  (same path caveat as the NAV cron below).
- Schedule: monthly, after the daily NAV sync has run so portfolio values are
  fresh — e.g. `0 3 1 * *` (3 AM on the 1st). Monthly, not daily, is
  deliberate: a plan's corpus moves in steps, so a daily series would be
  thousands of identical rows saying nothing, and a review conversation works
  off months.
- Safe to run more than once. Capture is idempotent per (goal, date) and per
  (client, date) — a re-run updates that day's rows instead of duplicating
  them, so a retried or overlapping cron cannot corrupt the series.
- Rows written by the cron carry `created_by_user_id = NULL`, which is how the
  UI distinguishes a scheduled reading from one an advisor took before a
  meeting.
- No backfill, ever: history starts the first time a snapshot runs. The app
  does not invent readings for dates nobody observed.

## Monthly plan-digest cron (self-serve individuals)

`tools/personal_digest_send.php` emails a self-serve individual a short,
factual summary of where their plan stands (docs/13 I-9). It exists because
the snapshot cron above has been recording each person's progress and telling
them nothing about it — with no nudge, the self-serve tier had no reason for
anyone to come back.

**hPanel → Advanced → Cron Jobs → Create a new cron job:**
- Command: `php /home/<your-hostinger-user>/public_html/tools/personal_digest_send.php`
- Schedule: monthly, a few days AFTER the snapshot cron so the digest
  describes fresh facts rather than last month's — e.g. `0 6 4 * *` (6 AM on
  the 4th, given a snapshot on the 1st).
- **Nobody is emailed unless they asked.** The recipient query requires
  `users.plan_digest_opt_in = 1` (sql/041, default **0**) and a
  `kind='personal'` tenant. Applying the migration cannot start mailing
  anyone; a firm's client is never included, because their adviser owns the
  follow-up (that is what the plan-review mailer is for).
- Safe to run more than once: a person is only due when their last send is at
  least a month old, so a same-day re-run mails nobody twice. A send that
  fails is *not* marked sent, so the next run retries it rather than losing a
  month.
- Someone with nothing worth reporting is skipped and **not** marked sent —
  silence rather than a "no change this month" email, and they are
  reconsidered next run.
- Run it once with `--dry-run` first on a real database: it prints each body
  and mails nothing.
- Demo tenants are protected regardless, because `sendMail()` itself
  suppresses delivery while `platform_settings.demo_mode` is on.

This is CLI-only and never shipped by the deploy pipeline, same as the NAV
cron below (only `api/lib/ProgressSnapshot.php` ships, which
`api/progress_capture.php` also uses in-request for "Record now").

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

## Sourced reference-cost library sync

`tools/reference_costs_sync.php` pushes the curated, cited cost-range dataset
(docs/11 Prompt E-3 — education by driver, healthcare) into `reference_costs`
(sql/037), the cache `api/lib/PersonalisationReference.php`'s education
lookup reads first (falling back to its own constants if this cache is
empty) and `reference_costs_get.php` serves directly. Unlike the NAV sync,
there is no single live API for AICTE/NMC/MOSPI-style figures — the dataset
lives in `api/lib/ReferenceCosts.php`, curated and cited, and this job's only
real work is upserting it into the DB cache the app actually reads. A row
seeds as `is_verified` per the dataset's own value (the education rows carry
`true`/`false` inherited from the docs/11 Prompt E-2 reconciliation — see
`ReferenceCosts.php`'s header) until a human checks it against the named
source and flips the flag by hand — a re-run never resets an existing row's
`is_verified` either way (see the ON DUPLICATE KEY UPDATE in
`syncReferenceCosts()`). The sync also **prunes** any cached row whose
(category, subcategory) the dataset no longer defines — safe because this
table is entirely CLI-cron-owned, no user data ever lands in it.

**hPanel → Advanced → Cron Jobs → Create a new cron job:**
- Command: `php /home/<your-hostinger-user>/public_html/tools/reference_costs_sync.php`
  (adjust the path as with the NAV cron above).
- Schedule: infrequent — the dataset only changes when someone edits
  `api/lib/ReferenceCosts.php` (a new verified range, a new driver). Monthly
  (e.g. `0 4 1 * *`) is plenty; there's no daily-freshness requirement like
  the NAV sync's AMFI export.
- Safe to run any time, including manually via SSH — it's a deterministic
  upsert (+ prune) of a constant dataset, not a live fetch. A DB error
  mid-run rolls back the whole batch (transaction owned by this script, not
  `syncReferenceCosts()` itself), leaving the existing cache untouched rather
  than half-written.
- Must run once after `sql/037_reference_costs.sql` is applied — before that,
  the cache is empty, `PersonalisationReference` runs entirely on its own
  fallback constants, and `reference_costs_get.php` returns nothing (never a
  fabricated range).
- **Note (city tier):** `reference_costs` deliberately holds no
  `city_expense_multiplier` rows — see `ReferenceCosts.php`'s header for why
  that figure stays a pure code-derived formula, not a cached range.

Same CLI-only, never-shipped-by-deploy posture as the NAV cron
(`api/lib/ReferenceCosts.php` is the shared part; `api/reference_costs_get.php`
`require_once`s it for the in-request read; `tools/` stays server-side-only).

## Sourced tax-reference sync

`tools/tax_reference_sync.php` pushes the curated, cited tax-treatment
dataset (docs/12 Prompt D-2 — capital-gains/holding-period treatment per
instrument category, mutual funds split into equity/debt/hybrid) into
`tax_reference` (sql/039), the cache `client_portfolio_list.php` reads (via
`api/lib/PortfolioTaxContext.php`) to attach a `tax_context` block to every
asset row. Same shape and precedent as the reference-cost sync above: no
live API for Finance Act/CBDT-style rules, so the dataset lives in
`api/lib/TaxReference.php`, curated and cited, and this job upserts it into
the DB cache the app actually reads — **facts only, never a filing figure or
a "sell now" prompt** (see `PortfolioTaxContext.php`'s header for the exact,
deliberate boundary: an illustrative unrealised gain on what's still held,
never a realised "how much LTCG exemption is left this year" number, since
this app has no transaction ledger to know that). Every row seeds
`is_verified` from the dataset (currently `false` on all 14 rows — Indian
tax rules changed substantially in the 2023/2024 budgets and change again
essentially every budget after that); a re-run never resets an
already-verified row. The sync also **prunes** any cached row the dataset no
longer defines, same as the reference-cost sync.

**hPanel → Advanced → Cron Jobs → Create a new cron job (optional):**
- Command: `php /home/<your-hostinger-user>/public_html/tools/tax_reference_sync.php`
  (adjust the path as with the NAV cron above).
- Schedule: **no fixed schedule needed** — Indian tax rules change only at
  Union Budget time (once a year, occasionally an in-year amendment), so
  running this by hand via SSH right after editing `TaxReference.php` for a
  rate correction is enough. A cron entry is a convenience, not a
  requirement, unlike the daily NAV sync.
- Safe to run any time — a deterministic upsert (+ prune) of a constant
  dataset, not a live fetch. A DB error mid-run rolls back the whole batch,
  leaving the existing cache untouched rather than half-written.
- Must run once after `sql/039_tax_context.sql` is applied — before that,
  `tax_context` on every portfolio item reads `applicable: false` for
  everything (never a fabricated treatment note).
- **Before relying on the exact rates/thresholds with a real client:** verify
  every row against the current Finance Act / CBDT guidance and flip
  `is_verified` by hand in the DB — the UI keeps showing "illustrative —
  verify against current rules" on every unverified row.

Same CLI-only, never-shipped-by-deploy posture as the other sync tools
(`api/lib/TaxReference.php` and `api/lib/PortfolioTaxContext.php` are the
shared parts `client_portfolio_list.php` `require_once`s for the in-request
read; `tools/` stays server-side-only).

## Scheduled plan-review emails cron

`tools/plan_review_send.php` sends the periodic "your plan, refreshed" review
emails (docs/10 P0-3). It emails **only** clients an advisor has explicitly
opted in — a per-client cadence of `quarterly` or `annually`, set on the
client's page in-app — whose review is due, and links each one to their own
self-service login. Off by default; nobody is ever auto-enrolled. See
`api/lib/PlanReviewMailer.php` for the logic.

**Before enabling:** set `APP_BASE_URL` in `api/db_config.php` (see
`api/db_config.example.php`) to the deployed app's absolute URL — it's the
login link in the email. Without it the email ships a placeholder link.

**hPanel → Advanced → Cron Jobs → Create a new cron job:**
- Command: `php /home/<your-hostinger-user>/public_html/tools/plan_review_send.php`
  (adjust the path as with the NAV cron above).
- Schedule: once daily is plenty — e.g. `0 8 * * *` (8 AM server time). Cadence
  is measured from each client's own last-sent date, so a daily run simply
  picks up whoever crossed their quarterly/annual mark that day; it never
  double-sends within a period.
- When `platform_settings.demo_mode = 'on'`, every email is suppressed (logged,
  not sent) by the shared `Mailer` — a demo/staging environment never emails a
  real inbox, and the client is still marked as "reviewed" so it doesn't pile up.

Same CLI-only, never-shipped-by-deploy posture as the NAV cron
(`api/lib/PlanReviewMailer.php` is the shared part; `tools/` stays
server-side-only).

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
