<?php
declare(strict_types=1);

/**
 * docs/09 Group 2 Session 4 — magic-link invite activation. Covers
 * InviteTokens.php against a real MySQL instance: issuance stores the
 * correct purpose/expiry, the token is redeemable through the exact same
 * lookup query password_reset_confirm.php runs (no purpose branch needed),
 * and unusablePasswordHash() never accidentally produces something
 * guessable or reused across accounts.
 *
 * Self-skips (exit 0) when api/db_config.php is absent, the DB is
 * unreachable, or password_resets.purpose isn't migrated yet:
 *   php tests/test_invite_tokens_db.php
 *
 * Non-destructive: everything runs inside one transaction, rolled back at
 * the end. On a failed assertion, assertTrue() calls exit() directly —
 * same documented reasoning as test_password_reset_db.php for why there's
 * no try/finally cleanup.
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
require_once __DIR__ . '/../api/lib/InviteTokens.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$columns = $db->query("SHOW COLUMNS FROM password_resets LIKE 'purpose'")->fetchAll();
if ($columns === []) {
    skip('password_resets.purpose not migrated yet — run sql/025_password_resets_purpose.sql to enable this test.');
}

$testEmail = 'invite-test-' . bin2hex(random_bytes(4)) . '@example.invalid';

$db->beginTransaction();

$db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES ('Invite Test Fixture', 'distribution')")
   ->execute();
$tenantId = (int) $db->lastInsertId();

$db->prepare(
    "INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, 'advisor')"
)->execute([
    ':t' => $tenantId,
    ':e' => $testEmail,
    ':h' => unusablePasswordHash(),
]);
$userId = (int) $db->lastInsertId();

// --- unusablePasswordHash() never verifies against anything guessable, and
// two calls never collide -----------------------------------------------
$hashA = unusablePasswordHash();
$hashB = unusablePasswordHash();
assertTrue($hashA !== $hashB, 'two unusablePasswordHash() calls produce different hashes');
foreach (['', 'password', 'DemoPass@2026', $testEmail] as $guess) {
    assertTrue(!password_verify($guess, $hashA), "unusablePasswordHash() does not verify against the guessable value '{$guess}'");
}

// --- issueInviteToken() stores purpose='invite' with a ~7-day expiry ----
$before = time();
$rawToken = issueInviteToken($db, $userId);
assertTrue($rawToken !== '' && strlen($rawToken) === 64, 'issueInviteToken() returns a 64-hex-char raw token');

$tokenHash = hash('sha256', $rawToken);
$row = $db->prepare('SELECT user_id, purpose, expires_at, used_at FROM password_resets WHERE token_hash = :h LIMIT 1');
$row->execute([':h' => $tokenHash]);
$stored = $row->fetch();
assertTrue($stored !== false, 'the raw token hashes to a stored row');
assertTrue((int) $stored['user_id'] === $userId, 'the stored row is attributed to the right user');
assertTrue($stored['purpose'] === 'invite', "the stored row's purpose is 'invite', not the reset-flow default");
assertTrue($stored['used_at'] === null, 'a freshly issued invite token is unused');

$expiresInSeconds = strtotime((string) $stored['expires_at']) - $before;
assertTrue(
    $expiresInSeconds > (INVITE_TOKEN_TTL_SECONDS - 60) && $expiresInSeconds <= INVITE_TOKEN_TTL_SECONDS,
    'the invite token expires roughly 7 days out (INVITE_TOKEN_TTL_SECONDS), not the 30-minute reset TTL'
);

// --- buildInviteLink() shapes the link for the /accept-invite route -----
$_SERVER['HTTP_HOST'] = 'app.example.test';
unset($_SERVER['HTTPS']);
$link = buildInviteLink($rawToken);
assertTrue($link === "http://app.example.test/accept-invite?token={$rawToken}",
    'buildInviteLink() builds an /accept-invite link carrying the raw token as a query param');

// --- the invite token redeems through the SAME lookup query
// password_reset_confirm.php runs — no purpose branch needed there --------
$lookup = $db->prepare(
    'SELECT id, user_id FROM password_resets
     WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
$lookup->execute([':h' => $tokenHash]);
$found = $lookup->fetch();
assertTrue($found !== false && (int) $found['user_id'] === $userId,
    'password_reset_confirm.php\'s own lookup query finds an invite-purpose token exactly like a reset-purpose one');

// Redeem it (mirrors password_reset_confirm.php's own redemption logic).
$db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
   ->execute([':hash' => password_hash('advisor-chosen-password-1', PASSWORD_BCRYPT), ':id' => $userId]);
$db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
   ->execute([':u' => $userId]);

$hashRow = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
$hashRow->execute([':id' => $userId]);
$finalHash = $hashRow->fetchColumn();
assertTrue(password_verify('advisor-chosen-password-1', $finalHash),
    'redeeming the invite token sets the advisor-chosen password, same as a real reset redemption');

// --- single-use: the same invite token cannot be redeemed twice ---------
$lookup->execute([':h' => $tokenHash]);
assertTrue($lookup->fetch() === false, 'a redeemed invite token is excluded by the used_at IS NULL check — single-use enforced');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll invite token DB tests passed.\n";
