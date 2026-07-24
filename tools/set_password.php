<?php
declare(strict_types=1);

/**
 * Set (or create) a user's password with a correct bcrypt hash — CLI ONLY.
 *
 * Use this when check_login.php reports a wrong/mis-formatted hash, or to seed
 * the first account on a fresh database. Passwords are stored with
 * password_hash(PASSWORD_BCRYPT), exactly as login.php expects.
 *
 * CLI-gated and never copied into public_html by the deploy pipeline.
 *
 * Update an existing account's password:
 *   php tools/set_password.php you@example.com 'new-password'
 *
 * Create a new account (tenant_id and role required):
 *   php tools/set_password.php you@example.com 'new-password' 1 advisor
 *   roles: super_admin | advisor | client
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this tool runs from the command line only.\n");
}

$configPath = __DIR__ . '/../api/db_config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "FAIL: api/db_config.php not found.\n");
    exit(2);
}
require_once $configPath;

$email    = $argv[1] ?? '';
$password = $argv[2] ?? '';
$tenantId = isset($argv[3]) ? (int) $argv[3] : null;
$role     = $argv[4] ?? null;

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php tools/set_password.php <email> <password> [tenant_id] [role]\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "FAIL: password must be at least 8 characters (matches the app's own rule).\n");
    exit(2);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $db = getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $upd = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $upd->execute([':hash' => $hash, ':id' => $existing['id']]);
    echo "OK: password updated for {$email} (user id {$existing['id']}).\n";
    exit(0);
}

// No such user — create one, but only if we have the required columns.
$validRoles = ['super_admin', 'advisor', 'client'];
if ($tenantId === null || $role === null) {
    fwrite(STDERR, "FAIL: no user '{$email}' exists. To create one, pass tenant_id and role:\n");
    fwrite(STDERR, "  php tools/set_password.php {$email} '<password>' <tenant_id> <" . implode('|', $validRoles) . ">\n");
    exit(1);
}
if (!in_array($role, $validRoles, true)) {
    fwrite(STDERR, "FAIL: role must be one of: " . implode(', ', $validRoles) . "\n");
    exit(2);
}

// Guard the foreign key so the error is readable rather than a raw constraint dump.
$t = $db->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
$t->execute([':id' => $tenantId]);
if (!$t->fetch()) {
    fwrite(STDERR, "FAIL: no tenant with id {$tenantId}. Create a tenant first (see sql/001_tenants.sql).\n");
    exit(1);
}

$ins = $db->prepare(
    'INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:tenant_id, :email, :hash, :role)'
);
$ins->execute([
    ':tenant_id' => $tenantId,
    ':email'     => $email,
    ':hash'      => $hash,
    ':role'      => $role,
]);
echo "OK: created user {$email} (id {$db->lastInsertId()}, tenant {$tenantId}, role {$role}).\n";
exit(0);
