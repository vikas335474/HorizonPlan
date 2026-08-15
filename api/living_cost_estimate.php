<?php
declare(strict_types=1);

// The sourced living-cost anchor for someone who does not know their own
// monthly spend. GET, advisor OR client (a client is forced to their own id,
// same rule as foundations_read.php / alerts_read.php).
//
// WHAT THIS IS FOR. PlanMath::retirementTarget() needs a monthly-expenses
// figure, which normally comes from the person's own cash_flow_items. Someone
// who has never recorded their expenses gets no plan at all. This returns a
// published population average for their state so they have somewhere to
// start.
//
// *** WHAT THIS IS NOT. *** It is NOT "what your retirement will cost", and
// the response says so in `caveat` rather than leaving the caller to phrase
// it. MPCE is average per-capita consumption across all households — this
// product's users spend well above it. The UI must show this beside the
// person's own input, never pre-filled into it. See LivingCostReference.php's
// header for why understating here is the most harmful error this app could
// make.
//
// Input:  ?state=<key>&sector=rural|urban[&household_size=N]
//         household_size defaults to the person's recorded dependants + 1.
// Output: {status, estimate:{per_capita, household_size, monthly_household,
//         is_fallback, is_verified, sector}, caveat, source:{...}}
//         estimate is null when the cache is empty (the sync has not run).
// Errors: 400 (bad sector), 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/ReferenceCosts.php';
require_once __DIR__ . '/lib/LivingCostReference.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccessAny($db, ['advisor', 'client']);
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);
$isClient = $session['role'] === 'client';

$clientId = $isClient
    ? (int) $session['user_id']
    : (int) ($_GET['client_id'] ?? 0);

$sector = isset($_GET['sector']) ? trim((string) $_GET['sector']) : 'urban';
if ($sector !== 'rural' && $sector !== 'urban') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "sector must be 'rural' or 'urban'."]);
    exit();
}

$state = isset($_GET['state']) ? trim((string) $_GET['state']) : null;

// Household size: the caller may state it, otherwise derive it from what the
// person already told us. dependants_count is DEPENDANTS, so the household is
// that plus the person themselves — getting this wrong understates the figure
// by a whole person, which is exactly the direction that matters.
$householdSize = 0;
if (isset($_GET['household_size'])) {
    $householdSize = (int) $_GET['household_size'];
} elseif ($clientId > 0) {
    $protection = $scopedDb->select('client_protection', ['client_id' => $clientId]);
    if ($protection !== [] && $protection[0]['dependants_count'] !== null) {
        $householdSize = (int) $protection[0]['dependants_count'] + 1;
    }
}
if ($householdSize <= 0) {
    $householdSize = 1; // stated explicitly rather than assumed silently
}

// Load the living-cost slice of the cache once and hand it to the pure
// resolver — no per-row queries, and the resolution logic stays unit-testable.
$cacheBySubcategory = [];
foreach (listReferenceCosts($db, 'living_cost') as $row) {
    $cacheBySubcategory[$row['subcategory']] = [
        'low'         => (float) $row['low'],
        'high'        => (float) $row['high'],
        'is_verified' => (bool) $row['is_verified'],
    ];
}

$anchor = livingCostAnchor($cacheBySubcategory, $state, $sector);

if ($anchor === null) {
    // The sync has not run, or ran against an empty dataset. Say so plainly
    // rather than returning a figure from nowhere.
    echo json_encode([
        'status'   => 'success',
        'estimate' => null,
        'caveat'   => 'No sourced figure is available yet.',
        'source'   => null,
    ]);
    exit();
}

$household = livingCostForHousehold($anchor['per_capita'], $householdSize);

echo json_encode([
    'status'   => 'success',
    'estimate' => [
        'per_capita'        => $household['per_capita'],
        'household_size'    => $household['household_size'],
        'monthly_household' => $household['monthly_household'],
        'sector'            => $anchor['sector'],
        // Both flags are part of the contract, not decoration: the UI is
        // expected to render differently for a national fallback and for an
        // unverified figure.
        'is_fallback'       => $anchor['is_fallback'],
        'is_verified'       => $anchor['is_verified'],
    ],
    'caveat' => 'This is what an average household spends, not what a comfortable retirement costs. '
              . 'Most people planning a retirement spend more than the average. Use it as a starting point and change it.',
    'source' => [
        'name'       => HCES_SOURCE_NAME,
        'url'        => HCES_SOURCE_URL,
        'as_of_date' => HCES_AS_OF_DATE,
    ],
]);
