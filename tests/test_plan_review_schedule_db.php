<?php
declare(strict_types=1);

/**
 * P0-3 · Scheduled plan-review emails (docs/10). Tests the deterministic,
 * DB-driven parts of api/lib/PlanReviewMailer.php against a real database
 * inside a rolled-back transaction:
 *   - planReviewIntervalSpec() — pure.
 *   - dueReviewClients() — the due-detection query (the actual logic worth
 *     guarding: cadence gating + interval maths + the users(role=client) join).
 *   - composePlanReviewEmail() — pure; the login link lands in the body.
 *
 * The send orchestration (sendDuePlanReviews → sendMail → mail()) is not
 * exercised here: sendMail() opens its own PDO connection to read demo_mode,
 * so demo-suppression can't be toggled from inside this transaction, and a
 * real mail() call is environment-dependent. That path is verified live via
 * `php tools/plan_review_send.php` with demo_mode on (see the P0-3 session
 * notes), same "don't test the un-mockable outbound call in CI" split as
 * test_mf_nav_sync.php uses for the AMFI fetch.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/PlanReviewMailer.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// ─── Pure: interval spec ───────────────────────────────────────────────────
assertTrue(planReviewIntervalSpec('quarterly') === 'P3M', 'quarterly → P3M');
assertTrue(planReviewIntervalSpec('annually') === 'P1Y', 'annually → P1Y');
assertTrue(planReviewIntervalSpec('off') === null, "off → null (never due)");
assertTrue(planReviewIntervalSpec('nonsense') === null, 'unknown cadence → null');

// ─── Pure: email composition ───────────────────────────────────────────────
$email = composePlanReviewEmail(['email' => 'c@example.com'], 'https://app.example.com/');
assertTrue(str_contains($email['subject'], 'review'), 'subject mentions a review');
assertTrue(str_contains($email['body'], 'https://app.example.com/login'), 'body carries the client login link (trailing slash normalised)');

// ─── DB: dueReviewClients() against real rows, rolled back ──────────────────
$db = getPdo();
$db->beginTransaction();

// FK-ordered teardown (same order as test_mf_nav_sync.php, with our table
// first since it references both users and tenants).
$db->exec("DELETE FROM plan_review_schedules");
$db->exec("DELETE FROM client_protection"); // migration 034 also FKs to users
$db->exec("DELETE FROM cash_flow_items"); // migration 030 also FKs to users
$db->exec("DELETE FROM client_portfolio_items");
$db->exec("DELETE FROM mf_nav_cache");
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM households"); // migration 029 FKs to tenants
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Firm A')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'advisor@example.com', 'hash', 'advisor'),
    (10, 1, 'off@example.com', 'hash', 'client'),
    (11, 1, 'q_never@example.com', 'hash', 'client'),
    (12, 1, 'q_recent@example.com', 'hash', 'client'),
    (13, 1, 'q_stale@example.com', 'hash', 'client'),
    (14, 1, 'a_recent@example.com', 'hash', 'client'),
    (15, 1, 'a_stale@example.com', 'hash', 'client')");

$now = new DateTimeImmutable('2026-07-28 09:00:00');
$fmt = static fn(string $rel): string => $now->modify($rel)->format('Y-m-d H:i:s');

$ins = $db->prepare("INSERT INTO plan_review_schedules (tenant_id, client_id, cadence, last_sent_at) VALUES (1, :cid, :cad, :last)");
$ins->execute([':cid' => 10, ':cad' => 'off',       ':last' => null]);           // off → never
$ins->execute([':cid' => 11, ':cad' => 'quarterly', ':last' => null]);           // never sent → due
$ins->execute([':cid' => 12, ':cad' => 'quarterly', ':last' => $fmt('-2 months')]); // 2mo < 3mo → not due
$ins->execute([':cid' => 13, ':cad' => 'quarterly', ':last' => $fmt('-4 months')]); // 4mo ≥ 3mo → due
$ins->execute([':cid' => 14, ':cad' => 'annually',  ':last' => $fmt('-6 months')]);  // 6mo < 12mo → not due
$ins->execute([':cid' => 15, ':cad' => 'annually',  ':last' => $fmt('-13 months')]); // 13mo ≥ 12mo → due

$due = dueReviewClients($db, $now);
$dueIds = array_map(static fn(array $r): int => (int) $r['client_id'], $due);
sort($dueIds);

assertTrue($dueIds === [11, 13, 15], 'exactly the due clients are returned: never-sent quarterly (11), stale quarterly (13), stale annual (15) — off (10), recent quarterly (12) and recent annual (14) excluded');
assertTrue(!in_array(10, $dueIds, true), "an 'off' cadence is never due, even with no send history");
assertTrue(in_array(11, $dueIds, true), 'a non-off cadence never sent is due immediately');

// The join is on role='client' — a non-client id with a schedule row is ignored.
$db->exec("INSERT INTO plan_review_schedules (tenant_id, client_id, cadence, last_sent_at) VALUES (1, 1, 'quarterly', NULL)");
$dueAfter = dueReviewClients($db, $now);
$dueAfterIds = array_map(static fn(array $r): int => (int) $r['client_id'], $dueAfter);
assertTrue(!in_array(1, $dueAfterIds, true), 'a schedule row pointing at a non-client user (advisor id 1) is ignored by the client join');

$db->rollBack();

echo "All plan-review schedule tests passed.\n";
