<?php
declare(strict_types=1);

/**
 * DB integration test for resolveOrLinkGoogleUser() — the deterministic part
 * of Google Sign-In that doesn't require talking to Google (that's
 * fetchGoogleTokenInfo(), and validateGooglePayload() is covered separately
 * in tests/test_google_auth_validation.php with fixture payloads). Exercises
 * the real auto-link-on-first-use and sub-mismatch-rejection behavior
 * against a live MySQL instance.
 *
 * Self-skips (exit 0) when api/db_config.php is absent or the DB is
 * unreachable, same as every other _db.php test in this suite:
 *   php tests/test_google_auth_db.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at the
 * end.
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

// api/db_config.php always exists now (it's a tracked loader — see its own
// header); the real "is a dev DB configured" question is answered by the
// shared resolver both it and this check use, so the two can't drift apart.
require_once __DIR__ . '/../api/lib/DbConfigResolver.php';
if (resolveDbConfigPath() === null) {
    skip('no database credentials configured — no database to test against.');
}

require_once __DIR__ . '/../api/lib/security_gatekeeper.php';
require_once __DIR__ . '/../api/lib/GoogleAuth.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$present = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('users', $present, true) || !in_array('tenants', $present, true)) {
    skip('users/tenants tables not migrated.');
}
$usersCols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('google_sub', $usersCols, true)) {
    skip('users.google_sub not migrated — run sql/021_google_auth.sql to enable this test.');
}

$db->beginTransaction();
try {
    $db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Google Auth Test Fixture', 'distribution')")
       ->execute();
    $tenantId = (int) $db->lastInsertId();

    $emailA = 'google-fixture-a-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $emailB = 'google-fixture-b-' . bin2hex(random_bytes(4)) . '@example.invalid';

    $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'advisor')")
       ->execute([':t' => $tenantId, ':e' => $emailA, ':h' => password_hash('irrelevant', PASSWORD_BCRYPT)]);
    $userAId = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'advisor')")
       ->execute([':t' => $tenantId, ':e' => $emailB, ':h' => password_hash('irrelevant', PASSWORD_BCRYPT)]);
    $userBId = (int) $db->lastInsertId();

    $subA = 'google-sub-' . bin2hex(random_bytes(8));

    // --- unknown email -----------------------------------------------------
    try {
        resolveOrLinkGoogleUser($db, 'no-such-account-' . bin2hex(random_bytes(4)) . '@example.invalid', $subA);
        assertTrue(false, 'an unknown email throws GoogleAuthException, not returning a fabricated user');
    } catch (GoogleAuthException $e) {
        assertTrue($e->httpStatus === 404, 'an unknown email throws a 404 GoogleAuthException');
    }

    // --- first login: auto-links google_sub ---------------------------------
    assertTrue(
        userHasMfaEnrolled($db, $userAId) === false,
        'before any Google login, the fixture account is not MFA-enrolled'
    );

    $resolved = resolveOrLinkGoogleUser($db, $emailA, $subA);
    assertTrue((int) $resolved['id'] === $userAId, 'first Google login resolves to the matching-email user');

    $stmt = $db->prepare('SELECT google_sub FROM users WHERE id = :id');
    $stmt->execute([':id' => $userAId]);
    $row = $stmt->fetch();
    assertTrue($row['google_sub'] === $subA, 'first Google login auto-links google_sub by email match');

    assertTrue(
        userHasMfaEnrolled($db, $userAId) === true,
        'once google_sub is linked, userHasMfaEnrolled() is true even with no TOTP secret — Google satisfies MFA on its own'
    );

    // --- second login, same sub: succeeds, no drift -------------------------
    $resolvedAgain = resolveOrLinkGoogleUser($db, $emailA, $subA);
    assertTrue((int) $resolvedAgain['id'] === $userAId, 'a later login with the same sub resolves to the same user');

    // --- second login, DIFFERENT sub: rejected, not silently re-linked ------
    try {
        resolveOrLinkGoogleUser($db, $emailA, 'a-completely-different-sub');
        assertTrue(false, 'a mismatched sub for an already-linked account throws, rather than silently re-linking');
    } catch (GoogleAuthException $e) {
        assertTrue($e->httpStatus === 409, 'a mismatched sub throws a 409 GoogleAuthException');
    }

    // Confirm the rejected mismatch attempt did NOT mutate the stored sub.
    $stmt->execute([':id' => $userAId]);
    $rowAfter = $stmt->fetch();
    assertTrue($rowAfter['google_sub'] === $subA, 'the rejected mismatch attempt left the original google_sub untouched');

    // --- the same Google sub cannot bind to a second, different account -----
    // (uq_users_google_sub, sql/021) — userBId has no google_sub yet, so
    // resolveOrLinkGoogleUser() would try to bind subA to it too; the UNIQUE
    // constraint must reject that outright rather than silently succeeding
    // against the wrong row.
    try {
        resolveOrLinkGoogleUser($db, $emailB, $subA);
        assertTrue(false, 'binding an already-used google_sub to a second account fails loudly (UNIQUE constraint), not silently');
    } catch (PDOException $e) {
        assertTrue(true, 'binding an already-used google_sub to a second account throws a PDOException (duplicate key)');
    }
} finally {
    $db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data
}

echo "\nAll Google auth DB tests passed.\n";
