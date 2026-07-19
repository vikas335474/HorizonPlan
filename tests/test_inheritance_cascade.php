<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';

// Exercises the same cascade pattern goals_update.php runs, directly against
// TenantScopedDb, so the cascade logic can be verified independent of HTTP
// plumbing. See docs/02 Section 4.2/4.3 — one is_overridden flag guards all
// three custom_* fields; the cascade must skip a row that's overridden even
// if only via a DIFFERENT field than the one being cascaded.

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();

$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Tenant A')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'client_a@example.com', 'hash', 'client'),
    (2, 1, 'advisor_a@example.com', 'hash', 'advisor')");

$scopedDb = new TenantScopedDb($db, 1);

$goalId = $scopedDb->insert('base_plans', [
    'client_id'   => 1,
    'goal_type'   => 'retirement',
    'goal_label'  => 'Cascade Test Goal',
    'initial_net_worth' => 10000000.00,
    'inflation_rate'    => 6.00,
    'withdrawal_rate'   => 3.50,
    'drawdown_return_rate' => 7.00,
]);

// Row A: never customized — should receive the cascade.
$rowA = $scopedDb->insert('sub_scenarios', [
    'base_plan_id' => $goalId,
    'custom_inflation' => 6.00,
    'custom_withdrawal_rate' => 3.50,
    'custom_drawdown_return_rate' => 7.00,
    'is_overridden' => 0,
]);

// Row B: overridden via a DIFFERENT field (custom_withdrawal_rate), not the
// one being cascaded (custom_inflation) — must still be skipped, because the
// flag is row-level, not per-field (documented behavior, Section 4.2).
$rowB = $scopedDb->insert('sub_scenarios', [
    'base_plan_id' => $goalId,
    'custom_inflation' => 6.00,
    'custom_withdrawal_rate' => 5.00, // client's own stress-test value
    'custom_drawdown_return_rate' => 7.00,
    'is_overridden' => 1,
]);

// --- Simulate the cascade goals_update.php runs when inflation_rate changes 6.00 -> 6.50 ---
$newInflation = 6.50;
$childRows = $scopedDb->select('sub_scenarios', ['base_plan_id' => $goalId, 'is_overridden' => 0]);
assertTrue(count($childRows) === 1 && (int) $childRows[0]['id'] === $rowA,
    'cascade SELECT (is_overridden=0) returns only Row A, not Row B');

$scopedDb->update('sub_scenarios', ['custom_inflation' => $newInflation], ['base_plan_id' => $goalId, 'is_overridden' => 0]);
foreach ($childRows as $child) {
    $scopedDb->logChange('sub_scenario', (int) $child['id'], 'custom_inflation', (string) $child['custom_inflation'], (string) $newInflation, 2);
}

$rowAAfter = $scopedDb->select('sub_scenarios', ['id' => $rowA])[0];
$rowBAfter = $scopedDb->select('sub_scenarios', ['id' => $rowB])[0];

assertTrue((float) $rowAAfter['custom_inflation'] === 6.50, 'Row A (not overridden) received the cascaded inflation update');
assertTrue((float) $rowBAfter['custom_inflation'] === 6.00, 'Row B (overridden, even via a different field) did NOT receive the cascade');
assertTrue((float) $rowBAfter['custom_withdrawal_rate'] === 5.00, "Row B's own stress-test value is untouched by the cascade");

$logCount = (int) $db->query("SELECT COUNT(*) FROM change_log WHERE entity_type = 'sub_scenario' AND field_changed = 'custom_inflation'")->fetchColumn();
assertTrue($logCount === 1, 'change_log has exactly one custom_inflation entry — one for Row A, none for skipped Row B');

$logRow = $db->query("SELECT entity_id, old_value, new_value FROM change_log WHERE field_changed = 'custom_inflation' LIMIT 1")->fetch();
assertTrue((int) $logRow['entity_id'] === $rowA, 'the change_log entry is attributed to Row A, the row actually changed');
assertTrue($logRow['old_value'] === '6' || $logRow['old_value'] === '6.00', 'change_log old_value captured before the cascade overwrote it');
assertTrue($logRow['new_value'] === '6.5' || $logRow['new_value'] === '6.50', 'change_log new_value matches the cascaded value');

echo "\nAll cascade tests passed.\n";
