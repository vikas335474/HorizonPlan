<?php
declare(strict_types=1);

/**
 * CLI entry point for the scheduled plan-review email cron (docs/10 P0-3).
 * CLI ONLY, same convention as tools/mf_nav_sync.php — the logic lives in
 * api/lib/PlanReviewMailer.php so a future endpoint could reuse it and
 * deploy.yml never ships tools/ to production.
 *
 * Intended to run once a day via Hostinger's hPanel → Advanced → Cron Jobs
 * (see DEPLOY.md's "Scheduled plan-review emails" section):
 *
 *   php tools/plan_review_send.php
 *
 * It emails only clients an advisor has explicitly opted in (cadence
 * quarterly/annually) whose review is due, and links them to their own login.
 * Demo-mode suppression is handled inside sendMail().
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
require_once __DIR__ . '/../api/lib/PlanReviewMailer.php';

// Absolute URL for the client login link in the email. Set APP_BASE_URL in
// db_config.php (see db_config.example.php); fall back to a placeholder so a
// misconfigured cron logs an obviously-wrong link rather than crashing.
$appBaseUrl = defined('APP_BASE_URL') && APP_BASE_URL !== '' ? APP_BASE_URL : 'https://horizonplan.example';

$db = getPdo();
$result = sendDuePlanReviews($db, new DateTimeImmutable('now'), $appBaseUrl);

echo "Plan-review emails: due={$result['due']}, sent={$result['sent']}, failed={$result['failed']}.\n";
if ($result['failed'] > 0) {
    // Non-zero exit so a wrapping cron/monitor can flag delivery trouble.
    exit(1);
}
