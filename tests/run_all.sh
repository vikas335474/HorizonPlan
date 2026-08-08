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
  tests/test_accumulation.php \
  tests/test_goal_field_validation.php \
  tests/test_risk_profile_scoring.php \
  tests/test_corpus_composition.php \
  tests/test_target_goal_funding.php \
  tests/test_goal_progress.php \
  tests/test_totp.php \
  tests/test_password_hashing.php \
  tests/test_inheritance_cascade.php \
  tests/test_tenant_isolation.php \
  tests/test_auth_db.php \
  tests/test_mfa_enforcement_db.php \
  tests/test_google_auth_validation.php \
  tests/test_google_auth_db.php \
  tests/test_signup_db.php \
  tests/test_demo_access_validation.php \
  tests/test_demo_access_db.php \
  tests/test_password_reset_db.php \
  tests/test_invite_tokens_db.php \
  tests/test_templates_db.php \
  tests/test_risk_profiles_db.php \
  tests/test_client_portfolio_db.php \
  tests/test_platform_settings.php \
  tests/test_firm_roles.php \
  tests/test_plan_review_db.php \
  tests/test_plan_review_schedule_db.php \
  tests/test_household_projection_db.php \
  tests/test_cash_flow_summary_db.php \
  tests/test_client_assignment_db.php \
  tests/test_progress_snapshot_db.php \
  tests/test_self_service_db.php \
  tests/test_mf_nav_sync.php
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
