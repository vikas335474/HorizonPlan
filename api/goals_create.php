<?php
declare(strict_types=1);

// Plans & scenarios (docs/02 §4): create a base_plans goal (retirement /
// education / home_purchase / other) for a client. POST, advisor-only
// (verifyAccess 'advisor'; super_admin passes). Tenant-scoped — the client must
// be a 'client' in this advisor's tenant (404 otherwise; base_plans has no DB-level
// FK enforcing tenant/client agreement, so this check is the boundary).
//
// goal_type gates which fields are meaningful: advice (withdrawal_rate default
// 3.5, drawdown_return_rate), accumulation (ages, SIP), and corpus composition
// (liquid/locked, both-or-neither and must sum to initial_net_worth) apply to
// retirement goals only and are silently nulled otherwise. Per-field validation
// is shared with goals_update.php via GoalFieldValidation.php. If the tenant
// opted into plan review, a retirement goal enters 'pending_review' immediately.
// Writes a 'created' change_log row. Output: {status, goal_id}. Errors:
// 400 (validation), 404 (client), 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/GoalFieldValidation.php';
require_once __DIR__ . '/lib/PlanReview.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // super_admin also passes, per verifyAccess()
$tenantId = (int) $session['tenant_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$clientId = (int) ($input['client_id'] ?? 0);
$goalType = (string) ($input['goal_type'] ?? '');
$goalLabel = trim((string) ($input['goal_label'] ?? ''));
$initialNetWorth = $input['initial_net_worth'] ?? null;
$inflationRate = $input['inflation_rate'] ?? null;

$allowedGoalTypes = ['retirement', 'education', 'home_purchase', 'other'];

if ($clientId <= 0 || $goalLabel === '' || !in_array($goalType, $allowedGoalTypes, true)
    || $initialNetWorth === null || $inflationRate === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id, goal_type, goal_label, initial_net_worth, and inflation_rate are required.']);
    exit();
}

// Confirm the client actually belongs to this advisor's tenant before creating
// a goal under them — client_id is caller-supplied, so this is the one place
// that boundary has to be checked explicitly (base_plans itself has no FK
// enforcing tenant_id/client_id/users.tenant_id agreement at the DB level).
$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

// goal_type gates whether withdrawal_rate / drawdown_return_rate are meaningful
// (docs/02 Section 4.2, 4.3) — silently ignore them for non-retirement goals
// rather than storing values that don't apply.
$isRetirement = $goalType === 'retirement';

$projectionHorizonYears = (int) ($input['projection_horizon_years'] ?? 30);
if ($projectionHorizonYears < 1 || $projectionHorizonYears > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'projection_horizon_years must be between 1 and 100.']);
    exit();
}

// docs/07 Session C / docs/06 Section A: accumulation-phase fields, retirement
// goals only, all optional (a goal can still be decumulation-only, same as today).
$currentAge = $isRetirement ? ($input['current_age'] ?? null) : null;
$retirementAge = $isRetirement ? ($input['retirement_age'] ?? null) : null;
if ($currentAge !== null && $retirementAge !== null && (int) $retirementAge <= (int) $currentAge) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'retirement_age must be greater than current_age.']);
    exit();
}

// docs/05 item 3 / docs/06 corpus composition — liquid vs locked split of
// initial_net_worth, retirement goals only, optional (a goal can still be a
// single undifferentiated corpus, same as today). Both-or-neither: providing
// only one bucket amount is ambiguous, and the two must sum to
// initial_net_worth so there's one source of truth for the goal's total.
$liquidCorpusAmount = $isRetirement ? ($input['liquid_corpus_amount'] ?? null) : null;
$lockedCorpusAmount = $isRetirement ? ($input['locked_corpus_amount'] ?? null) : null;
if (($liquidCorpusAmount === null) !== ($lockedCorpusAmount === null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'liquid_corpus_amount and locked_corpus_amount must be provided together, or not at all.']);
    exit();
}
if ($liquidCorpusAmount !== null && $lockedCorpusAmount !== null) {
    if (abs(((float) $liquidCorpusAmount + (float) $lockedCorpusAmount) - (float) $initialNetWorth) > 0.01) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'liquid_corpus_amount + locked_corpus_amount must sum to initial_net_worth.']);
        exit();
    }
}

$data = [
    'client_id'                => $clientId,
    'goal_type'                => $goalType,
    'goal_label'                => $goalLabel,
    'target_amount'            => $input['target_amount'] ?? null,
    'target_date'              => $input['target_date'] ?? null,
    'initial_net_worth'        => $initialNetWorth,
    'inflation_rate'           => $inflationRate,
    'withdrawal_rate'          => $isRetirement ? ($input['withdrawal_rate'] ?? 3.5) : null,
    'drawdown_return_rate'     => $isRetirement ? ($input['drawdown_return_rate'] ?? null) : null,
    'projection_horizon_years' => $projectionHorizonYears,
    'accumulation_return_rate' => $isRetirement ? ($input['accumulation_return_rate'] ?? null) : null,
    'current_age'              => $currentAge,
    'retirement_age'           => $retirementAge,
    'monthly_sip_amount'       => $isRetirement ? ($input['monthly_sip_amount'] ?? null) : null,
    'sip_step_up_rate'         => $isRetirement ? ($input['sip_step_up_rate'] ?? null) : null,
    'liquid_corpus_amount'     => $liquidCorpusAmount,
    'locked_corpus_amount'     => $lockedCorpusAmount,
    'locked_return_rate'       => $isRetirement ? ($input['locked_return_rate'] ?? null) : null,
];

// Jr -> Sr Advisor Plan-Approval Workflow (decision #1): a retirement goal is
// created with its advice fields (withdrawal_rate defaults to 3.5, always
// set) already in place, so if this tenant has opted into plan review it
// enters the workflow immediately rather than starting 'not_required' and
// waiting for a subsequent edit. Non-retirement goals never carry advice
// fields at all, so they stay 'not_required' (the column's own DEFAULT).
if ($isRetirement && tenantRequiresPlanReview($db, $tenantId)) {
    $data['review_status'] = 'pending_review';
}

// docs/09 Pre-Launch Hardening Session 1: same per-field range/type
// validation as goals_update.php, shared via GoalFieldValidation.php so the
// two entry points to this data can't drift. Fields with no rule (goal_type,
// goal_label, monthly_sip_amount, sip_step_up_rate, ...) pass through untouched.
$fieldError = validateGoalFields($data);
if ($fieldError !== null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $fieldError]);
    exit();
}

$goalId = $scopedDb->insert('base_plans', $data);

$scopedDb->logChange('base_plan', $goalId, 'created', null, json_encode($data), (int) $session['user_id']);

echo json_encode(['status' => 'success', 'goal_id' => $goalId]);
