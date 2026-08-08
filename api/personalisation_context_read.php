<?php
declare(strict_types=1);

// docs/11 Prompt E-2 · Read one client's personalisation context: city tier,
// the effective expense multiplier it implies (or the person's own override),
// and their recorded ongoing medical cost.
//
// GET only. Same read shape as foundations_read.php: an advisor reads any
// client in their tenant, a client reads only their own (query-string
// client_id is ignored for a client session, forced server-side).
//
// City tier is HOUSEHOLD-shared (docs/11 §4 decide-then-build note, mirroring
// FinancialFoundations' reserve/debt precedent): if this client hasn't
// answered the tier question themselves, this falls back to a household
// partner's answer and reports whose figure is actually being used.
// Medical cost stays PER-PERSON, like client_protection's cover fields.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/PersonalisationReference.php';

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

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id is required.']);
    exit();
}

if (!$isClient && $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$ownRows = $scopedDb->select('client_context', ['client_id' => $clientId]);
$own = $ownRows[0] ?? null;

$cityTier = $own['city_tier'] ?? null;
$expenseMultiplierOverride = ($own !== null && $own['expense_multiplier'] !== null)
    ? (float) $own['expense_multiplier']
    : null;
$tierAnsweredByPartner = false;

if ($cityTier === null && $expenseMultiplierOverride === null) {
    $clientRows = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
    $householdId = ($clientRows !== [] && $clientRows[0]['household_id'] !== null)
        ? (int) $clientRows[0]['household_id']
        : 0;
    if ($householdId > 0) {
        foreach ($scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']) as $member) {
            if ((int) $member['id'] === $clientId) {
                continue;
            }
            $partnerRows = $scopedDb->select('client_context', ['client_id' => (int) $member['id']]);
            if ($partnerRows !== [] && ($partnerRows[0]['city_tier'] !== null || $partnerRows[0]['expense_multiplier'] !== null)) {
                $cityTier = $partnerRows[0]['city_tier'];
                $expenseMultiplierOverride = $partnerRows[0]['expense_multiplier'] !== null
                    ? (float) $partnerRows[0]['expense_multiplier']
                    : null;
                $tierAnsweredByPartner = true;
                break;
            }
        }
    }
}

$multiplierInfo = PersonalisationReference::cityTierMultiplier($cityTier, $expenseMultiplierOverride);

echo json_encode([
    'status'  => 'success',
    'context' => [
        // The client's OWN recorded answers — null even when a partner's
        // answer is being applied, so the UI can tell "you haven't answered
        // this" apart from "this is your household's shared answer".
        'own_city_tier'          => $own['city_tier'] ?? null,
        'own_expense_multiplier' => ($own !== null && $own['expense_multiplier'] !== null) ? (float) $own['expense_multiplier'] : null,
        'monthly_medical_cost'   => ($own !== null && $own['monthly_medical_cost'] !== null) ? (float) $own['monthly_medical_cost'] : null,

        // The EFFECTIVE figures actually applied to this client's retirement
        // target, after the household fallback above.
        'effective_city_tier'        => $multiplierInfo['city_tier'],
        'effective_multiplier'       => $multiplierInfo['multiplier'],
        'multiplier_applied'         => $multiplierInfo['is_applied'],
        'multiplier_is_override'     => $multiplierInfo['is_override'],
        'multiplier_source_name'     => $multiplierInfo['source_name'],
        'multiplier_source_as_of'    => $multiplierInfo['source_as_of'],
        'tier_answered_by_partner'   => $tierAnsweredByPartner,
    ],
    'city_tier_labels' => PersonalisationReference::CITY_TIER_LABELS,
]);
