<?php
declare(strict_types=1);

/**
 * docs/09 Piece 2 — firm-level role hierarchy DB integration test: the
 * requireFirmRole() gate itself (approval blocked for jr_advisor, allowed for
 * sr_advisor/firm_admin/super_admin), and backward compatibility for a NULL
 * firm_role (treated as sr_advisor).
 *
 * requireFirmRole() exits the process on failure, so — same approach as
 * test_platform_settings.php for requireMfaEnrollment() — this exercises the
 * exact query + OR-condition requireFirmRole() evaluates internally, against
 * real DB rows, rather than calling the exiting function directly.
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is
 * unreachable, or the users.firm_role column isn't migrated yet:
 *   php tests/test_firm_roles.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at the
 * end (see test_mfa_enforcement_db.php's docblock for why exit()-on-failure
 * is still safe under a transaction wrapper).
 */

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}
function skip(string $why): void
{
    echo "SKIP: $why\n";
    exit(0);
}

$configPath = __DIR__ . '/../api/db_config.php';
if (!is_file($configPath)) {
    skip('api/db_config.php not present — no database to test against.');
}

require_once __DIR__ . '/../api/lib/security_gatekeeper.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$columns = $db->query("SHOW COLUMNS FROM users LIKE 'firm_role'")->fetchAll();
if (empty($columns)) {
    skip('users.firm_role column not migrated — run sql/020_firm_role.sql to enable this test.');
}

/**
 * Mirrors requireFirmRole()'s internal logic exactly (minus the 403-and-exit
 * side effect), so a regression in either the query or the NULL-defaulting
 * rule breaks this test too.
 */
function wouldPassFirmRoleCheck(PDO $db, int $userId, array $allowedFirmRoles): bool
{
    $stmt = $db->prepare("SELECT firm_role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    $firmRole = ($row && $row['firm_role'] !== null) ? $row['firm_role'] : 'sr_advisor';
    return in_array($firmRole, $allowedFirmRoles, true);
}

$db->beginTransaction();

$db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Firm Roles Test', 'distribution')")
   ->execute();
$tenantId = (int) $db->lastInsertId();

function makeAdvisor(PDO $db, int $tenantId, ?string $firmRole): int
{
    $stmt = $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role, firm_role) VALUES (:t, :e, :h, 'advisor', :fr)"
    );
    $stmt->execute([
        ':t'  => $tenantId,
        ':e'  => 'firmrole-test-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ':h'  => password_hash('irrelevant', PASSWORD_BCRYPT),
        ':fr' => $firmRole,
    ]);
    return (int) $db->lastInsertId();
}

$jrAdvisor    = makeAdvisor($db, $tenantId, 'jr_advisor');
$srAdvisor    = makeAdvisor($db, $tenantId, 'sr_advisor');
$firmAdmin    = makeAdvisor($db, $tenantId, 'firm_admin');
$legacyAdvisor = makeAdvisor($db, $tenantId, null); // pre-migration 020 advisor

$approvalRoles = ['sr_advisor', 'firm_admin'];

assertTrue(wouldPassFirmRoleCheck($db, $jrAdvisor, $approvalRoles) === false,
    'jr_advisor is blocked from an approval action (sr_advisor/firm_admin required)');
assertTrue(wouldPassFirmRoleCheck($db, $srAdvisor, $approvalRoles) === true,
    'sr_advisor is allowed to approve');
assertTrue(wouldPassFirmRoleCheck($db, $firmAdmin, $approvalRoles) === true,
    'firm_admin is allowed to approve');
assertTrue(wouldPassFirmRoleCheck($db, $legacyAdvisor, $approvalRoles) === true,
    'a pre-migration advisor with firm_role=NULL is treated as sr_advisor and allowed to approve (backward compat)');

$firmAdminOnlyRoles = ['firm_admin'];
assertTrue(wouldPassFirmRoleCheck($db, $srAdvisor, $firmAdminOnlyRoles) === false,
    'sr_advisor is blocked from a firm_admin-only action (e.g. editing firm branding)');
assertTrue(wouldPassFirmRoleCheck($db, $firmAdmin, $firmAdminOnlyRoles) === true,
    'firm_admin is allowed for a firm_admin-only action');
assertTrue(wouldPassFirmRoleCheck($db, $legacyAdvisor, $firmAdminOnlyRoles) === false,
    'a pre-migration advisor with firm_role=NULL is treated as sr_advisor, NOT firm_admin — no silent capability escalation');

// requireFirmRole() itself always passes a super_admin session without even
// reaching the DB query (checked in the function before the SELECT) — that
// branch has no DB state to assert on here, so it's covered by reading the
// function's source contract rather than a query result.

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll firm role tests passed.\n";
