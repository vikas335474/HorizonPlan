<?php
declare(strict_types=1);

/**
 * P1-1 · CLI entry point for the goal-progress snapshot cron. CLI ONLY, same
 * convention as tools/mf_nav_sync.php — see api/lib/ProgressSnapshot.php for
 * the actual logic (kept in api/lib/ rather than here for the same reason: an
 * endpoint already calls the same capture path for a manual "Record now", and
 * .github/workflows/deploy.yml never ships tools/ to production).
 *
 * Intended to run once a MONTH via Hostinger's hPanel → Advanced → Cron Jobs.
 * Monthly, not daily, is deliberate: a plan's corpus is an advisor-maintained
 * figure that moves in steps, and a daily series would be thousands of
 * identical rows saying nothing. A monthly cadence is what a review
 * conversation actually uses. Suggested schedule — 1st of each month, after
 * the daily NAV sync has run so portfolio values are fresh:
 *
 *   0 3 1 * *   php /home/USER/public_html/tools/progress_snapshot.php
 *
 * Safe to run more than once: capture is idempotent per (goal, date) and per
 * (client, date), so a re-run updates that day's rows instead of duplicating
 * them. Rows written here carry created_by_user_id = NULL, which is how a
 * reader tells a scheduled reading from one a person took.
 *
 * Optional argument: a YYYY-MM-DD date to capture "as of" instead of today.
 * Intended for a one-off catch-up after adding the cron, not routine use — it
 * still records TODAY's corpus values under that date, so only use it when
 * that is genuinely what you mean.
 *
 *   php tools/progress_snapshot.php
 *   php tools/progress_snapshot.php 2026-08-01
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this tool runs from the command line only.\n");
}

// api/db_config.php always exists now — it's a tracked loader, not a
// git-ignored file a server setup step might have skipped. It owns the
// "credentials genuinely missing" diagnostic itself (see its header); no
// existence check is needed here anymore.
require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/ProgressSnapshot.php';

$asOf = $argv[1] ?? null;
if ($asOf !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "FAIL: the optional date argument must be YYYY-MM-DD.\n");
    exit(2);
}

$db = getPdo();
$result = captureAllProgress($db, $asOf);

echo "Progress snapshot: done for {$result['as_of_date']} — "
    . "{$result['clients']} client(s), {$result['goals']} goal(s).\n";
