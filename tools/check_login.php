<?php
declare(strict_types=1);

/**
 * Login diagnostics — CLI ONLY.
 *
 * Answers "why is login failing?" without exposing password hashes, by checking
 * the things login.php actually depends on, in order:
 *   1. DB connection works (db_config.php correct)
 *   2. every migration table exists
 *   3. the user row exists for the given email
 *   4. whether MFA is enrolled (login returns 202, not a full session)
 *   5. whether a supplied password actually verifies against the stored hash
 *
 * This deliberately reveals whether an email exists / a password matches —
 * exactly what the public login endpoint hides to prevent enumeration. That is
 * fine here because it is an admin tool gated to the command line: it refuses to
 * run over the web, and the deploy pipeline never copies tools/ into public_html.
 *
 * Usage (run on the server via SSH, or locally against the DB):
 *   php tools/check_login.php you@example.com
 *   php tools/check_login.php you@example.com 'the-password-to-test'
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this diagnostic runs from the command line only.\n");
}

$configPath = __DIR__ . '/../api/db_config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "FAIL: api/db_config.php not found. Copy db_config.example.php to db_config.php and fill in real credentials.\n");
    exit(2);
}
require_once $configPath;

$email    = $argv[1] ?? '';
$password = $argv[2] ?? null;

if ($email === '') {
    fwrite(STDERR, "Usage: php tools/check_login.php <email> [password]\n");
    exit(2);
}

function line(string $status, string $msg): void
{
    echo str_pad($status, 6) . $msg . "\n";
}

// --- 1. DB connection ---------------------------------------------------------
try {
    $db = getPdo();
    line('OK', 'Database connection succeeded (host/name/user/pass in db_config.php are valid).');
} catch (Throwable $e) {
    line('FAIL', 'Could not connect to the database: ' . $e->getMessage());
    echo "\n→ Fix db_config.php (DB_HOST is 'localhost' on Hostinger; DB_NAME/DB_USER carry the uNNNN_ prefix).\n";
    exit(1);
}

// --- 2. Tables exist ----------------------------------------------------------
$expected = [
    'tenants', 'users', 'active_sessions', 'base_plans',
    'sub_scenarios', 'change_log', 'login_attempts', 'mfa_pending',
];
$present = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($expected, $present));
if ($missing) {
    line('FAIL', 'Missing tables: ' . implode(', ', $missing));
    echo "\n→ Import the matching files from /sql in phpMyAdmin, in numeric order.\n";
    echo "  login.php touches users + login_attempts + active_sessions on every attempt,\n";
    echo "  so any of those missing makes login 500 regardless of the password.\n";
    exit(1);
}
line('OK', 'All ' . count($expected) . ' expected tables exist.');

// --- 3. Rate-limit query actually runs (regression guard for the INTERVAL bug) -
try {
    $db->query("SELECT COUNT(*) FROM login_attempts
                WHERE successful = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)")->fetchColumn();
    line('OK', 'login_attempts window query runs (no native-prepare INTERVAL error).');
} catch (Throwable $e) {
    line('FAIL', 'login_attempts window query failed: ' . $e->getMessage());
    exit(1);
}

// --- 4. User lookup -----------------------------------------------------------
$stmt = $db->prepare('SELECT id, tenant_id, role, password_hash, mfa_secret FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    line('FAIL', "No user row for email '{$email}'.");
    echo "\n→ The account was never created, or the email differs (check for typos / trailing spaces).\n";
    echo "  Create one with: php tools/set_password.php <email> <password> <tenant_id> <role>\n";
    exit(1);
}
line('OK', "User found: id={$user['id']}, tenant_id={$user['tenant_id']}, role={$user['role']}.");

// Hash sanity — a bcrypt hash starts with $2y$ and is 60 chars. A row seeded
// with a plaintext or mis-formatted value can never verify.
$hash = (string) $user['password_hash'];
if (!preg_match('/^\$2[aby]\$/', $hash) || strlen($hash) < 60) {
    line('WARN', 'password_hash does not look like a bcrypt hash (expected $2y$...\, 60 chars). '
        . 'Length=' . strlen($hash) . '. This row will never verify — reset it with tools/set_password.php.');
} else {
    line('OK', 'password_hash is a well-formed bcrypt hash.');
}

// --- 5. MFA state -------------------------------------------------------------
if (!empty($user['mfa_secret'])) {
    line('INFO', 'MFA IS enrolled → login.php returns HTTP 202 (mfa_required), not a session. '
        . 'The client must then POST the authenticator code to mfa_verify.php. '
        . 'If the frontend treats 202 as a failure, that looks like "login failed".');
} else {
    line('INFO', 'MFA is not enrolled → a correct password yields a full session directly.');
}

// --- 6. Password check (optional) --------------------------------------------
if ($password === null) {
    echo "\nNo password argument supplied — skipped the credential check.\n";
    echo "Re-run with the password to test it:  php tools/check_login.php {$email} 'password'\n";
    exit(0);
}

if (password_verify($password, $hash)) {
    line('OK', 'Password MATCHES the stored hash. Credentials are correct.');
    echo "\n→ If login still fails in the browser with correct credentials, the cause is downstream:\n";
    echo "  a 202 MFA step (see above), a stale cached JS bundle (hard-refresh), or a 500 in\n";
    echo "  issueSession/active_sessions. Check the login.php response in DevTools → Network.\n";
    exit(0);
}

line('FAIL', 'Password does NOT match the stored hash.');
echo "\n→ The password is wrong for this account, or the stored hash is for a different password.\n";
echo "  Set a known password with:  php tools/set_password.php {$email} 'new-password'\n";
exit(1);
