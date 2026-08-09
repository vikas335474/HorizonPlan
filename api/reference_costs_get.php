<?php
declare(strict_types=1);

// The sourced reference-cost library's read side (docs/11 Prompt E-3). GET,
// any authenticated advisor OR client — this is read-only reference data,
// not tenant-scoped (see sql/037_reference_costs.sql), same posture as
// market_history_years.php. Two modes:
//   ?category=education                          -> list every row in that category
//   ?category=education&subcategory=private             -> exactly one row (or found:false)
// Output: {status, rows:[...]} or {status, row:{...}|null, found:bool}.
// Errors: 405 (non-GET), 400 (missing category).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/ReferenceCosts.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
verifyAccessAny($db, ['advisor', 'client']);

$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
if ($category === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'category is required.']);
    exit();
}

$subcategory = isset($_GET['subcategory']) ? trim((string) $_GET['subcategory']) : '';

if ($subcategory !== '') {
    $row = getReferenceCost($db, $category, $subcategory);
    echo json_encode([
        'status' => 'success',
        'found'  => $row !== null,
        'row'    => $row !== null ? formatReferenceCostRow($row) : null,
    ]);
    exit();
}

$rows = listReferenceCosts($db, $category);
echo json_encode([
    'status' => 'success',
    'rows'   => array_map('formatReferenceCostRow', $rows),
]);
