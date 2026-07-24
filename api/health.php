<?php
declare(strict_types=1);

// Lightweight diagnostics for when the app returns opaque 500s (e.g. login
// failing with "Request failed (500)"). Reports whether the DB is reachable
// and whether each table the app needs actually exists on this server — the
// usual root cause being a /sql migration that was never run manually after a
// deploy (migrations are NOT applied automatically; see DEPLOY.md / CLAUDE.md).
//
// Returns ONLY connection status and per-table booleans. No row data, no
// credentials, nothing tenant-scoped, and the table names it checks are
// already public in this repo's /sql directory — so this leaks nothing an
// attacker couldn't read from GitHub. It is intentionally unauthenticated
// because it has to work while login itself is broken.
//
// Once the deploy is healthy, gate this behind an admin check or delete it.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/error_handler.php';
installApiErrorHandlers();

header('Content-Type: application/json; charset=UTF-8');

// Every table the current endpoints touch. Keep in sync with /sql migrations.
$required = [
    'tenants',
    'users',
    'active_sessions',
    'base_plans',
    'sub_scenarios',
    'change_log',
    'login_attempts',
    'mfa_pending',
];

// getPdo() throws on a connection/credential failure — the installed handler
// turns that into a clean JSON 500, which is itself a useful diagnostic
// (db_config.php missing or wrong on the server).
$db = getPdo();

$stmt = $db->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
);
$present = array_map(
    static fn(array $row): string => strtolower((string) $row['table_name']),
    $stmt->fetchAll()
);

$tables  = [];
$missing = [];
foreach ($required as $table) {
    $exists = in_array($table, $present, true);
    $tables[$table] = $exists;
    if (!$exists) {
        $missing[] = $table;
    }
}

echo json_encode([
    'status' => 'ok',
    'health' => [
        'db_connect' => true,
        'tables'     => $tables,
        'missing'    => $missing,
        'all_ok'     => $missing === [],
    ],
]);
