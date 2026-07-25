# Phase 1 Deployment Checklist

After merging the feature branch to `main`, follow these steps to deploy to Hostinger and test the strategy template system.

## Step 1: Merge & Push (triggers GitHub Actions build)

```bash
git checkout main
git pull origin main
git merge --no-ff origin/claude/horizonplan-phase1-templates-p2bv3c
git push origin main
```

Watch the [GitHub Actions tab](https://github.com/vikas335474/HorizonPlan/actions) — the `deploy.yml` workflow should run, compile the React app, and publish to the `deploy` branch automatically (~1–2 min).

## Step 2: Hostinger Git Pull

In hPanel → Advanced → Git (in the Git deployment you set up per DEPLOY.md):
- Click **Deploy** manually, OR if auto-deployment is enabled, it pulls automatically after ~30s.

Check that `/public_html` now has:
- `index.html`, `/assets/`, `.htaccess` (compiled frontend)
- `/api/` with the new `templates_*.php` files

## Step 3: Run SQL Migrations (in order)

Via hPanel → Databases → phpMyAdmin, run these three migrations in sequence:

1. `sql/010_template_strategies.sql` — Creates `template_strategies` table
2. `sql/011_template_customizations.sql` — Creates `template_customizations` table
3. `sql/012_template_audit_log.sql` — Creates `template_audit_log` table

After each, confirm "✓ SQL executed successfully."

## Step 4: Seed Global Templates

Via hPanel → Terminal (if SSH access is enabled) or File Manager → upload the seed script:

```bash
php /home/user/public_html/tools/seed_templates.php
```

Expected output:
```
[✓] Created: Conservative – Capital Preservation (Age 55+)
[✓] Created: Moderate – Balanced Growth (Age 45–55)
[✓] Created: Balanced – Moderate Growth (Age 35–45)
[✓] Created: Aggressive – Growth Focused (Age 25–35)
[✓] Created: Ultra-Aggressive – Maximum Growth (Age 20–30)

✔ Seeded 5 global strategy templates.
   Advisors can now see these in the Global Library tab and fork/customize them.
```

## Step 5: Test the UI

1. **Sign in as Super Admin**
   - Go to `/admin`
   - Click **Strategy Templates** tab (new tab alongside Firms)
   - Should see 5 templates listed with allocation bars, risk profiles, and publish status

2. **Create a custom global template (admin only)**
   - Click **+ New template**
   - Fill in: name, allocation (e.g., `equity 50, debt 30, gold 15, cash 5`), return %, risk profile
   - **Check** "Publish immediately"
   - Click **Create template**
   - Confirm it appears in the list and is marked "published"

3. **Sign in as Advisor**
   - New nav link: **Templates** (top bar, between clients list and Settings)
   - Click it → **Global Library** tab
   - Should see all 6 templates (5 seeded + 1 you created)
   - Click **Fork** on any template
   - Customize name and allocation
   - Click **Fork template**
   - Go to **My Customized** tab → should see your fork

4. **Verify cascade & audit**
   - In **My Customized**, click **Provenance** on your fork
   - Should see "forked_from" entry with the base template name
   - Click **Edit**
   - Change return assumption %
   - Save
   - Click **Provenance** again → should see "customized" entry

## Rollback (if needed)

If something breaks, you can revert `deploy` to the previous commit:

```bash
git checkout deploy
git reset --hard HEAD~1
git push -f origin deploy
```

Then Hostinger will pull the older deploy commit on next sync.

## Notes

- **No template data in the DB yet?** If the templates aren't showing after step 4, check:
  - Did the SQL migrations run without error?
  - Does `api/db_config.php` have correct credentials?
  - Check hPanel → Databases → phpMyAdmin → `horizonplan` database → `template_strategies` table for rows.

- **Blank admin Templates tab?** The API calls may be failing. Check:
  - Browser DevTools → Network tab → does `templates_list.php` return 200?
  - Check Hostinger error logs: hPanel → Files → `/public_html/error_log`

- **404 on `/templates` route?** The frontend route isn't in the compiled output — re-run step 1 (merge/push) and confirm the GitHub Action completed successfully.
