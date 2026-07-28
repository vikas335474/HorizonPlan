<?php
declare(strict_types=1);

// Risk profiler authoring: read the tenant's single active question set. GET,
// advisor-only. Tenant-scoped. A tenant with none yet gets an explicit
// question_set:null (NOT a 404) — "not set up yet" is the normal starting state
// for every firm, since no HorizonPlan-authored starter set exists (guardrail 2).
// Output: {status, question_set{questions, scoring_rubric, status, ...}|null}.
// Errors: 405 (non-GET).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // managing the question set is an advisor/super_admin action
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// docs/06 Section B: one active question set per tenant. No global/seeded
// starter exists (guardrail 2 — no HorizonPlan-authored content presented as
// authoritative), so a tenant with none yet gets an explicit "not set" state
// rather than a 404, since that is the normal starting state for every tenant.
$rows = $scopedDb->select('risk_question_sets', []);
if (empty($rows)) {
    echo json_encode(['status' => 'success', 'question_set' => null]);
    exit();
}
$set = $rows[0];

echo json_encode([
    'status'        => 'success',
    'question_set'  => [
        'id'                  => (int) $set['id'],
        'questions'           => json_decode($set['questions_json'], true),
        'scoring_rubric'      => json_decode($set['scoring_rubric_json'], true),
        'status'              => $set['status'],
        'approved_by_user_id' => $set['approved_by_user_id'] !== null ? (int) $set['approved_by_user_id'] : null,
        'approved_at'         => $set['approved_at'],
    ],
]);
