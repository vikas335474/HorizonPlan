#!/usr/bin/env bash
# Runs the whole PHP test suite. Pure tests (PlanMath, TOTP, hashing, tenant
# isolation, cascade) always run; the DB integration test self-skips when no
# database is configured. Any real failure exits non-zero, so this is safe to
# wire into CI.
set -u

cd "$(dirname "$0")/.."

status=0
for t in \
  tests/test_plan_math.php \
  tests/test_totp.php \
  tests/test_password_hashing.php \
  tests/test_inheritance_cascade.php \
  tests/test_tenant_isolation.php \
  tests/test_auth_db.php
do
  echo "──────────────────────────────────────────────"
  echo "▶ $t"
  php "$t" || status=1
done

echo "──────────────────────────────────────────────"
if [ "$status" -eq 0 ]; then
  echo "✔ All tests passed (DB-dependent tests may have self-skipped)."
else
  echo "✗ Some tests failed."
fi
exit "$status"
