<?php
declare(strict_types=1);

// docs/11 Prompt E-2 · Create or update one dependant (child) row: current
// age, cost driver (government/private/overseas — never the aspiration, see
// docs/11 §3/§5), and an optional link to an existing education-type goal.
//
// POST only. Same gate as foundations_upsert.php / personalisation_context_
// upsert.php: advisor-only in a firm tenant, self-service for an individual in
// a personal tenant. Only the client who OWNS the row (client_id = the
// resolved acting client, not a household partner) may edit or create it —
// same one-writes-their-own-lines posture as cash_flow_items, so a partner
// managing the household's shared dependants list still edits it as their own
// entries, exactly like a shared cash-flow statement.
//
// id present -> update; id absent -> create. Every field is optional on
// update (unchanged if omitted); current_age/cost_driver/label may be
// explicitly nulled back to "not recorded".

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

$id = (int) ($input['id'] ?? 0);
$existing = null;
if ($id > 0) {
    $rows = $scopedDb->select('client_dependants', ['id' => $id]);
    if ($rows === [] || (int) $rows[0]['client_id'] !== $clientId) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No dependant with that ID for this client.']);
        exit();
    }
    $existing = $rows[0];
}

$fields = [];

if (array_key_exists('label', $input)) {
    $raw = $input['label'];
    if ($raw === null || $raw === '') {
        $fields['label'] = null;
    } elseif (!is_string($raw) || mb_strlen($raw) > 60) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'label must be 60 characters or fewer.']);
        exit();
    } else {
        $fields['label'] = $raw;
    }
}

if (array_key_exists('current_age', $input)) {
    $raw = $input['current_age'];
    if ($raw === null || $raw === '') {
        $fields['current_age'] = null;
    } elseif (!is_numeric($raw) || (int) $raw != $raw || (int) $raw < 0 || (int) $raw > 30) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'current_age must be a whole number between 0 and 30.']);
        exit();
    } else {
        $fields['current_age'] = (int) $raw;
    }
}

if (array_key_exists('cost_driver', $input)) {
    $raw = $input['cost_driver'];
    if ($raw === null || $raw === '') {
        $fields['cost_driver'] = null;
    } elseif (!in_array($raw, ['government', 'private', 'overseas'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'cost_driver must be one of government, private, overseas.']);
        exit();
    } else {
        $fields['cost_driver'] = $raw;
    }
}

if (array_key_exists('goal_id', $input)) {
    $rawGoalId = $input['goal_id'];
    if ($rawGoalId === null || $rawGoalId === '' || (int) $rawGoalId === 0) {
        $fields['goal_id'] = null;
    } else {
        $goalId = (int) $rawGoalId;
        // Never auto-matched (docs/11 decide-then-build note) — must be an
        // education-type goal belonging to THIS SAME client, so a partner
        // cannot link a child to the other partner's goal.
        $goalRows = $scopedDb->select('base_plans', ['id' => $goalId, 'client_id' => $clientId, 'goal_type' => 'education']);
        if ($goalRows === []) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'goal_id must be an education-type goal belonging to this client.']);
            exit();
        }
        $fields['goal_id'] = $goalId;
    }
}

if ($existing === null) {
    $fields['client_id'] = $clientId;
    $fields['created_by_user_id'] = $userId;
    $newId = $scopedDb->insert('client_dependants', $fields);
    echo json_encode(['status' => 'success', 'id' => $newId]);
    exit();
}

if ($fields === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nothing to update.']);
    exit();
}

$scopedDb->update('client_dependants', $fields, ['id' => $id]);
echo json_encode(['status' => 'success', 'id' => $id]);
