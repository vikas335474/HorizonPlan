<?php
declare(strict_types=1);

/**
 * Auth integration test — runs the real security_gatekeeper queries against a
 * live MySQL. This is the test that would have caught the "INTERVAL :param
 * MINUTE" native-prepare bug: that query only fails when actually executed by
 * MySQL, never under `php -l` or a pure unit test.
 *
 * It self-skips (exit 0) when api/db_config.php is absent or the DB is
 * unreachable, so it is safe to run anywhere — but run it against a real
 * database (locally or on the server via SSH) to get real coverage:
 *   php tests/test_auth_db.php
 *
 * Non-destructive: all writes happen inside a transaction that is rolled back.
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

$present = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('login_attempts', $present, true)) {
    skip('login_attempts table not migrated — run sql/007_login_attempts.sql to enable this test.');
}

// --- checkLoginRateLimit executes without throwing (the INTERVAL regression) --
$testEmail = 'ratelimit-test-' . bin2hex(random_bytes(4)) . '@example.invalid';
$testIp    = '203.0.113.250'; // TEST-NET-3, never a real client

try {
    $allowed = checkLoginRateLimit($db, $testEmail, $testIp);
    assertTrue($allowed === true,
        'checkLoginRateLimit() runs against MySQL and allows a fresh email/IP (no INTERVAL syntax error)');
} catch (Throwable $e) {
    assertTrue(false, 'checkLoginRateLimit() threw: ' . $e->getMessage());
}

// --- the rate limit actually trips after MAX_ATTEMPTS failures ----------------
$db->beginTransaction();
try {
    for ($i = 0; $i < LOGIN_RATE_LIMIT_MAX_ATTEMPTS; $i++) {
        recordLoginAttempt($db, $testEmail, $testIp, false);
    }
    assertTrue(checkLoginRateLimit($db, $testEmail, $testIp) === false,
        'checkLoginRateLimit() blocks after ' . LOGIN_RATE_LIMIT_MAX_ATTEMPTS . ' failed attempts');

    // A successful attempt is recorded but does not lift the block (failures
    // within the window still count) — documents the current behaviour.
    recordLoginAttempt($db, $testEmail, $testIp, true);
    assertTrue(checkLoginRateLimit($db, $testEmail, $testIp) === false,
        'a later success does not clear failures still inside the window');
} finally {
    $db->rollBack(); // leave login_attempts exactly as we found it
}

echo "\nAll auth DB tests passed.\n";
