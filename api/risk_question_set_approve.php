<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor');
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, (int) $session['tenant_id']);

// docs/06 Section B has no global/system risk question set — every set is
// tenant-owned from the start (unlike template_strategies), so this is
// always a plain tenant-scoped update. Same documented limitation as
// templates_approve.php: Phase 1 has no separate "firm principal" role, so
// the approving advisor and the set's editor may be the same person today.
$rows = $scopedDb->select('risk_question_sets', []);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No question set to approve yet — create one first.']);
    exit();
}
$set = $rows[0];

if ($set['status'] === 'approved') {
    echo json_encode(['status' => 'success', 'message' => 'Already approved.', 'question_set_id' => (int) $set['id']]);
    exit();
}

$scopedDb->update('risk_question_sets', [
    'status'              => 'approved',
    'approved_by_user_id' => $userId,
    'approved_at'         => date('Y-m-d H:i:s'),
], ['id' => $set['id']]);

echo json_encode(['status' => 'success', 'question_set_id' => (int) $set['id']]);
