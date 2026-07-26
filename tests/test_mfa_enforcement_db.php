<?php
declare(strict_types=1);

/**
 * Mandatory-MFA-enrollment integration test — exercises userHasMfaEnrolled()
 * (the pure DB check behind requireMfaEnrollment(), which itself can't be
 * unit-tested directly since it exit()s the process on failure) against a
 * live MySQL, same shape as test_auth_db.php.
 *
 * Self-skips (exit 0) when api/db_config.php is absent or the DB is
 * unreachable — safe to run anywhere:
 *   php tests/test_mfa_enforcement_db.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at the
 * end. On a failed assertion, assertTrue() calls exit() directly — same
 * documented reasoning as test_password_reset_db.php for why there's no
 * try/finally rollback: killing the process mid-transaction drops the
 * connection, and MySQL implicitly rolls back.
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

$testEmail = 'mfa-enforce-test-' . bin2hex(random_bytes(4)) . '@example.invalid';

$db->beginTransaction();

$db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Test Fixture', 'distribution')")
   ->execute();
$tenantId = (int) $db->lastInsertId();

foreach (['advisor', 'client', 'super_admin'] as $role) {
    $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, :r)"
    )->execute([
        ':t' => $tenantId,
        ':e' => $role . '-' . $testEmail,
        ':h' => password_hash('irrelevant-for-this-test', PASSWORD_BCRYPT),
        ':r' => $role,
    ]);
    $userId = (int) $db->lastInsertId();

    assertTrue(
        userHasMfaEnrolled($db, $userId) === false,
        "a freshly created $role account (mfa_secret NULL) is not MFA-enrolled"
    );

    $db->prepare("UPDATE users SET mfa_secret = :s WHERE id = :id")
       ->execute([':s' => 'JBSWY3DPEHPK3PXP', ':id' => $userId]);

    assertTrue(
        userHasMfaEnrolled($db, $userId) === true,
        "the same $role account is MFA-enrolled once mfa_secret is set"
    );

    $db->prepare("UPDATE users SET mfa_secret = '' WHERE id = :id")
       ->execute([':id' => $userId]);

    assertTrue(
        userHasMfaEnrolled($db, $userId) === false,
        "an empty-string mfa_secret (as opposed to NULL) still counts as not-enrolled"
    );
}

// A user id with no matching row at all must not be treated as enrolled —
// requireMfaEnrollment() would otherwise fail open on a bad/stale session.
assertTrue(
    userHasMfaEnrolled($db, 999999999) === false,
    'a nonexistent user id is treated as not-enrolled, not enrolled (fail closed)'
);

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll MFA-enforcement DB tests passed.\n";
