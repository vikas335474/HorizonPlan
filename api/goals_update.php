<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';

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

// Only field with a hard range check today — see CLAUDE.md Phase 8 notes:
// the rest of $updatableFields (initial_net_worth, inflation_rate, etc.) has
// no type/range validation at all in this endpoint, which is a real gap
// flagged there but not fixed here, to avoid quietly widening this change.
if (array_key_exists('projection_horizon_years', $changes)) {
    $newHorizon = (int) $changes['projection_horizon_years'][1];
    if ($newHorizon < 1 || $newHorizon > 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'projection_horizon_years must be between 1 and 100.']);
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
$cascadeMap = [
    'inflation_rate'       => 'custom_inflation',
    'withdrawal_rate'      => 'custom_withdrawal_rate',
    'drawdown_return_rate' => 'custom_drawdown_return_rate',
];

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
