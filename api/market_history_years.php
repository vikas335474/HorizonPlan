<?php
declare(strict_types=1);

// Historical replay reference data: list the years available for the
// "what if you retired in <year>?" projection. GET, any authenticated advisor OR
// client. NOT tenant-scoped — market_history (sql/015) is global read-only
// reference data, so this queries it directly, no TenantScopedDb. Each year is
// flagged is_verified (unverified seed rows are labelled as such downstream).
// Output: {status, years[]}. Errors: 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
// Any authenticated advisor or client can see which replay years are
// available — this is read-only reference data, not tenant-scoped (see
// sql/015_market_history.sql), so no TenantScopedDb involved here, same as
// how login_attempts/active_sessions are queried directly.
verifyAccessAny($db, ['advisor', 'client']);

$stmt = $db->query('SELECT year, is_verified FROM market_history ORDER BY year ASC');
$rows = $stmt->fetchAll();

echo json_encode([
    'status' => 'success',
    'years'  => array_map(static fn(array $r) => [
        'year'        => (int) $r['year'],
        'is_verified' => (bool) $r['is_verified'],
    ], $rows),
]);
