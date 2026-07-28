<?php
declare(strict_types=1);

/**
 * P0-3 · Scheduled / emailed plan reviews (docs/10).
 *
 * The logic behind tools/plan_review_send.php (kept in api/lib/ so a future
 * endpoint could reuse it and deploy.yml never ships tools/ to production —
 * same convention as MfNavSync.php). Runs cross-tenant, like the MF NAV sync:
 * the daily cron sweeps every firm's clients, so these queries are deliberately
 * not TenantScopedDb-wrapped (that helper is for per-tenant *requests*; the
 * advisor-facing read/update endpoints for this feature stay scoped). Requires
 * db_config.php (getPdo) and Mailer.php (sendMail) to be loaded by the caller.
 *
 * Guardrail: cadence defaults to 'off' and a client is only ever emailed when
 * an advisor has explicitly opted them in. Demo-mode suppression is handled by
 * sendMail() itself — a demo firm's client is never really emailed.
 */

require_once __DIR__ . '/Mailer.php';

/** DateInterval spec for a cadence, or null for 'off'/unknown. */
function planReviewIntervalSpec(string $cadence): ?string
{
    return match ($cadence) {
        'quarterly' => 'P3M',
        'annually'  => 'P1Y',
        default     => null,
    };
}

/**
 * Clients whose next review email is due at $now: a non-off cadence, and either
 * never sent or last sent at least one cadence-interval ago. Pure read — does
 * not send or mutate anything, so it's directly unit-testable.
 *
 * @return list<array{schedule_id:int, client_id:int, tenant_id:int, cadence:string, last_sent_at:?string, email:string}>
 */
function dueReviewClients(PDO $db, DateTimeImmutable $now): array
{
    $sql = "SELECT s.id AS schedule_id, s.client_id, s.tenant_id, s.cadence,
                   s.last_sent_at, u.email
            FROM plan_review_schedules s
            JOIN users u ON u.id = s.client_id AND u.role = 'client'
            WHERE s.cadence <> 'off'";
    $rows = $db->query($sql)->fetchAll();

    $due = [];
    foreach ($rows as $r) {
        $spec = planReviewIntervalSpec((string) $r['cadence']);
        if ($spec === null) {
            continue;
        }
        if ($r['last_sent_at'] === null) {
            $due[] = $r; // never sent → due now
            continue;
        }
        $nextDue = (new DateTimeImmutable((string) $r['last_sent_at']))->add(new DateInterval($spec));
        if ($now >= $nextDue) {
            $due[] = $r;
        }
    }
    return $due;
}

/**
 * Plain-text review email. Links the client to their own self-service login
 * (client login already exists) rather than attaching a report — the report
 * PDF is a client-side/browser artefact, so the email points them at the live
 * plan instead. Kept simple and firm-neutral in wording.
 *
 * @return array{subject:string, body:string}
 */
function composePlanReviewEmail(array $client, string $appBaseUrl): array
{
    $link = rtrim($appBaseUrl, '/') . '/login';
    $subject = 'Your financial plan — time for a review';
    $body = "Hello,\n\n"
        . "Your adviser has scheduled a periodic review of your financial plan.\n\n"
        . "Sign in to see your latest goals, projections and portfolio:\n"
        . $link . "\n\n"
        . "If anything has changed — income, expenses, a new goal — mention it to your\n"
        . "adviser so the plan stays current.\n\n"
        . "— Sent on behalf of your advisory firm via HorizonPlan\n";
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Send every due review email and advance last_sent_at for the ones that went
 * out (sendMail() returns true on a real send *or* a demo-mode suppression;
 * only a genuine mail() failure returns false, and those are retried next run).
 *
 * @return array{due:int, sent:int, failed:int}
 */
function sendDuePlanReviews(PDO $db, DateTimeImmutable $now, string $appBaseUrl): array
{
    $due = dueReviewClients($db, $now);
    $upd = $db->prepare("UPDATE plan_review_schedules SET last_sent_at = :now WHERE id = :id");

    $sent = 0;
    $failed = 0;
    foreach ($due as $r) {
        ['subject' => $subject, 'body' => $body] = composePlanReviewEmail($r, $appBaseUrl);
        if (sendMail((string) $r['email'], $subject, $body)) {
            $upd->execute([':now' => $now->format('Y-m-d H:i:s'), ':id' => (int) $r['schedule_id']]);
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['due' => count($due), 'sent' => $sent, 'failed' => $failed];
}
