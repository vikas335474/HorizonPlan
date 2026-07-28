<?php
declare(strict_types=1);

// Risk profiler authoring: create or replace the tenant's question set + scoring
// rubric. POST or PUT, advisor-only, tenant-scoped. Validates the shape (each
// question needs id/text/options; each option a value + numeric score; each
// rubric band name/min/max/suggested_return_pct). Editing an already-approved
// set reverts it to 'draft' and clears the sign-off (guardrail 2 — the principal
// approved specific content, not whatever it becomes later). Output: {status,
// question_set_id, approval_reverted}. Errors: 400 (shape validation),
// 405 (other method).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$questions = $input['questions'] ?? null;
$rubric = $input['scoring_rubric'] ?? null;

if (!is_array($questions) || empty($questions)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'questions is required and must be a non-empty array.']);
    exit();
}
if (!is_array($rubric) || empty($rubric['bands']) || !is_array($rubric['bands'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'scoring_rubric is required and must have a non-empty bands array.']);
    exit();
}

foreach ($questions as $q) {
    if (!is_array($q) || !isset($q['id'], $q['text'], $q['options']) || !is_array($q['options']) || empty($q['options'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Each question needs id, text, and a non-empty options array.']);
        exit();
    }
    foreach ($q['options'] as $opt) {
        if (!is_array($opt) || !array_key_exists('value', $opt) || !isset($opt['score']) || !is_numeric($opt['score'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Each option needs a value and a numeric score.']);
            exit();
        }
    }
}
foreach ($rubric['bands'] as $band) {
    if (!is_array($band) || !isset($band['name'], $band['min'], $band['max'], $band['suggested_return_pct'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Each rubric band needs name, min, max, and suggested_return_pct.']);
        exit();
    }
}

$existingRows = $scopedDb->select('risk_question_sets', []);

// docs/06 guardrail 2: editing the content of an already-approved question
// set invalidates that sign-off — a firm principal approved the questions
// and rubric as they stood, not whatever they're edited to later. Same
// approval-revert logic as templates_customize.php, simpler here because
// this endpoint always replaces the whole content, not individual fields.
if (!empty($existingRows)) {
    $scopedDb->update('risk_question_sets', [
        'questions_json'      => json_encode($questions),
        'scoring_rubric_json' => json_encode($rubric),
        'status'              => 'draft',
        'approved_by_user_id' => null,
        'approved_at'         => null,
    ], ['id' => $existingRows[0]['id']]);

    echo json_encode(['status' => 'success', 'question_set_id' => (int) $existingRows[0]['id'], 'approval_reverted' => $existingRows[0]['status'] === 'approved']);
    exit();
}

$id = $scopedDb->insert('risk_question_sets', [
    'questions_json'      => json_encode($questions),
    'scoring_rubric_json' => json_encode($rubric),
    'status'              => 'draft',
    'created_by_user_id'  => $userId,
]);

echo json_encode(['status' => 'success', 'question_set_id' => $id, 'approval_reverted' => false]);
