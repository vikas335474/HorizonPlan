<?php
declare(strict_types=1);

/**
 * CLI entry point for the rich, multi-firm demo dataset — CLI ONLY, same
 * convention as tools/bootstrap_admin.php.
 *
 * The actual seeding logic (FIRMS, seedDemoDataFull(), etc.) lives in
 * api/lib/DemoSeeder.php, not here — it moved out of this file because
 * api/demo_reset.php and api/lib/DemoAccess.php both need it at real request
 * time, and .github/workflows/deploy.yml's publish step only ever copies
 * frontend/dist/ and api/ into the deployed public_html tree; tools/ is
 * deliberately never shipped to production. A require_once reaching into
 * tools/ from production API code 500s with "failed to open stream" on a
 * real deploy, since the file just isn't there — this file now only ever
 * runs locally/via SSH, exactly as its CLI-only guard below already implied.
 *
 *   php tools/seed_demo_data_full.php
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
require_once __DIR__ . '/../api/lib/DemoSeeder.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $result = seedDemoDataFull($db);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result['created_firms'] === [] && $result['skipped_firms'] !== []) {
    echo "Rich demo dataset already exists — every firm was already seeded, nothing to do.\n";
} else {
    echo "\nSeeded: " . count($result['created_firms']) . " new firm(s)"
       . ($result['skipped_firms'] !== [] ? ' (' . count($result['skipped_firms']) . ' already existed, skipped)' : '')
       . ", {$result['total_clients']} clients, {$result['total_goals']} goals.\n";
}
echo "Platform admin  : " . PLATFORM_ADMIN_EMAIL . " / " . DEMO_PASSWORD . " (needs demo_mode='on', or its own TOTP enrollment, to pass the MFA gate)\n";
echo "Firm admin login: <slug>'s 'admin' handle has a REAL TOTP secret — use the public \"Try a live demo\" picker on /login\n"
   . "                  (api/demo_login.php), which authenticates it directly. Manual password login for this\n"
   . "                  specific account will still prompt for a code nobody has, by design (see DemoAccess.php).\n"
   . "                  Slugs: " . implode(', ', array_column(FIRMS, 'slug')) . "\n";
echo "Other firm logins: <handle>@<slug>.demo.horizonplan.in (senior/junior1/junior2) / " . DEMO_PASSWORD . " — no TOTP secret,\n"
   . "                  needs demo_mode='on' to pass the MFA gate via manual login.\n";
echo "Client logins   : client<N>.<bracket>@<slug>.demo.horizonplan.in / " . DEMO_PASSWORD . " — same demo_mode note as above.\n";
