<?php
declare(strict_types=1);

// Billing (docs/09 Session 8): the fixed subscription-plan catalog (sql/044).
// Super Admin only — read-only, and the catalog itself isn't editable through
// the app in this session (mechanism, not opinion; see sql/044's own header).
// Backs the plan picker in FirmDetailModal.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
verifyAccess($db, 'super_admin');

$rows = $db->query(
    'SELECT id, code, name, max_advisors, price_inr_monthly FROM subscription_plans ORDER BY
        (max_advisors IS NULL) ASC, max_advisors ASC'
)->fetchAll();

$plans = array_map(static fn(array $p): array => [
    'id'                => (int) $p['id'],
    'code'              => $p['code'],
    'name'              => $p['name'],
    'max_advisors'      => $p['max_advisors'] !== null ? (int) $p['max_advisors'] : null,
    'price_inr_monthly' => $p['price_inr_monthly'] !== null ? (int) $p['price_inr_monthly'] : null,
], $rows);

echo json_encode(['status' => 'success', 'plans' => $plans]);
