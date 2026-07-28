<?php
declare(strict_types=1);

// Plans & scenarios: partial-update a goal's parameters. POST or PUT,
// advisor-only (assumption edits are an advisor action). Tenant-scoped.
//
// Only fields actually sent are changed (no full-row replace). Per-field
// validation is shared with goals_create.php (GoalFieldValidation.php); the
// age-order and liquid+locked=net-worth invariants are re-checked against
// effective values so a partial update can't silently break them. Every changed
// field writes a change_log row. The Global Inheritance Engine then cascades the
// seven overridable rate/contribution fields to non-overridden sub_scenarios
// only (is_overridden=1 rows are structurally excluded), logging each. An edit
// to an advice field can move the goal into/out of plan review (PlanReview).
// Output: {status, goal_id, changed_fields[], cascaded_fields[]}. Errors:
// 400 (validation), 404 (not found), 405 (other method).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/GoalFieldValidation.php';
require_once __DIR__ . '/lib/PlanReview.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
$session = verifyAccess($db, 'advisor'); // goal-level assumption edits are an advisor action
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$goalId = (int) ($input['id'] ?? 0);
if ($goalId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id is required.']);
    exit();
}

$rows = $scopedDb->select('base_plans', ['id' => $goalId]);
if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Goal not found.']);
    exit();
}
$existing = $rows[0];

// Only fields the caller actually sent get touched — this is a partial update,
// not a full-row replace, so an advisor adjusting one slider doesn't
// accidentally null out every other field.
$updatableFields = [
    'goal_label', 'target_amount', 'target_date', 'initial_net_worth',
    'inflation_rate', 'withdrawal_rate', 'drawdown_return_rate', 'projection_horizon_years',
    // docs/07 Session C / docs/06 Section A — accumulation-phase fields.
    'accumulation_return_rate', 'current_age', 'retirement_age',
    'monthly_sip_amount', 'sip_step_up_rate',
    // docs/05 item 3 / docs/06 corpus composition.
    'liquid_corpus_amount', 'locked_corpus_amount', 'locked_return_rate',
];

$changes = []; // field => [old, new]
foreach ($updatableFields as $field) {
    if (!array_key_exists($field, $input)) {
        continue;
    }
    $newValue = $input[$field];
    $oldValue = $existing[$field];
    // Loose comparison across the string/number boundary PDO hands back —
    // avoids logging a no-op change_log row every time a field is resent unchanged.
    if ((string) $oldValue !== (string) $newValue) {
        $changes[$field] = [$oldValue, $newValue];
    }
}

if (empty($changes)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes.', 'goal_id' => $goalId]);
    exit();
}

// docs/09 Pre-Launch Hardening Session 1: per-field range/type validation,
// only for fields actually being changed here (partial-update semantics —
// a field present but unchanged writes nothing, so there's nothing new to
// validate). Shared with goals_create.php via GoalFieldValidation.php so the
// two entry points to this data can't drift.
foreach ($changes as $field => [, $newValue]) {
    $fieldError = validateGoalField($field, $newValue);
    if ($fieldError !== null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $fieldError]);
        exit();
    }
}

if (array_key_exists('projection_horizon_years', $changes)) {
    $newHorizon = (int) $changes['projection_horizon_years'][1];
    if ($newHorizon < 1 || $newHorizon > 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'projection_horizon_years must be between 1 and 100.']);
        exit();
    }
}

// docs/07 Session C: retirement_age must stay after current_age. Checked
// against whichever of the two changed here plus the pre-existing value for
// whichever didn't, so a partial update (only one of the pair sent) can't
// silently create an inverted age range.
if (array_key_exists('current_age', $changes) || array_key_exists('retirement_age', $changes)) {
    $effectiveCurrentAge = array_key_exists('current_age', $changes) ? $changes['current_age'][1] : $existing['current_age'];
    $effectiveRetirementAge = array_key_exists('retirement_age', $changes) ? $changes['retirement_age'][1] : $existing['retirement_age'];
    if ($effectiveCurrentAge !== null && $effectiveRetirementAge !== null && (int) $effectiveRetirementAge <= (int) $effectiveCurrentAge) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'retirement_age must be greater than current_age.']);
        exit();
    }
}

// docs/05 item 3 / docs/06 corpus composition: liquid_corpus_amount +
// locked_corpus_amount must sum to the goal's initial_net_worth — checked
// against whichever of the three is being changed here plus the pre-existing
// value for whichever isn't, same "effective value" pattern as the age check
// above, so a partial update can't silently break the invariant.
if (array_key_exists('liquid_corpus_amount', $changes) || array_key_exists('locked_corpus_amount', $changes) || array_key_exists('initial_net_worth', $changes)) {
    $effectiveLiquid = array_key_exists('liquid_corpus_amount', $changes) ? $changes['liquid_corpus_amount'][1] : $existing['liquid_corpus_amount'];
    $effectiveLocked = array_key_exists('locked_corpus_amount', $changes) ? $changes['locked_corpus_amount'][1] : $existing['locked_corpus_amount'];
    $effectiveNetWorth = array_key_exists('initial_net_worth', $changes) ? $changes['initial_net_worth'][1] : $existing['initial_net_worth'];
    if (($effectiveLiquid === null) !== ($effectiveLocked === null)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'liquid_corpus_amount and locked_corpus_amount must be provided together, or not at all.']);
        exit();
    }
    if ($effectiveLiquid !== null && $effectiveLocked !== null
        && abs(((float) $effectiveLiquid + (float) $effectiveLocked) - (float) $effectiveNetWorth) > 0.01) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'liquid_corpus_amount + locked_corpus_amount must sum to initial_net_worth.']);
        exit();
    }
}

$updateData = array_combine(array_keys($changes), array_map(static fn($c) => $c[1], $changes));
$scopedDb->update('base_plans', $updateData, ['id' => $goalId]);

foreach ($changes as $field => [$oldValue, $newValue]) {
    $scopedDb->logChange(
        'base_plan',
        $goalId,
        $field,
        $oldValue !== null ? (string) $oldValue : null,
        $newValue !== null ? (string) $newValue : null,
        $userId
    );
}

// --- Global Inheritance Engine cascade ---
// Maps a parent field to the child override column it feeds. Only fires for
// fields that were actually changed above. Rows with is_overridden = 1 are
// structurally excluded from the SELECT below, so the cascade never touches
// a protected what-if variant — this mirrors the original blueprint's SQL
// pattern (docs/02 Section 4.2/4.3: one shared is_overridden flag, no
// per-field override flags for MVP).
// current_age/retirement_age are deliberately NOT in this map — a what-if
// sub-scenario varies assumptions (rates, contribution amount), not the
// client's age, so there is no custom_current_age/custom_retirement_age
// column to cascade into (docs/06 Section A). liquid_corpus_amount/
// locked_corpus_amount are likewise not cascaded — same precedent as
// initial_net_worth itself, which has never been overridable per
// sub-scenario (docs/05 item 3): only locked_return_rate is a rate
// assumption, so only it gets a custom_* override column.
$cascadeMap = [
    'inflation_rate'           => 'custom_inflation',
    'withdrawal_rate'          => 'custom_withdrawal_rate',
    'drawdown_return_rate'     => 'custom_drawdown_return_rate',
    'accumulation_return_rate' => 'custom_accumulation_return_rate',
    'monthly_sip_amount'       => 'custom_monthly_sip_amount',
    'sip_step_up_rate'         => 'custom_sip_step_up_rate',
    'locked_return_rate'       => 'custom_locked_return_rate',
];

// Jr -> Sr Advisor Plan-Approval Workflow (decision #1/#6): an edit to either
// advice field can move the goal into (or back into) pending_review — a
// no-op if this tenant hasn't opted into plan review, or if the field(s)
// touched aren't advice fields at all (goal_label, target_date, etc. never
// affect review_status).
if (array_intersect(PLAN_REVIEW_ADVICE_FIELDS, array_keys($changes)) !== []) {
    applyPlanReviewTransitionOnAdviceEdit($scopedDb, $db, $tenantId, $goalId, $existing, $userId);
}

$cascadedFields = array_intersect_key($cascadeMap, $changes);

if (!empty($cascadedFields)) {
    // Non-overridden children only — this SELECT is what makes the cascade
    // safe: it never sees rows that opted out via is_overridden = 1.
    $childRows = $scopedDb->select('sub_scenarios', ['base_plan_id' => $goalId, 'is_overridden' => 0]);

    foreach ($cascadedFields as $parentField => $childColumn) {
        [, $newValue] = $changes[$parentField];

        // Bulk cascade write and the per-row change_log entries are done
        // together, right here, so the two can never drift apart: the update
        // and its audit trail are two statements in the same block, not
        // separated across files.
        $scopedDb->update(
            'sub_scenarios',
            [$childColumn => $newValue],
            ['base_plan_id' => $goalId, 'is_overridden' => 0]
        );

        foreach ($childRows as $child) {
            $scopedDb->logChange(
                'sub_scenario',
                (int) $child['id'],
                $childColumn,
                $child[$childColumn] !== null ? (string) $child[$childColumn] : null,
                (string) $newValue,
                $userId
            );
        }
    }
}

echo json_encode([
    'status'          => 'success',
    'goal_id'         => $goalId,
    'changed_fields'  => array_keys($changes),
    'cascaded_fields' => array_values($cascadedFields),
]);
