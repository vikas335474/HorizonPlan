<?php
declare(strict_types=1);

/**
 * Password-reset integration test — runs the real request/confirm logic
 * against a live MySQL, exercising the same queries password_reset_request.php
 * and password_reset_confirm.php run (without going through HTTP — a pure PDO
 * exercise of the same SQL, same shape as test_auth_db.php).
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is unreachable,
 * or password_resets isn't migrated yet — safe to run anywhere:
 *   php tests/test_password_reset_db.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at the
 * end on success. On a failed assertion, assertTrue() calls exit() directly —
 * which does NOT run a `finally` block in PHP — so cleanup here deliberately
 * relies on the same mechanism test_auth_db.php does: killing the PHP process
 * mid-transaction drops the connection, and MySQL implicitly rolls back any
 * transaction still open on a closed connection. Do not "fix" this by adding a
 * finally-block rollBack(); it would never run for the same reason.
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
if (!in_array('password_resets', $present, true)) {
    skip('password_resets table not migrated — run sql/009_password_resets.sql to enable this test.');
}

$testEmail = 'pwreset-test-' . bin2hex(random_bytes(4)) . '@example.invalid';

$db->beginTransaction();

$db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Test Fixture', 'distribution')")
   ->execute();
$tenantId = (int) $db->lastInsertId();

$db->prepare(
    "INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'advisor')"
)->execute([
    ':t' => $tenantId,
    ':e' => $testEmail,
    ':h' => password_hash('original-password-123', PASSWORD_BCRYPT),
]);
$userId = (int) $db->lastInsertId();

// --- issue a token exactly as password_reset_request.php does ----------------
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', time() + 1800);

$db->prepare(
    'INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (:h, :u, :e)'
)->execute([':h' => $tokenHash, ':u' => $userId, ':e' => $expiresAt]);

// --- lookup by hash succeeds for an unexpired, unused token -------------------
$lookup = $db->prepare(
    'SELECT id, user_id FROM password_resets
     WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
$lookup->execute([':h' => $tokenHash]);
$found = $lookup->fetch();
assertTrue($found !== false && (int) $found['user_id'] === $userId,
    'a freshly issued token is found by its hash and resolves to the right user');

// --- an expired token is NOT returned by the same lookup ----------------------
$expiredHash = hash('sha256', bin2hex(random_bytes(32)));
$db->prepare(
    "INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (:h, :u, NOW() - INTERVAL 1 MINUTE)"
)->execute([':h' => $expiredHash, ':u' => $userId]);
$lookup->execute([':h' => $expiredHash]);
assertTrue($lookup->fetch() === false, 'an expired token is not returned, even though it exists in the table');

// --- redeeming a token (as password_reset_confirm.php does) -------------------
$db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
   ->execute([':hash' => password_hash('new-password-456', PASSWORD_BCRYPT), ':id' => $userId]);
$db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
   ->execute([':u' => $userId]);

$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$hash = $stmt->fetchColumn();
assertTrue(password_verify('new-password-456', $hash), 'password_hash reflects the new password after redemption');
assertTrue(!password_verify('original-password-123', $hash), 'the old password no longer verifies');

// --- the same (now-used) token cannot be looked up again (single-use) --------
$lookup->execute([':h' => $tokenHash]);
assertTrue($lookup->fetch() === false, 'a redeemed token is excluded by the used_at IS NULL check — single-use enforced');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll password-reset DB tests passed.\n";
