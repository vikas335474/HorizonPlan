<?php
declare(strict_types=1);

/**
 * DB integration test for resolveDemoFirmAdmin()/listAvailableDemoFirms()
 * (api/lib/DemoAccess.php) — the logic behind the public "try a live demo"
 * one-click login. Requires the real rich demo dataset to actually be seeded
 * (tools/seed_demo_data_full.php) since these functions read real seeded
 * rows, not a synthetic fixture — self-skips if it isn't.
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is
 * unreachable, the trial-signup/demo-access columns aren't migrated, or the
 * demo dataset hasn't been seeded yet:
 *   php tools/seed_demo_data_full.php && php tests/test_demo_access_db.php
 *
 * Non-destructive: both functions under test are pure reads, and the one
 * place this test temporarily mutates a row (clearing then restoring a
 * demo firm_admin's mfa_secret, to exercise the "not demo-login-ready" path)
 * is wrapped in a transaction rolled back at the end.
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
require_once __DIR__ . '/../api/lib/DemoAccess.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$tenantCols = $db->query('SHOW COLUMNS FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('signup_source', $tenantCols, true)) {
    skip('tenants.signup_source not migrated — run sql/022_trial_signup.sql to enable this test.');
}

$nirvana = resolveDemoFirmAdmin($db, 'nirvana');
if ($nirvana === null) {
    skip('demo dataset not seeded — run tools/seed_demo_data_full.php to enable this test.');
}

// --- unknown slug -------------------------------------------------------
assertTrue(
    resolveDemoFirmAdmin($db, 'not-a-real-firm-slug') === null,
    'an unknown firm slug resolves to null'
);

// --- a real, seeded firm resolves correctly ------------------------------
assertTrue($nirvana['email'] === 'admin@nirvana.demo.horizonplan.in',
    "nirvana's firm_admin resolves to the expected known email");
assertTrue($nirvana['company_name'] === 'Nirvana Wealth Advisors',
    'the resolved row carries the correct company_name');
assertTrue(isDemoAccountEmail($nirvana['email']) === true,
    'the resolved email passes the domain safety check (sanity check on the fixture itself)');

// --- listAvailableDemoFirms() includes every seeded firm -----------------
$available = listAvailableDemoFirms($db);
$slugs = array_column($available, 'slug');
assertTrue(in_array('nirvana', $slugs, true), 'listAvailableDemoFirms() includes nirvana');
assertTrue(count($available) === 4, 'all 4 seeded demo firms are listed (the full rich dataset)');

// --- an admin account missing its TOTP secret is correctly excluded ------
// (simulates a partial/corrupted seed, or a future change that stops
// setting the secret) — wrapped in a transaction so the real seeded data is
// restored afterward regardless of how this test exits.
$db->beginTransaction();
try {
    $db->prepare("UPDATE users SET mfa_secret = NULL WHERE id = :id")->execute([':id' => $nirvana['id']]);

    assertTrue(
        resolveDemoFirmAdmin($db, 'nirvana') === null,
        'a firm_admin account with its TOTP secret cleared is no longer demo-login-ready'
    );

    $availableAfter = listAvailableDemoFirms($db);
    $slugsAfter = array_column($availableAfter, 'slug');
    assertTrue(
        !in_array('nirvana', $slugsAfter, true),
        'listAvailableDemoFirms() stops offering a firm whose admin lost its secret'
    );
    assertTrue(count($availableAfter) === 3, 'the other 3 seeded firms are unaffected');
} finally {
    $db->rollBack(); // restore the real seeded secret — this is live demo data, not a disposable fixture
}

// Confirm the rollback actually restored things — the real demo dataset
// must be exactly as usable after this test as before it ran.
assertTrue(
    resolveDemoFirmAdmin($db, 'nirvana') !== null,
    "nirvana's real secret is restored after the transaction rolled back"
);

echo "\nAll demo access DB tests passed.\n";
