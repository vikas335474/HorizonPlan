<?php
declare(strict_types=1);

// Public, unauthenticated, read-only list of demo firms actually ready for
// the "try a live demo" picker (Login.jsx). GET, so no CSRF check per the
// existing convention (verifyCsrfToken() is only ever called for
// POST/PUT/PATCH/DELETE). Returns an empty list rather than an error if
// nothing has been seeded yet — the frontend hides the section entirely in
// that case.

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/DemoAccess.php';

header('Content-Type: application/json; charset=UTF-8');

$db = getPdo();

echo json_encode([
    'status' => 'success',
    'firms'  => listAvailableDemoFirms($db),
]);
