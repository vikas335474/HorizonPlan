<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-1 — reconcile-by-holding CAS import, DB-touching half.
 * The pure diff math is covered in test_portfolio_reconcile.php; this file
 * checks the two things that need a real database:
 *   1. SOURCE SCOPING — a hand-entered ('manual') holding must be
 *      completely invisible to reconciliation, even when its folio/name
 *      would otherwise match an incoming CAS row.
 *   2. TENANT ISOLATION — the source='cas_import' scoped SELECT that feeds
 *      the diff never leaks across tenants, same as every other
 *      TenantScopedDb-backed table.
 * Runs the exact same TenantScopedDb calls client_portfolio_reconcile_preview.php
 * / client_portfolio_import.php use, mirroring test_client_portfolio_db.php's
 * own pattern.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/PortfolioReconcile.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

/** Mirrors exactly what the two endpoints do: load only this client's own CAS-sourced rows. */
function loadCasRows(TenantScopedDb $scopedDb, int $clientId): array
{
    return array_map(
        static fn(array $r): array => [
            'id'           => (int) $r['id'],
            'description'  => $r['description'],
            'value'        => (float) $r['value'],
            'folio_number' => $r['folio_number'],
        ],
        $scopedDb->select('client_portfolio_items', ['client_id' => $clientId, 'source' => 'cas_import'])
    );
}

$db = getPdo();
$db->beginTransaction();

$db->exec("DELETE FROM client_protection");
$db->exec("DELETE FROM client_dependants");
$db->exec("DELETE FROM client_context");
$db->exec("DELETE FROM cash_flow_items");
$db->exec("DELETE FROM client_portfolio_items");
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM households");
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Advisor Firm A'), (2, 'Advisor Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'advisor_a@example.com', 'hash', 'advisor'),
    (2, 1, 'client_a@example.com', 'hash', 'client'),
    (3, 2, 'advisor_b@example.com', 'hash', 'advisor'),
    (4, 2, 'client_b@example.com', 'hash', 'client')");

$dbAdvisorA = new TenantScopedDb($db, 1);
$dbAdvisorB = new TenantScopedDb($db, 2);

// --- Test 1: source defaults to 'manual' for a plain insert (migration 038) ---
$manualId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid',
    'category' => 'mutual_fund', 'description' => 'HDFC Flexi Cap Fund - Growth', 'value' => 100000.0,
    'folio_number' => 'F001', // even naming a folio doesn't matter — source stays manual unless explicitly set
    'created_by_user_id' => 1,
]);
$manualRow = $dbAdvisorA->select('client_portfolio_items', ['id' => $manualId])[0];
assertTrue($manualRow['source'] === 'manual', 'a plain insert with no explicit source defaults to manual');

// --- Test 2: source scoping — the manual row above is INVISIBLE to reconciliation ---
// even though its folio number (F001) exactly matches an incoming CAS row.
$casRowsForClientA = loadCasRows($dbAdvisorA, 2);
assertTrue($casRowsForClientA === [], 'a client with only a manually-entered holding has zero rows visible to reconciliation');

$incoming = [
    ['description' => 'HDFC Flexi Cap Fund - Growth', 'value' => 999999.0, 'folio_number' => 'F001'],
];
$diffAgainstManualOnly = computePortfolioReconciliation($casRowsForClientA, $incoming);
assertTrue(count($diffAgainstManualOnly['to_add']) === 1, 'the incoming row is treated as brand new, NOT matched against the manual row with the same folio');
assertTrue($diffAgainstManualOnly['to_update'] === [], 'the manual row is never queued for update, no matter how well it matches');

// --- Test 3: a real cas_import row DOES get matched and reconciled ---
$casId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid',
    'category' => 'mutual_fund', 'source' => 'cas_import',
    'description' => 'ICICI Pru Bluechip Fund', 'value' => 50000.0, 'folio_number' => 'F002',
    'created_by_user_id' => 1,
]);
$casRowsForClientA2 = loadCasRows($dbAdvisorA, 2);
assertTrue(count($casRowsForClientA2) === 1, 'now exactly one CAS-sourced row is visible — the manual one still is not');

$incoming2 = [
    ['description' => 'ICICI Pru Bluechip Fund', 'value' => 55000.0, 'folio_number' => 'F002'],
];
$diff2 = computePortfolioReconciliation($casRowsForClientA2, $incoming2);
assertTrue(count($diff2['to_update']) === 1 && $diff2['to_update'][0]['id'] === $casId, 'the CAS-sourced row is correctly matched and queued for update');

// Apply it exactly as client_portfolio_import.php would, then verify.
$dbAdvisorA->update('client_portfolio_items', ['value' => $diff2['to_update'][0]['new_value']], ['id' => $casId]);
$updatedCasRow = $dbAdvisorA->select('client_portfolio_items', ['id' => $casId])[0];
assertTrue((float) $updatedCasRow['value'] === 55000.0, 'the CAS row\'s value is actually updated in the DB');
$manualRowUnchanged = $dbAdvisorA->select('client_portfolio_items', ['id' => $manualId])[0];
assertTrue((float) $manualRowUnchanged['value'] === 100000.0, 'the manual row\'s value is completely untouched by the whole exercise');

// --- Test 4: tenant isolation on the source-scoped read ---
$dbAdvisorB->insert('client_portfolio_items', [
    'client_id' => 4, 'item_kind' => 'asset', 'bucket' => 'liquid',
    'category' => 'mutual_fund', 'source' => 'cas_import',
    'description' => 'ICICI Pru Bluechip Fund', 'value' => 999.0, 'folio_number' => 'F002', // same description+folio as tenant A's row, on purpose
    'created_by_user_id' => 3,
]);
$casRowsForClientB = loadCasRows($dbAdvisorB, 4);
assertTrue(count($casRowsForClientB) === 1, 'tenant B sees its own one CAS row');
assertTrue($casRowsForClientB[0]['value'] === 999.0, 'and it is tenant B\'s own row, not tenant A\'s, despite an identical folio number and description');

// A cross-tenant id probe: tenant B's scoped db must never see tenant A's CAS row.
$crossTenantAttempt = $dbAdvisorB->select('client_portfolio_items', ['id' => $casId]);
assertTrue($crossTenantAttempt === [], 'tenant B cannot read tenant A\'s CAS-sourced row by id, even knowing it exists');

// --- Test 5: applying a confirmed removal deletes only the confirmed id ---
$toRemoveId = $dbAdvisorA->insert('client_portfolio_items', [
    'client_id' => 2, 'item_kind' => 'asset', 'bucket' => 'liquid',
    'category' => 'mutual_fund', 'source' => 'cas_import',
    'description' => 'Axis Small Cap Fund', 'value' => 25000.0, 'folio_number' => 'F003',
    'created_by_user_id' => 1,
]);
$casRowsForClientA3 = loadCasRows($dbAdvisorA, 2); // now has F002 (updated) and F003
$statementMissingF003 = [
    ['description' => 'ICICI Pru Bluechip Fund', 'value' => 55000.0, 'folio_number' => 'F002'],
];
$diff3 = computePortfolioReconciliation($casRowsForClientA3, $statementMissingF003);
assertTrue(count($diff3['to_flag']) === 1 && $diff3['to_flag'][0]['id'] === $toRemoveId, 'the Axis Small Cap fund is correctly flagged as missing from the new statement');

// Simulate a request that ALSO tries to remove the manual holding's id and a
// nonexistent id, alongside the legitimately flagged one — confirmedPortfolioRemovalIds()
// must strip everything except what this diff itself flagged.
$requestedRemovals = [$manualId, 999999, $toRemoveId];
$confirmed = confirmedPortfolioRemovalIds($diff3, $requestedRemovals);
assertTrue($confirmed === [$toRemoveId], 'only the legitimately-flagged id survives — the manual row\'s id is silently dropped even though it was in the request');

$dbAdvisorA->delete('client_portfolio_items', ['id' => $confirmed[0]]);
assertTrue($dbAdvisorA->select('client_portfolio_items', ['id' => $toRemoveId]) === [], 'the confirmed removal actually deleted the row');
assertTrue($dbAdvisorA->select('client_portfolio_items', ['id' => $manualId]) !== [], 'the manual row survives completely untouched, even though its id was in the same removal request');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll portfolio-reconcile DB tests passed.\n";
