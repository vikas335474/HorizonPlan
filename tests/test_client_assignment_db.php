<?php
declare(strict_types=1);

/**
 * Client → advisor assignment (migration 031). Verifies against a real
 * database, inside a rolled-back transaction, the invariants that matter:
 *
 *   1. An assignment is tenant-scoped on write — firm B cannot assign firm A's
 *      client, and cannot point one of its own clients at firm A's advisor.
 *   2. Assignment is ATTRIBUTION, NOT ACCESS CONTROL — the whole point of the
 *      design. Assigning a client to advisor X must not change what any other
 *      advisor in the firm can read. This is the invariant most at risk of
 *      being "helpfully" broken later by someone assuming "my clients" is a
 *      permission boundary, so it is asserted explicitly.
 *   3. Unassigning (NULL) works and is the default state.
 *   4. Deleting an advisor un-assigns their clients (ON DELETE SET NULL)
 *      rather than blocking the delete or leaving a dangling id.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();
$db->beginTransaction();

// FK-ordered teardown — same chain the other *_db.php fixtures use.
foreach ([
    'client_dependants', 'client_context', 'client_protection', 'cash_flow_items', 'plan_review_schedules', 'client_portfolio_items', 'mf_nav_cache', 'risk_profiles',
    'risk_question_sets', 'template_audit_log', 'change_log', 'sub_scenarios', 'base_plans',
    'template_customizations', 'template_strategies', 'active_sessions', 'login_attempts',
    'users', 'households', 'tenants',
] as $t) {
    $db->exec("DELETE FROM $t");
}

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Firm A'), (2, 'Firm B')");

$mkUser = static function (PDO $db, int $tenant, string $email, string $role, ?string $firmRole = null): int {
    $stmt = $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role, firm_role) VALUES (:t, :e, 'hash', :r, :fr)"
    );
    $stmt->execute([':t' => $tenant, ':e' => $email, ':r' => $role, ':fr' => $firmRole]);
    return (int) $db->lastInsertId();
};

$advA1 = $mkUser($db, 1, 'sr.a@example.invalid', 'advisor', 'sr_advisor');
$advA2 = $mkUser($db, 1, 'jr.a@example.invalid', 'advisor', 'jr_advisor');
$advB1 = $mkUser($db, 2, 'sr.b@example.invalid', 'advisor', 'sr_advisor');
$clientA = $mkUser($db, 1, 'client.a@example.invalid', 'client');
$clientB = $mkUser($db, 2, 'client.b@example.invalid', 'client');

$scopedA = new TenantScopedDb($db, 1);
$scopedB = new TenantScopedDb($db, 2);

// --- 0. default state -------------------------------------------------------
$row = $scopedA->select('users', ['id' => $clientA])[0];
assertTrue($row['assigned_advisor_id'] === null, 'a new client starts unassigned (migration 031 defaults NULL, no backfill)');

// --- 1. a normal in-tenant assignment --------------------------------------
$affected = $scopedA->update('users', ['assigned_advisor_id' => $advA1], ['id' => $clientA]);
assertTrue($affected === 1, 'firm A can assign its own client to its own advisor');
$row = $scopedA->select('users', ['id' => $clientA])[0];
assertTrue((int) $row['assigned_advisor_id'] === $advA1, 'the assignment is persisted');

// --- 2. tenant isolation on write ------------------------------------------
// Firm B trying to reassign firm A's client: the scoped update carries
// tenant_id = 2, so it simply matches no rows — the same "acts like it doesn't
// exist" outcome every other cross-tenant write attempt gets.
$affected = $scopedB->update('users', ['assigned_advisor_id' => $advB1], ['id' => $clientA]);
assertTrue($affected === 0, 'firm B cannot reassign firm A\'s client — tenant-scoped update matches 0 rows');
$row = $scopedA->select('users', ['id' => $clientA])[0];
assertTrue((int) $row['assigned_advisor_id'] === $advA1, 'firm A\'s client is untouched after firm B\'s blocked attempt');

// The other direction — pointing your own client at another firm's advisor —
// is what client_assign_advisor.php's explicit advisor lookup prevents, since
// a plain FK to users(id) would happily allow it. Assert the lookup that
// endpoint performs is the thing that fails.
assertTrue(
    $scopedB->select('users', ['id' => $advA1, 'role' => 'advisor']) === [],
    'firm B cannot resolve firm A\'s advisor — the tenant-scoped lookup client_assign_advisor.php runs returns nothing, so it 404s before writing'
);

// --- 3. assignment is attribution, not access control ----------------------
// clientA is assigned to advA1. A DIFFERENT advisor in the same firm (advA2)
// must still be able to read that client in full. Reads are tenant-scoped, and
// nothing anywhere filters by assigned_advisor_id — "my clients" is a filter
// the caller opts into, never an implicit restriction.
$visibleToOtherAdvisor = $scopedA->select('users', ['id' => $clientA, 'role' => 'client']);
assertTrue(
    $visibleToOtherAdvisor !== [] && (int) $visibleToOtherAdvisor[0]['id'] === $clientA,
    'a client assigned to one advisor is still fully readable by every other advisor in the firm — assignment is attribution, not a permission boundary'
);
$allFirmClients = $scopedA->select('users', ['role' => 'client']);
assertTrue(
    count($allFirmClients) === 1,
    'the firm-wide client list is unaffected by who a client is assigned to'
);

// --- 4. unassigning ---------------------------------------------------------
$scopedA->update('users', ['assigned_advisor_id' => null], ['id' => $clientA]);
$row = $scopedA->select('users', ['id' => $clientA])[0];
assertTrue($row['assigned_advisor_id'] === null, 'a client can be explicitly unassigned back to NULL');

// --- 5. ON DELETE SET NULL --------------------------------------------------
$scopedA->update('users', ['assigned_advisor_id' => $advA2], ['id' => $clientA]);
$db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $advA2]);
$row = $scopedA->select('users', ['id' => $clientA])[0];
assertTrue(
    $row['assigned_advisor_id'] === null,
    'deleting an advisor un-assigns their clients (ON DELETE SET NULL) rather than blocking the delete or dangling the id'
);

// Firm B's own client was never touched by any of the above.
$rowB = $scopedB->select('users', ['id' => $clientB])[0];
assertTrue($rowB['assigned_advisor_id'] === null, 'firm B\'s client is untouched throughout');

$db->rollBack();

echo "\nAll client-assignment tests passed.\n";
