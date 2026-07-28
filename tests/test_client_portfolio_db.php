<?php
declare(strict_types=1);

/**
 * docs/05 item 3 / docs/06 corpus composition — client portfolio ledger
 * DB-level tests. Same pattern as test_risk_profiles_db.php: runs the same
 * TenantScopedDb calls the client_portfolio_*.php endpoints use, against a
 * real MySQL instance, no self-skip.
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

// Non-destructive: everything below runs inside one transaction, rolled back
// at the very end (docs/09 Session 2) — see test_tenant_isolation.php for the
// full reasoning (this file had the same blanket-DELETE-against-whatever-DB
// issue). On a failed assertion, assertTrue() calls exit() directly — killing
// the process mid-transaction drops the connection, and MySQL implicitly
// rolls back, so there's no try/finally needed.
$db->beginTransaction();

$db->exec("DELETE FROM cash_flow_items"); // migration 030 also FKs to users
$db->exec("DELETE FROM client_portfolio_items");
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
// template_audit_log/template_strategies/template_customizations also FK to
// users (approved_by_user_id/created_by_user_id) and base_plans FKs to the
// latter two (applied_template_id/applied_customization_id, migration 014) —
// this file didn't clear any of the three, which only ever worked by
// accident: in the full tests/run_all.sh sequence, test_risk_profiles_db.php
// (which does clear them) always ran immediately before this file. Running
// this file standalone against a database that already has real committed
// template rows (e.g. the demo dataset) surfaced the FK violation for real —
// same class of bug the other four sibling tests already guard against.
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Advisor Firm A'), (2, 'Advisor Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'advisor_a@example.com', 'hash', 'advisor'),
    (2, 1, 'client_a@example.com', 'hash', 'client'),
    (3, 2, 'advisor_b@example.com', 'hash', 'advisor')");

$dbAdvisorA = new TenantScopedDb($db, 1);
$dbAdvisorB = new TenantScopedDb($db, 2);

// --- Test 1: ALLOWED_TABLES accepts the new table ---
$threw = false;
try {
    $dbAdvisorA->select('client_portfolio_items');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue(!$threw, 'TenantScopedDb allows client_portfolio_items');

// --- Test 2: insert a liquid asset, a locked asset, and a liability ---
$liquidId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid',
    'category' => 'mutual_fund', 'description' => 'Flexi-cap SIP', 'value' => 1500000.00,
    'created_by_user_id' => 1,
]);
$lockedId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'locked',
    'category' => 'epf', 'description' => null, 'value' => 800000.00,
    'created_by_user_id' => 1,
]);
$liabilityId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'liability', 'bucket' => null,
    'category' => 'home_loan', 'description' => null, 'value' => 2000000.00,
    'created_by_user_id' => 1,
]);
assertTrue($liquidId > 0 && $lockedId > 0 && $liabilityId > 0, 'inserts a liquid asset, a locked asset, and a liability');

// --- Test 3: tenant isolation ---
assertTrue(count($dbAdvisorA->select('client_portfolio_items', ['client_id' => 2])) === 3, 'advisor A sees all 3 of their client\'s portfolio items');
assertTrue(count($dbAdvisorB->select('client_portfolio_items', ['client_id' => 2])) === 0, 'advisor B (different tenant) sees none of advisor A\'s client\'s portfolio items');

// --- Test 4: net worth computation (same math client_portfolio_list.php runs) ---
$rows = $dbAdvisorA->select('client_portfolio_items', ['client_id' => 2]);
$liquidTotal = 0.0; $lockedTotal = 0.0; $liabilitiesTotal = 0.0;
foreach ($rows as $r) {
    $value = (float) $r['value'];
    if ($r['item_kind'] === 'liability') {
        $liabilitiesTotal += $value;
    } elseif ($r['bucket'] === 'locked') {
        $lockedTotal += $value;
    } else {
        $liquidTotal += $value;
    }
}
assertTrue($liquidTotal === 1500000.0, 'liquid total sums only liquid-bucket assets');
assertTrue($lockedTotal === 800000.0, 'locked total sums only locked-bucket assets');
assertTrue($liabilitiesTotal === 2000000.0, 'liabilities total sums only liability rows');
$netWorth = $liquidTotal + $lockedTotal - $liabilitiesTotal;
assertTrue($netWorth === 300000.0, 'net worth = liquid + locked - liabilities (1,500,000 + 800,000 - 2,000,000 = 300,000)');

// --- Test 5: update() is tenant-scoped — advisor B cannot edit advisor A's item ---
$affected = $dbAdvisorB->update('client_portfolio_items', ['value' => 999.0], ['id' => $liquidId]);
assertTrue($affected === 0, 'a different tenant cannot update another tenant\'s portfolio item — tenant-scoped update() naturally blocks it');

$stillOriginal = $dbAdvisorA->select('client_portfolio_items', ['id' => $liquidId])[0];
assertTrue((float) $stillOriginal['value'] === 1500000.0, 'the item\'s value is unchanged after the blocked cross-tenant update attempt');

// --- Test 6: delete() is tenant-scoped the same way ---
$deleteAffected = $dbAdvisorB->delete('client_portfolio_items', ['id' => $liabilityId]);
assertTrue($deleteAffected === 0, 'a different tenant cannot delete another tenant\'s portfolio item');
assertTrue(count($dbAdvisorA->select('client_portfolio_items', ['id' => $liabilityId])) === 1, 'the item still exists after the blocked cross-tenant delete attempt');

$dbAdvisorA->delete('client_portfolio_items', ['id' => $liabilityId]);
assertTrue(count($dbAdvisorA->select('client_portfolio_items', ['client_id' => 2])) === 2, 'the owning tenant can delete its own portfolio item');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll client portfolio DB tests passed.\n";
