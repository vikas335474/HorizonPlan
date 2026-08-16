<?php
declare(strict_types=1);

/**
 * docs/13 I-9 · CLI entry point for the monthly plan-digest cron. CLI ONLY,
 * same convention as tools/plan_review_send.php and tools/mf_nav_sync.php —
 * the actual logic lives in api/lib/PersonalDigestMailer.php (kept there so
 * .github/workflows/deploy.yml never ships tools/ to production).
 *
 * Intended to run once a MONTH via Hostinger's hPanel -> Advanced -> Cron Jobs.
 * Run it a few days AFTER tools/progress_snapshot.php so the month's snapshot
 * already exists and the digest describes fresh facts rather than last
 * month's. Suggested schedule — the 4th, after the 1st-of-month snapshot:
 *
 *   0 6 4 * *   php /home/USER/public_html/tools/personal_digest_send.php
 *
 * Safe to run more than once: dueDigestRecipients() only returns people whose
 * last send is at least a month old, so a same-day re-run mails nobody twice.
 *
 * NOBODY IS EMAILED UNLESS THEY ASKED. The recipient query requires
 * plan_digest_opt_in = 1 (sql/041, default 0) and a kind='personal' tenant.
 * Demo tenants are additionally protected by sendMail() itself, which
 * suppresses delivery entirely when platform_settings.demo_mode is on.
 *
 * --dry-run prints what would be sent and mails nothing. Worth using the first
 * time this runs on a real database.
 *
 *   php tools/personal_digest_send.php
 *   php tools/personal_digest_send.php --dry-run
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
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/CashFlowSummary.php';
require_once __DIR__ . '/../api/lib/FinancialFoundations.php';
require_once __DIR__ . '/../api/lib/FoundationsInputs.php';
require_once __DIR__ . '/../api/lib/AlertsEngine.php';
require_once __DIR__ . '/../api/lib/AlertsInputs.php';
require_once __DIR__ . '/../api/lib/PersonalDigestMailer.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$now = new DateTimeImmutable();

$appBaseUrl = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? APP_BASE_URL
    : 'https://horizonplan.in';

try {
    $db = getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: could not connect to the database.\n");
    exit(2);
}

$recipients = dueDigestRecipients($db, $now);
if ($recipients === []) {
    echo "No one is due a digest right now.\n";
    exit(0);
}

$sent = 0;
$skippedQuiet = 0;
$failed = 0;

foreach ($recipients as $person) {
    // The facts come from the SAME alerts path the person's own dashboard
    // reads, gathered through a TenantScopedDb built for their own tenant —
    // so this cron never sees a wider slice than a request would.
    $scopedDb = new TenantScopedDb($db, $person['tenant_id']);

    try {
        $bundle = gatherClientAlertBundle($scopedDb, $db, $person['user_id']);
        $alerts = computeClientAlerts($bundle);
    } catch (Throwable $e) {
        // One person's bad data must never stop the sweep for everybody else.
        fwrite(STDERR, "WARN: could not gather alerts for user {$person['user_id']}: {$e->getMessage()}\n");
        $failed++;
        continue;
    }

    $body = composeDigestBody($alerts, $person['display_name'], $appBaseUrl);
    if ($body === null) {
        // Nothing worth saying this month. Deliberately does NOT mark as sent,
        // so this person is reconsidered on the next run rather than waiting a
        // further month once they do have something to hear.
        $skippedQuiet++;
        continue;
    }

    if ($dryRun) {
        echo "--- would send to {$person['email']} ---\n{$body}\n\n";
        $sent++;
        continue;
    }

    if (sendMail($person['email'], digestSubject(), $body)) {
        markDigestSent($db, $person['user_id'], $now);
        $sent++;
    } else {
        // Not marked sent, so the next run retries rather than losing a month.
        fwrite(STDERR, "WARN: send failed for user {$person['user_id']}.\n");
        $failed++;
    }
}

echo ($dryRun ? "Dry run: " : "")
    . "{$sent} sent, {$skippedQuiet} skipped (nothing to say), {$failed} failed.\n";

exit($failed > 0 ? 1 : 0);
