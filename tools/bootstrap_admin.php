<?php
declare(strict_types=1);

/**
 * Bootstrap the first Super Admin — CLI ONLY.
 *
 * The tenant/advisor onboarding endpoints all require an existing super_admin
 * session, so the very first one has to be seeded out-of-band. This creates a
 * tenant to house the admin account plus the super_admin user itself, in one
 * transaction. Run it once on a fresh database, then use the in-app Super Admin
 * console for everything after.
 *
 * CLI-gated and never copied into public_html by the deploy pipeline.
 *
 *   php tools/bootstrap_admin.php <admin-email> <password> ["Firm/Org name"]
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

$email    = strtolower(trim((string) ($argv[1] ?? '')));
$password = (string) ($argv[2] ?? '');
$orgName  = trim((string) ($argv[3] ?? 'HorizonPlan Admin'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php tools/bootstrap_admin.php <admin-email> <password(>=8 chars)> [\"Org name\"]\n");
    exit(2);
}

try {
    $db = getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$existing = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    fwrite(STDERR, "FAIL: a user with email {$email} already exists.\n");
    exit(1);
}

$db->beginTransaction();
try {
    $db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES (:name, 'distribution')")
       ->execute([':name' => $orgName]);
    $tenantId = (int) $db->lastInsertId();

    $ins = $db->prepare(
        'INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, :r)'
    );
    $ins->execute([
        ':t' => $tenantId,
        ':e' => $email,
        ':h' => password_hash($password, PASSWORD_BCRYPT),
        ':r' => 'super_admin',
    ]);
    $adminId = (int) $db->lastInsertId();

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "OK: created super_admin {$email} (id {$adminId}) under tenant '{$orgName}' (id {$tenantId}).\n";
echo "Sign in and use the Super Admin console to onboard advisory firms.\n";
exit(0);
