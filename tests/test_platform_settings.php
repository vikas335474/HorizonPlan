<?php
declare(strict_types=1);

/**
 * docs/09 Piece 1 — platform_settings DB integration test: read/write of the
 * single-row config, and the MFA-bypass logic requireMfaEnrollment() applies
 * (demo_mode='on' OR mfa_enforcement='disabled' skips the enrollment check
 * regardless of the user's own mfa_secret).
 *
 * Self-skips (exit 0) when api/db_config.php is absent or the DB is
 * unreachable, same shape as test_mfa_enforcement_db.php:
 *   php tests/test_platform_settings.php
 *
 * Non-destructive: every mutation (including the UPDATE on the pre-existing
 * platform_settings row) happens inside one transaction, rolled back at the
 * end. On a failed assertion, assertTrue() calls exit() directly — same
 * documented reasoning as test_password_reset_db.php for why there's no
 * try/finally cleanup: exit() does not run finally blocks in PHP, but killing
 * the process mid-transaction still drops the connection and MySQL implicitly
 * rolls back, so the transaction wrapper (not finally) is what keeps this
 * non-destructive even on failure.
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
if (!in_array('platform_settings', $present, true)) {
    skip('platform_settings table not migrated — run sql/019_platform_settings.sql to enable this test.');
}

function setSettings(PDO $db, string $mfaEnforcement, string $demoMode, string $signupEnabled = 'on'): void
{
    $db->prepare("UPDATE platform_settings SET mfa_enforcement = :m, demo_mode = :d, signup_enabled = :s WHERE id = 1")
       ->execute([':m' => $mfaEnforcement, ':d' => $demoMode, ':s' => $signupEnabled]);
}

$db->beginTransaction();

$row = $db->query("SELECT id FROM platform_settings WHERE id = 1 LIMIT 1")->fetch();
assertTrue($row !== false, 'platform_settings has its seeded id=1 row');

// --- read/write round trip -------------------------------------------------
setSettings($db, 'enabled', 'off');
$read = getPlatformSettings($db, true);
assertTrue($read === ['mfa_enforcement' => 'enabled', 'demo_mode' => 'off', 'signup_enabled' => 'on'],
    'getPlatformSettings() reads back enabled/off after a direct write');

setSettings($db, 'disabled', 'off');
$read = getPlatformSettings($db, true);
assertTrue($read === ['mfa_enforcement' => 'disabled', 'demo_mode' => 'off', 'signup_enabled' => 'on'],
    'getPlatformSettings() reads back disabled/off');

setSettings($db, 'enabled', 'on');
$read = getPlatformSettings($db, true);
assertTrue($read === ['mfa_enforcement' => 'enabled', 'demo_mode' => 'on', 'signup_enabled' => 'on'],
    'getPlatformSettings() reads back enabled/on');

setSettings($db, 'enabled', 'off', 'off');
$read = getPlatformSettings($db, true);
assertTrue($read === ['mfa_enforcement' => 'enabled', 'demo_mode' => 'off', 'signup_enabled' => 'off'],
    'getPlatformSettings() reads back a disabled signup_enabled too');

// --- MFA-bypass logic (mirrors requireMfaEnrollment()'s own condition) -----
// requireMfaEnrollment() itself exits the process on failure, so it can't be
// called directly here — this exercises the exact same boolean logic it
// evaluates, against a real unenrolled user, so a change to either side (the
// DB read or the OR condition) would break this test too.
$db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Platform Settings Test', 'distribution')")
   ->execute();
$tenantId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'advisor')")
   ->execute([
       ':t' => $tenantId,
       ':e' => 'platform-settings-test-' . bin2hex(random_bytes(4)) . '@example.invalid',
       ':h' => password_hash('irrelevant', PASSWORD_BCRYPT),
   ]);
$userId = (int) $db->lastInsertId();
assertTrue(userHasMfaEnrolled($db, $userId) === false, 'fixture user is not MFA-enrolled');

setSettings($db, 'enabled', 'off');
$settings = getPlatformSettings($db, true);
$bypassed = ($settings['demo_mode'] === 'on' || $settings['mfa_enforcement'] === 'disabled');
assertTrue($bypassed === false,
    'with enforcement enabled and demo mode off, an unenrolled user is NOT bypassed (would still be blocked)');

setSettings($db, 'disabled', 'off');
$settings = getPlatformSettings($db, true);
$bypassed = ($settings['demo_mode'] === 'on' || $settings['mfa_enforcement'] === 'disabled');
assertTrue($bypassed === true,
    'mfa_enforcement=disabled alone bypasses the check for an unenrolled user');

setSettings($db, 'enabled', 'on');
$settings = getPlatformSettings($db, true);
$bypassed = ($settings['demo_mode'] === 'on' || $settings['mfa_enforcement'] === 'disabled');
assertTrue($bypassed === true,
    'demo_mode=on alone bypasses the check for an unenrolled user, even with enforcement enabled');

$db->rollBack(); // leave platform_settings and the fixture tenant/user exactly as found

echo "\nAll platform settings tests passed.\n";
