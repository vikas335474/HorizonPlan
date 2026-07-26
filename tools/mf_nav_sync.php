<?php
declare(strict_types=1);

/**
 * CLI entry point for the daily MF NAV price-sync cron. CLI ONLY, same
 * convention as tools/bootstrap_admin.php — see api/lib/MfNavSync.php for
 * the actual logic (kept in api/lib/ rather than here, same reasoning as
 * DemoSeeder.php's move: a future endpoint could need this in-request, and
 * .github/workflows/deploy.yml never ships tools/ to production).
 *
 * Intended to run once a day via Hostinger's hPanel → Advanced → Cron Jobs,
 * after AMFI typically publishes NAVs for the day (see DEPLOY.md's "Daily MF
 * NAV price-sync cron" section for the exact hPanel setup):
 *
 *   php tools/mf_nav_sync.php
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
require_once __DIR__ . '/../api/lib/MfNavSync.php';

$db = getPdo();
$result = syncMfNavCache($db);

if ($result['fetch_failed']) {
    fwrite(STDERR, "MF NAV sync: FAILED to fetch AMFI's NAV export — left the existing cache/portfolio values untouched.\n");
    fwrite(STDERR, "  Held schemes awaiting a price: " . $result['held_scheme_count'] . "\n");
    exit(1);
}

echo "MF NAV sync: done.\n";
echo "  Schemes actually held across the platform: {$result['held_scheme_count']}\n";
echo "  Schemes matched + cached this run:         {$result['cached']}\n";
echo "  client_portfolio_items rows updated:       {$result['portfolio_rows_updated']}\n";
if (!empty($result['unmatched'])) {
    echo "  Held scheme codes NOT found in today's AMFI export (still 'price pending'):\n";
    foreach ($result['unmatched'] as $code) {
        echo "    - $code\n";
    }
}
