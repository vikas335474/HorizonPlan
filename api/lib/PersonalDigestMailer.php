<?php
declare(strict_types=1);

/**
 * docs/13 I-9 · The monthly plan digest for a self-serve individual.
 *
 * The logic behind tools/personal_digest_send.php, kept in api/lib/ for the
 * same two reasons PlanReviewMailer and MfNavSync are: an endpoint may want to
 * reuse it, and .github/workflows/deploy.yml never ships tools/ to production.
 *
 * WHAT THIS SENDS, AND WHAT IT REFUSES TO SEND.
 * The body is assembled from AlertsEngine's existing facts — the same five
 * triggers the person's own dashboard shows. That is deliberate: a digest that
 * computed its own view of a plan would eventually contradict the app, and
 * being told two different things about your own money is worse than being
 * told nothing.
 *
 * Every line states what IS true. None of them says what to do. "Closing this
 * takes ₹8,400 more a month" is arithmetic; "you should invest ₹8,400 more" is
 * advice, and this product does not give advice — in an email least of all,
 * where there is no chart next to it and no way to ask a follow-up.
 * tests/test_personal_digest.php asserts no instruction word survives into a
 * composed body, the same guard AlertsEngine already holds itself to.
 *
 * NOTHING IS SENT TO SOMEONE WHO DID NOT ASK. dueDigestRecipients() selects
 * only plan_digest_opt_in = 1 (sql/041, default 0), and only inside a
 * kind='personal' tenant — a firm's client has an adviser whose job this is,
 * and emailing them directly would cut across that relationship.
 *
 * Cross-tenant by design, like the other crons: it sweeps every personal
 * tenant, so the recipient query is deliberately not TenantScopedDb-wrapped.
 * The per-person FACTS are still gathered through a properly scoped
 * TenantScopedDb built for that person's own tenant, so the alerts path this
 * reuses is never handed a wider view than a request would get.
 */

require_once __DIR__ . '/Mailer.php';

/** How often the digest goes out. Monthly — see the cron's own header. */
const DIGEST_INTERVAL_SPEC = 'P1M';

/**
 * People due a digest at $now: opted in, inside a personal tenant, and either
 * never sent or last sent at least one interval ago.
 *
 * Pure read — sends nothing and mutates nothing, so it is directly testable
 * against a fixture database.
 *
 * @return list<array{user_id:int, tenant_id:int, email:string, display_name:?string}>
 */
function dueDigestRecipients(PDO $db, DateTimeImmutable $now): array
{
    $cutoff = $now->sub(new DateInterval(DIGEST_INTERVAL_SPEC))->format('Y-m-d H:i:s');

    $sql = "SELECT u.id AS user_id, u.tenant_id, u.email, u.display_name
              FROM users u
              JOIN tenants t ON t.id = u.tenant_id
             WHERE u.plan_digest_opt_in = 1
               AND u.role = 'client'
               AND t.kind = 'personal'
               AND (u.plan_digest_last_sent_at IS NULL
                    OR u.plan_digest_last_sent_at <= :cutoff)
             ORDER BY u.plan_digest_last_sent_at IS NULL DESC, u.plan_digest_last_sent_at ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute([':cutoff' => $cutoff]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'user_id'      => (int) $row['user_id'],
            'tenant_id'    => (int) $row['tenant_id'],
            'email'        => (string) $row['email'],
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }
    return $out;
}

/**
 * Compose the digest body from a person's alerts.
 *
 * Pure: takes the already-computed alerts array (AlertsEngine's shape) and
 * returns plain text. No DB, no PDO, no clock — so the wording is unit-testable
 * without a fixture.
 *
 * Returns NULL when there is nothing worth saying. A monthly email that exists
 * only to say "nothing changed" is how a useful digest becomes filtered noise;
 * silence is a better product than filler, and it is the same convention
 * AlertsCard already follows by rendering nothing when it has nothing.
 *
 * @param list<array{type:string,severity:string,factual_message:string}> $alerts
 * @param string|null $displayName the person's own name, when they gave one
 * @param string      $appBaseUrl  for the single link back into the plan
 */
function composeDigestBody(array $alerts, ?string $displayName, string $appBaseUrl): ?string
{
    if ($alerts === []) {
        return null;
    }

    $greetingName = ($displayName !== null && trim($displayName) !== '')
        ? ' ' . trim($displayName)
        : '';

    $positive  = [];
    $attention = [];
    foreach ($alerts as $alert) {
        $message = (string) ($alert['factual_message'] ?? '');
        if ($message === '') {
            continue;
        }
        if (($alert['severity'] ?? '') === 'positive') {
            $positive[] = $message;
        } else {
            $attention[] = $message;
        }
    }

    if ($positive === [] && $attention === []) {
        return null;
    }

    $lines = [];
    $lines[] = "Hello{$greetingName},";
    $lines[] = '';
    $lines[] = "Here's where your plan stands this month.";
    $lines[] = '';

    // Good news first. Not a motivational trick — a person scanning an email
    // about their retirement deserves to see "this one is on track" before a
    // list of things that need attention, and the ordering is the only editorial
    // choice made anywhere in this file.
    foreach ($positive as $message) {
        $lines[] = "  - {$message}";
    }
    foreach ($attention as $message) {
        $lines[] = "  - {$message}";
    }

    $lines[] = '';
    $lines[] = 'See the detail, and change any number you like:';
    $lines[] = rtrim($appBaseUrl, '/') . '/goals';
    $lines[] = '';
    // Every one of the statements above is a fact about the plan as it stands.
    // Saying so out loud is not boilerplate here — it is the same promise the
    // in-app disclosure makes, and an email is read away from that context.
    $lines[] = 'These are statements about your own plan as it stands today, not';
    $lines[] = 'advice and not a forecast. Nothing has been changed for you.';
    $lines[] = '';
    $lines[] = 'To stop these emails, turn off the monthly summary in Settings.';

    return implode("\n", $lines);
}

/** The subject line. Deliberately dull and consistent — a digest is not news. */
function digestSubject(): string
{
    return 'Your plan this month';
}

/**
 * Mark a person as having been sent this period's digest.
 *
 * Called only after sendMail() reports success, so a send that failed is
 * retried on the next run rather than silently skipped for a month.
 */
function markDigestSent(PDO $db, int $userId, DateTimeImmutable $now): void
{
    $stmt = $db->prepare('UPDATE users SET plan_digest_last_sent_at = :now WHERE id = :id');
    $stmt->execute([':now' => $now->format('Y-m-d H:i:s'), ':id' => $userId]);
}
