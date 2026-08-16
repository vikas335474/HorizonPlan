<?php
declare(strict_types=1);

// Platform product analytics — GET, super_admin-only, cross-tenant. The
// missing consumer for `product_events` (sql/040): nothing has read this
// table until now, even though it has been recording since docs/13 I-5.
//
// NOT the same page as /practice (firm_analytics.php), which is one firm's
// own book. This is platform-wide product usage: the self-serve onboarding
// funnel, activation, retention, feature adoption by tenant kind, and tenant
// growth. Cross-tenant by nature, so this reads with raw PDO rather than
// TenantScopedDb — same precedent as tenants_list.php / platform_settings_read.php,
// both already super_admin-only cross-tenant reads.
//
// Every figure is a count or a ratio of counts, matching firm_analytics.php's
// own rule: never a grade, a score, or a target — counts the platform can
// read for itself. And per ProductEvents.php's privacy rule, product_events
// physically cannot carry a rupee figure (enforced by
// tests/test_product_events.php); the feature-adoption section below counts
// tenants with >=1 row in a feature table, never an amount from it.
//
// Onboarding funnel and activation cover the events docs/13 §5 named on day
// one; several (personal_signup_started, onboarding_abandoned,
// first_goal_opened, plan_edited, refinement_answered) aren't wired into the
// frontend yet (see frontend/src/lib/api.js's logEvent call sites) — those
// rows read zero honestly rather than the dashboard pretending to have
// signal it doesn't.
//
// Output: {status, totals{}, onboarding_funnel[], activation{}, retention{},
//          weekly_active_users[], feature_adoption{}, tenant_growth[]}
// Errors: 403 (not super_admin), 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/ProductAnalytics.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
verifyAccess($db, 'super_admin');

// --- tenants: totals + growth ------------------------------------------------
$tenantRows = $db->query("SELECT id, kind, created_at, signup_source FROM tenants")->fetchAll();

$tenantsByKind = ['firm' => 0, 'personal' => 0];
$growthByMonthKind = []; // 'Y-m' => ['firm' => n, 'personal' => n]
foreach ($tenantRows as $t) {
    $kind = $t['kind'] === 'personal' ? 'personal' : 'firm';
    $tenantsByKind[$kind]++;

    $month = substr((string) $t['created_at'], 0, 7); // 'Y-m'
    if (!isset($growthByMonthKind[$month])) {
        $growthByMonthKind[$month] = ['firm' => 0, 'personal' => 0];
    }
    $growthByMonthKind[$month][$kind]++;
}
ksort($growthByMonthKind);
$tenantGrowth = [];
foreach ($growthByMonthKind as $month => $counts) {
    $tenantGrowth[] = ['month' => $month, 'firm' => $counts['firm'], 'personal' => $counts['personal']];
}

$userCount = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// --- onboarding funnel + activation ------------------------------------------
$eventCountRows = $db->query(
    "SELECT event_name, COUNT(DISTINCT user_id) AS c FROM product_events GROUP BY event_name"
)->fetchAll();
$distinctUsersByEvent = [];
$totalEventRows = 0;
foreach ($eventCountRows as $row) {
    $distinctUsersByEvent[$row['event_name']] = (int) $row['c'];
}
$totalEventRows = (int) $db->query("SELECT COUNT(*) FROM product_events")->fetchColumn();

$onboardingFunnel = buildOnboardingFunnel($distinctUsersByEvent);

$plannedUserIds = array_column(
    $db->query("SELECT DISTINCT user_id FROM product_events WHERE event_name = 'plan_created'")->fetchAll(),
    'user_id'
);
$plannedUsers = count($plannedUserIds);

$activationCountsByEvent = [];
if ($plannedUsers > 0) {
    $placeholders = implode(',', array_fill(0, $plannedUsers, '?'));
    $stmt = $db->prepare(
        "SELECT event_name, COUNT(DISTINCT user_id) AS c FROM product_events
          WHERE event_name IN ('first_goal_opened','lever_previewed','plan_edited')
            AND user_id IN ($placeholders)
          GROUP BY event_name"
    );
    $stmt->execute(array_map('intval', $plannedUserIds));
    foreach ($stmt->fetchAll() as $row) {
        $activationCountsByEvent[$row['event_name']] = (int) $row['c'];
    }
}
$activationRates = buildActivationRates($plannedUsers, $activationCountsByEvent);

// --- retention: returning-user share + weekly active users ------------------
$spanRows = $db->query(
    "SELECT user_id, MIN(DATE(occurred_at)) AS first_seen, MAX(DATE(occurred_at)) AS last_seen
       FROM product_events GROUP BY user_id"
)->fetchAll();
$spanByUser = [];
foreach ($spanRows as $row) {
    $spanByUser[(int) $row['user_id']] = ['first_seen' => $row['first_seen'], 'last_seen' => $row['last_seen']];
}
$retention = returningUserStats($spanByUser);

// Weekly active users over the last 90 days — enough to see a trend without
// scanning the table's full history on every load.
$recentEvents = $db->query(
    "SELECT DISTINCT user_id, occurred_at FROM product_events
      WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
)->fetchAll();
$usersByWeek = [];
foreach ($recentEvents as $row) {
    $week = isoWeekBucket((string) $row['occurred_at']);
    $usersByWeek[$week] ??= [];
    $usersByWeek[$week][(int) $row['user_id']] = true;
}
ksort($usersByWeek);
$weeklyActiveUsers = [];
foreach ($usersByWeek as $week => $users) {
    $weeklyActiveUsers[] = ['week' => $week, 'users' => count($users)];
}

// --- feature adoption by tenant kind ------------------------------------------
// One tenant-scoped table per core write action. Counts tenants with >=1 row,
// never an amount from the row.
$featureTables = [
    'goal_created'      => 'base_plans',
    'portfolio_entered'  => 'client_portfolio_items',
    'cash_flow_entered'  => 'cash_flow_items',
    'foundations_entered' => 'client_protection',
    'risk_profiled'      => 'risk_profiles',
];
$adoptedByFeatureAndKind = [];
foreach ($featureTables as $feature => $table) {
    $rows = $db->query(
        "SELECT t.kind, COUNT(DISTINCT x.tenant_id) AS c
           FROM `$table` x JOIN tenants t ON t.id = x.tenant_id
          GROUP BY t.kind"
    )->fetchAll();
    $byKind = ['firm' => 0, 'personal' => 0];
    foreach ($rows as $row) {
        $kind = $row['kind'] === 'personal' ? 'personal' : 'firm';
        $byKind[$kind] = (int) $row['c'];
    }
    $adoptedByFeatureAndKind[$feature] = $byKind;
}
$featureAdoption = buildFeatureAdoption($tenantsByKind, $adoptedByFeatureAndKind);

echo json_encode([
    'status' => 'success',
    'totals' => [
        'tenants'          => count($tenantRows),
        'firm_tenants'     => $tenantsByKind['firm'],
        'personal_tenants' => $tenantsByKind['personal'],
        'users'            => $userCount,
        'event_count'      => $totalEventRows,
        'event_users'      => count($spanByUser),
    ],
    'onboarding_funnel'   => $onboardingFunnel,
    'activation'          => ['planned_users' => $plannedUsers, 'rates' => $activationRates],
    'retention'           => $retention,
    'weekly_active_users' => $weeklyActiveUsers,
    'feature_adoption'    => $featureAdoption,
    'tenant_growth'       => $tenantGrowth,
]);
