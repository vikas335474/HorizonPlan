<?php
declare(strict_types=1);

require_once __DIR__ . '/api/db_config.php';
require_once __DIR__ . '/api/lib/TenantScopedDb.php';
require_once __DIR__ . '/api/lib/security_gatekeeper.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();

// --- Setup: two tenants, one client user each ---
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Tenant A'), (2, 'Tenant B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'client_a@example.com', 'hash', 'client'),
    (2, 2, 'client_b@example.com', 'hash', 'client'),
    (3, 1, 'advisor_a@example.com', 'hash', 'advisor')");

// --- Test 1: insert always stamps the constructor's tenant_id, never a caller-supplied one ---
$dbTenantA = new TenantScopedDb($db, 1);
$goalId = $dbTenantA->insert('base_plans', [
    'tenant_id'   => 999, // deliberately wrong — must be overwritten
    'client_id'   => 1,
    'goal_type'   => 'retirement',
    'goal_label'  => 'Test Retirement Goal',
    'initial_net_worth' => 1500000.00,
    'inflation_rate'    => 6.00,
    'withdrawal_rate'   => 3.50,
]);
$row = $db->query("SELECT tenant_id FROM base_plans WHERE id = $goalId")->fetch();
assertTrue($row['tenant_id'] === '1' || $row['tenant_id'] === 1, 'insert() overwrites caller-supplied tenant_id with the constructor tenant');

// --- Test 2: tenant A's scoped instance cannot see tenant B's data ---
$db->exec("INSERT INTO base_plans (tenant_id, client_id, goal_type, goal_label, initial_net_worth, inflation_rate)
           VALUES (2, 2, 'retirement', 'Tenant B Goal', 2000000.00, 6.00)");

$tenantAResults = $dbTenantA->select('base_plans');
$leaksTenantB = false;
foreach ($tenantAResults as $r) {
    if ((int)$r['tenant_id'] !== 1) {
        $leaksTenantB = true;
    }
}
assertTrue(count($tenantAResults) === 1 && !$leaksTenantB, 'select() on tenant A instance returns only tenant A rows, not tenant B');

// --- Test 3: update() cannot cross tenant boundary even if given tenant B's row ID ---
$dbTenantB = new TenantScopedDb($db, 2);
$tenantBGoal = $db->query("SELECT id FROM base_plans WHERE tenant_id = 2")->fetch();
$affected = $dbTenantA->update('base_plans', ['inflation_rate' => 99.00], ['id' => $tenantBGoal['id']]);
assertTrue($affected === 0, 'update() from tenant A instance affects 0 rows when targeting tenant B\'s row ID');

// --- Test 4: update() requires a condition beyond tenant scope (refuses bulk update) ---
$threw = false;
try {
    $dbTenantA->update('base_plans', ['inflation_rate' => 1.00], []);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue($threw, 'update() throws when called with no row-level condition');

// --- Test 5: assertAllowedTable rejects a table not in the allow-list ---
$threw = false;
try {
    $dbTenantA->select('users'); // users is tenant-scoped but deliberately not in ALLOWED_TABLES for this helper
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue($threw, 'select() rejects a table outside ALLOWED_TABLES');

// --- Test 6: logChange writes a row scoped to the correct tenant ---
$dbTenantA->logChange('base_plan', $goalId, 'inflation_rate', '6.00', '6.50', 3);
$logRow = $db->query("SELECT tenant_id, field_changed FROM change_log WHERE entity_id = $goalId")->fetch();
assertTrue($logRow['tenant_id'] === '1' || $logRow['tenant_id'] === 1, 'logChange() writes with correct tenant_id');
assertTrue($logRow['field_changed'] === 'inflation_rate', 'logChange() records the correct field name');

// --- Test 7: session issuance + rate limiting logic (not verifyAccess itself, since it calls exit() on the CLI) ---
issueSession($db, 1, 1, 'client');
$sessionRow = $db->query("SELECT user_id, tenant_id, role FROM active_sessions WHERE user_id = 1")->fetch();
assertTrue($sessionRow['role'] === 'client' && ((int)$sessionRow['tenant_id']) === 1, 'issueSession() writes a correctly-scoped session row');

for ($i = 0; $i < 5; $i++) {
    recordLoginAttempt($db, 'attacker@example.com', '10.0.0.1', false);
}
$allowed = checkLoginRateLimit($db, 'attacker@example.com', '10.0.0.1');
assertTrue($allowed === false, 'checkLoginRateLimit() blocks after 5 failed attempts within the window');

$allowedOtherUser = checkLoginRateLimit($db, 'someone_else@example.com', '203.0.113.5');
assertTrue($allowedOtherUser === true, 'checkLoginRateLimit() does not block an unrelated email/IP');

echo "\nAll tests passed.\n";
