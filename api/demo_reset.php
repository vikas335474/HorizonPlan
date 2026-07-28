<?php
declare(strict_types=1);

// docs/09 Piece 3 — one-click demo data reset. super_admin-only, and only
// works when platform_settings.demo_mode = 'on' (403 otherwise) — this is a
// bulk-delete operation and must never be reachable against a real
// production dataset just because the caller happens to be a super_admin.
//
// Deletes ONLY the 4 demo firms' tenant-scoped data (identified by tenant
// company_name matching api/lib/DemoSeeder.php's FIRMS list, not by email
// domain — company_name is the same identity the seeder itself uses for its
// per-firm idempotency check, so the two can never drift apart) and never
// touches the acting super_admin's own account, the platform admin tenant,
// platform_settings, or the shared global system template (it lives under a
// separate "HorizonPlan System" tenant, never in the demo tenant list built
// below). After deleting, it calls seedDemoDataFull($db) directly (from
// api/lib/DemoSeeder.php — NOT tools/seed_demo_data_full.php, which is
// CLI-only and never deployed to production, see that file's own docblock)
// — that function is idempotent per firm, so it recreates exactly the 4
// firms just deleted without touching the platform admin account (which was
// never deleted) or refusing to run because a platform admin already exists.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

// Re-seeding 160 clients + goals/portfolio/risk-profile rows takes ~40s
// locally — well past PHP's default 30s max_execution_time (and past
// Hostinger's own 30s ceiling noted elsewhere in this project, see CLAUDE.md
// Phase 7). Every other endpoint in this app is legitimately sub-second
// (PlanMath is pure in-memory arithmetic), so raising the limit here — for
// this one deliberate, super_admin-only, low-frequency admin action — is not
// the same call as building async job infrastructure for real per-request
// traffic. Documented limitation: on Hostinger's actual shared-hosting cap,
// this may still need to run via CLI (`php tools/seed_demo_data_full.php`)
// or SSH rather than through this endpoint, if the host enforces its 30s
// ceiling at a level set_time_limit() can't override.
set_time_limit(180);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'super_admin');

$settings = getPlatformSettings($db, true);
if ($settings['demo_mode'] !== 'on') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Demo reset is only available while demo_mode is on.']);
    exit();
}

// docs/09 Group 1 Session 3 — cooldown so a repeated call can't repeatedly
// wipe-and-reseed 160 clients back to back (~40s each), a self-inflicted DoS
// even from a legitimate super_admin session. claimDemoResetSlot() stamps
// last_reset_at atomically in the same UPDATE it checks, so this doubles as
// the concurrency guard for two near-simultaneous calls. Checked before
// requiring DemoSeeder.php — no point loading 40s of seeding logic for a
// call that's about to be rejected.
if (!claimDemoResetSlot($db)) {
    $row = $db->query("SELECT last_reset_at FROM platform_settings WHERE id = 1 LIMIT 1")->fetch();
    $retryAfterSeconds = null;
    if (!empty($row['last_reset_at'])) {
        $elapsedSeconds = time() - strtotime((string) $row['last_reset_at']);
        $retryAfterSeconds = max(0, (DEMO_RESET_COOLDOWN_MINUTES * 60) - $elapsedSeconds);
    }
    http_response_code(429);
    echo json_encode([
        'status'  => 'error',
        'message' => 'A demo reset already ran recently. Please wait before trying again.',
        'retry_after_seconds' => $retryAfterSeconds,
    ]);
    exit();
}

require_once __DIR__ . '/lib/DemoSeeder.php';

// The exact 4 company names tools/seed_demo_data_full.php's FIRMS constant
// seeds — deliberately not derived from email-domain matching, so this can
// never accidentally sweep up a real firm that happens to share a domain
// convention, and never the platform admin's own tenant (a different name).
$demoFirmNames = array_column(FIRMS, 'name');

$placeholders = implode(', ', array_fill(0, count($demoFirmNames), '?'));
$tenantStmt = $db->prepare("SELECT id FROM tenants WHERE company_name IN ($placeholders)");
$tenantStmt->execute($demoFirmNames);
$demoTenantIds = array_map('intval', $tenantStmt->fetchAll(PDO::FETCH_COLUMN));

if ($demoTenantIds === []) {
    // Nothing to delete (e.g. a reset called before any seed run) — just
    // (re)seed and return.
    $result = seedDemoDataFull($db);
    echo json_encode([
        'status'  => 'success',
        'message' => 'No existing demo firms found — seeded a fresh dataset.',
        'created_firms' => $result['created_firms'],
    ]);
    exit();
}

$db->beginTransaction();
try {
    $tenantPlaceholders = implode(', ', array_fill(0, count($demoTenantIds), '?'));

    // FK-safe order: base_plans carries applied_template_id/
    // applied_customization_id FKs into template_strategies/
    // template_customizations, so base_plans (and template_audit_log, which
    // also FKs into both) must be deleted before those two tables — not
    // after, which an earlier draft of this endpoint got backwards. Full
    // chain: cash_flow_items/client_portfolio_items/risk_profiles/sub_scenarios/
    // template_audit_log/base_plans (all children) before
    // risk_question_sets/template_customizations/template_strategies
    // (their parents), and finally users/tenants. cash_flow_items (migration
    // 030) FKs to users, so it must go before the users delete like the rest.
    foreach ([
        'cash_flow_items', 'client_portfolio_items', 'risk_profiles', 'sub_scenarios',
        'template_audit_log', 'base_plans',
        'risk_question_sets', 'template_customizations', 'template_strategies',
        'change_log',
    ] as $table) {
        $db->prepare("DELETE FROM {$table} WHERE tenant_id IN ($tenantPlaceholders)")
           ->execute($demoTenantIds);
    }

    // active_sessions/mfa_pending/password_resets key off user_id, not
    // tenant_id — active_sessions has no ON DELETE CASCADE (mfa_pending and
    // password_resets do), so it needs an explicit delete before users.
    $userIdsStmt = $db->prepare("SELECT id FROM users WHERE tenant_id IN ($tenantPlaceholders)");
    $userIdsStmt->execute($demoTenantIds);
    $demoUserIds = array_map('intval', $userIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($demoUserIds !== []) {
        $userPlaceholders = implode(', ', array_fill(0, count($demoUserIds), '?'));
        $db->prepare("DELETE FROM active_sessions WHERE user_id IN ($userPlaceholders)")->execute($demoUserIds);
    }

    $db->prepare("DELETE FROM users WHERE tenant_id IN ($tenantPlaceholders)")->execute($demoTenantIds);
    $db->prepare("DELETE FROM tenants WHERE id IN ($tenantPlaceholders)")->execute($demoTenantIds);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not clear demo data: ' . $e->getMessage()]);
    exit();
}

$result = seedDemoDataFull($db);

echo json_encode([
    'status'         => 'success',
    'message'        => 'Demo data reset and reseeded.',
    'created_firms'  => $result['created_firms'],
    'total_clients'  => $result['total_clients'],
    'total_goals'    => $result['total_goals'],
    'platform_admin' => $result['platform_admin_email'],
]);
