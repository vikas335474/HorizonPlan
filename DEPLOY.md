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
