<?php
declare(strict_types=1);

/**
 * Self-serve trial signup integration test — exercises createTrialTenant()
 * (api/lib/TrialSignup.php) against a live MySQL. Input validation (email
 * format, password length, the friendly pre-flight uniqueness check,
 * signup_enabled) lives in api/signup.php itself and isn't re-tested here —
 * this covers the DB-level guarantees: always distribution mode, always
 * signup_source='trial_signup', the founding user is always firm_admin, and
 * the transaction boundary that keeps a tenant from ever existing without
 * its user.
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is
 * unreachable, or the trial-signup columns aren't migrated yet:
 *   php tests/test_signup_db.php
 *
 * NOT wrapped in a rolled-back transaction, unlike most other _db.php tests
 * in this suite — createTrialTenant() commits internally by design (a real
 * signup can't be "undone" by killing the process; issuing a session and
 * moving on assumes the row really exists). Every row this test creates uses
 * a random unique suffix, so there's no collision risk, and cleanup happens
 * explicitly at the end. A FAILED assertion partway through will leave its
 * test tenant/user permanently in the database — a deliberate, documented
 * tradeoff of testing a function that must commit for real, not an oversight.
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
require_once __DIR__ . '/../api/lib/TrialSignup.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$tenantCols = $db->query('SHOW COLUMNS FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('signup_source', $tenantCols, true)) {
    skip('tenants.signup_source not migrated — run sql/022_trial_signup.sql to enable this test.');
}

$suffix = bin2hex(random_bytes(4));
$companyName = "Signup Test Firm {$suffix}";
$email = "signup-test-{$suffix}@example.invalid";
$passwordHash = password_hash('irrelevant-for-this-test', PASSWORD_BCRYPT);

$createdTenantIds = [];
$createdUserIds = [];

try {
    $result = createTrialTenant($db, $companyName, $email, $passwordHash);
    $createdTenantIds[] = $result['tenant_id'];
    $createdUserIds[] = $result['user_id'];

    $tenantRow = $db->prepare('SELECT advisory_mode, signup_source FROM tenants WHERE id = :id');
    $tenantRow->execute([':id' => $result['tenant_id']]);
    $tenant = $tenantRow->fetch();

    assertTrue($tenant['advisory_mode'] === 'distribution',
        'a trial-signup tenant is always created in distribution mode');
    assertTrue($tenant['signup_source'] === 'trial_signup',
        'a trial-signup tenant is tagged signup_source = trial_signup');

    $userRow = $db->prepare('SELECT role, firm_role, email FROM users WHERE id = :id');
    $userRow->execute([':id' => $result['user_id']]);
    $user = $userRow->fetch();

    assertTrue($user['role'] === 'advisor', "the founding user's role is advisor");
    assertTrue($user['firm_role'] === 'firm_admin', "the founding user's firm_role is firm_admin (top of docs/09 Piece 2's hierarchy)");
    assertTrue($user['email'] === $email, 'the founding user email matches what was submitted');

    assertTrue(
        userHasMfaEnrolled($db, $result['user_id']) === false,
        'a freshly signed-up account is not MFA-enrolled — same mandatory-enrollment nag as any other fresh account'
    );

    $auditRow = $db->prepare(
        "SELECT field_changed FROM change_log WHERE entity_type = 'user' AND entity_id = :id AND field_changed = 'created' LIMIT 1"
    );
    $auditRow->execute([':id' => $result['user_id']]);
    assertTrue($auditRow->fetch() !== false, 'signup writes a change_log row for the created user');

    // --- transactional atomicity: a duplicate email must roll back cleanly,
    // never leaving a second orphaned tenant with no (or a duplicate) user ---
    $secondCompanyName = "Signup Test Firm {$suffix} — should not persist";
    $tenantCountBefore = (int) $db->query('SELECT COUNT(*) FROM tenants')->fetchColumn();

    $duplicateRejected = false;
    try {
        createTrialTenant($db, $secondCompanyName, $email, $passwordHash);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    assertTrue($duplicateRejected, 'a second signup with an already-used email fails loudly (UNIQUE constraint), not silently');

    $tenantCountAfter = (int) $db->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
    assertTrue($tenantCountAfter === $tenantCountBefore,
        'the rejected duplicate-email attempt did not leave an orphaned tenant behind — the transaction rolled back both statements together');

    $orphanCheck = $db->prepare('SELECT id FROM tenants WHERE company_name = :name');
    $orphanCheck->execute([':name' => $secondCompanyName]);
    assertTrue($orphanCheck->fetch() === false, 'the second (rejected) tenant does not exist at all');
} finally {
    // Explicit cleanup — createTrialTenant() commits internally, so there is
    // no transaction to roll back. Safe even if the second (rejected) call
    // above created nothing, since we only ever delete the first, real result.
    foreach ($createdUserIds as $uid) {
        $db->prepare("DELETE FROM change_log WHERE entity_type = 'user' AND entity_id = :id")->execute([':id' => $uid]);
        $db->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
    }
    foreach ($createdTenantIds as $tid) {
        $db->prepare('DELETE FROM tenants WHERE id = :id')->execute([':id' => $tid]);
    }
}

echo "\nAll signup DB tests passed.\n";
