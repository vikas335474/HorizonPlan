<?php
declare(strict_types=1);

// docs/10 P1-4 · Record the protection facts the foundations check needs:
// dependants count, existing life cover, existing health cover.
//
// POST only. Advisor-only in a firm tenant, and additionally self-service for an
// individual writing their OWN data in a personal tenant (sql/033) —
// verifySelfServiceWrite() refuses an advisor-managed client exactly as
// everywhere else, so this cannot become a way for a firm's client to edit
// what their advisor recorded. See api/lib/SelfService.php.
//
// One row per client (UNIQUE on tenant_id+client_id), so this is a true upsert
// keyed on the client rather than on a row id.
//
// EVERY FIELD IS OPTIONAL AND NULLABLE. Omitting a field leaves it unchanged;
// passing null explicitly clears it back to "not recorded". That distinction
// matters — FinancialFoundations treats NULL (unanswered) and 0 (answered:
// none) as genuinely different states, and a person must be able to get back to
// "I haven't answered that" after a mistaken entry.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifySelfServiceWrite($db);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$clientId = resolveSelfServiceClientId($session, (int) ($input['client_id'] ?? 0));
if ($clientId <= 0 || $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']) === []) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

// Ceiling mirrors the money-field ceiling used across goal validation — a sum
// assured beyond it is a typo, not a policy.
const FOUNDATIONS_COVER_CEILING = 10_000_000_000;

$fields = [];

if (array_key_exists('dependants_count', $input)) {
    $raw = $input['dependants_count'];
    if ($raw === null || $raw === '') {
        $fields['dependants_count'] = null;
    } elseif (!is_numeric($raw) || (int) $raw != $raw || (int) $raw < 0 || (int) $raw > 20) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'dependants_count must be a whole number between 0 and 20.']);
        exit();
    } else {
        $fields['dependants_count'] = (int) $raw;
    }
}

foreach (['term_life_cover', 'health_cover'] as $field) {
    if (!array_key_exists($field, $input)) {
        continue;
    }
    $raw = $input[$field];
    if ($raw === null || $raw === '') {
        $fields[$field] = null;
        continue;
    }
    // 0 is valid and meaningful: "I have no cover" is an answer, and it is a
    // different state from having left the question blank.
    if (!is_numeric($raw) || (float) $raw < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "$field cannot be negative."]);
        exit();
    }
    if ((float) $raw > FOUNDATIONS_COVER_CEILING) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "$field is implausibly large."]);
        exit();
    }
    $fields[$field] = (float) $raw;
}

if ($fields === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nothing to update.']);
    exit();
}

$existing = $scopedDb->select('client_protection', ['client_id' => $clientId]);
if ($existing !== []) {
    $scopedDb->update('client_protection', $fields, ['id' => (int) $existing[0]['id']]);
    echo json_encode(['status' => 'success', 'client_id' => $clientId]);
    exit();
}

$fields['client_id'] = $clientId;
$fields['created_by_user_id'] = $userId;
$scopedDb->insert('client_protection', $fields);

echo json_encode(['status' => 'success', 'client_id' => $clientId]);
