# HorizonPlan

B2B2C retirement planning platform for Indian MFDs/IFAs and SEBI-RIA firms. See `CLAUDE.md` for the standing rules and `/docs` for full context before working on any area.

## Structure
```
api/
  lib/          Shared helpers — security, tenant-scoped data access. Every endpoint routes through these.
  (endpoint files, added per-phase)
frontend/        React SPA (Tailwind, shadcn/ui). Pre-compiled — see .github/workflows for the build step.
sql/             Numbered migration files, run in order. Not auto-applied by Hostinger's git-deploy — see CLAUDE.md.
docs/            Full planning/architecture/roadmap documentation.
```

## Local setup
1. Create a local MySQL database and run the migrations in `sql/` in numeric order.
2. Copy `api/db_config.example.php` to `api/db_config.php` (git-ignored) and fill in local DB credentials.
3. PHP built-in server for local dev: `php -S localhost:8000 -t api`
4. Frontend: `cd frontend && npm install && npm run dev`

## Tests
`tests/test_tenant_isolation.php` is a functional regression test against a real database (not a mock) — it verifies cross-tenant isolation actually holds (a scoped update can't touch another tenant's row), that the allow-list rejects unscoped tables, and that session issuance and login rate-limiting behave correctly. Run it after setting up `api/db_config.php` pointed at a scratch/test database — it deletes existing rows in the tables it touches, so don't point it at real data:
```
php tests/test_tenant_isolation.php
```

## Deploy
See `CLAUDE.md` — Hostinger git-deploy watches the `deploy` branch, not `main`.
