<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-4 · The alerts/rules engine — the unifying surface that
 * makes both dashboards dynamic. Pure — no DB access anywhere in this file,
 * same "computation only" posture as PlanMath.php/FinancialFoundations.php —
 * so every trigger is unit-testable with a synthetic bundle. The DB-touching
 * half that assembles a bundle lives in AlertsInputs.php.
 *
 * EVERY ALERT IS A FACT, NEVER AN INSTRUCTION (docs/12 §2 principle 5). The
 * shape is deliberately flat and small:
 *   {type, severity, subject, factual_message, deep_link}
 * `factual_message` states what IS true; it never tells anyone what to DO.
 * `severity` is 'positive' | 'attention' — the same two-color framing
 * already used across this app's dashboards (teal / amber), not a third
 * invented vocabulary.
 *
 * FIVE TRIGGER TYPES, not six. docs/12's own D-4 text lists a sixth — "LTCG
 * exemption headroom remains this year" — sourced from D-2. It was dropped
 * on purpose: D-2 deliberately never built a headroom figure (this app has
 * no transaction/sale ledger, so it cannot know what's already been
 * realised this financial year — see PortfolioTaxContext.php's header).
 * There is no honest fact behind that trigger given what D-2 actually
 * shipped, and fabricating a weaker substitute would violate the same
 * "never fabricate a figure" principle this whole feature is built on.
 *
 * STATELESS BY DESIGN, THIS SESSION. Every alert here is recomputed fresh
 * from current data on every read — no acknowledge/snooze, no `alert_ack`
 * table. docs/12's own D-4 text recommends a hybrid (compute fresh, persist
 * only the acknowledgement) as the ideal; this session ships the stateless
 * engine only, a deliberate, documented scope cut (see docs/12 §6) rather
 * than bolting a second, separate persisted-dismissal feature onto this one.
 */

require_once __DIR__ . '/PlanReviewMailer.php'; // planReviewIntervalSpec()

/** Days a NAV-tracked holding's cached price can go without a refresh before it's flagged stale — generous over the daily cron's own cadence so one missed weekend/holiday run is never a false alarm. */
const ALERTS_STALE_PRICE_DAYS = 3;

/**
 * A goal reaching its target (docs/12 Prompt D-3's `is_met`, from either
 * targetGoalFunding() or retirementTarget() — both name the field
 * identically, so this one function serves both goal shapes). Fires once,
 * the moment is_met flips true; there is no "un-fire" — a goal that later
 * dips back under is simply not alerted again until it re-crosses (this
 * engine is stateless, so "already celebrated" isn't tracked either;
 * accepted as the cost of shipping stateless-only this session).
 */
function goalMetAlert(int $goalId, string $goalLabel, ?bool $isMet): ?array
{
    if ($isMet !== true) {
        return null;
    }
    return [
        'type'            => 'goal_met',
        'severity'        => 'positive',
        'subject'         => ['goal_id' => $goalId, 'goal_label' => $goalLabel],
        'factual_message' => "\"{$goalLabel}\" has reached its target.",
        'deep_link'       => "/goals/{$goalId}",
    ];
}

/**
 * A goal drifted BEHIND its own expected curve, beyond PlanMath::progressStatus()'s
 * existing ±5% dead zone — reuses that exact classification, never a second
 * threshold. Only 'behind' fires an alert; 'ahead' is good news already
 * visible on the goal's own progress chart, not something needing attention,
 * and 'on_track' is the default, unremarkable state.
 */
function goalDriftAlert(int $goalId, string $goalLabel, ?array $progressStatus): ?array
{
    if ($progressStatus === null || $progressStatus['status'] !== 'behind') {
        return null;
    }
    $pct = abs((float) $progressStatus['drift_pct']);
    return [
        'type'            => 'goal_drift',
        'severity'        => 'attention',
        'subject'         => ['goal_id' => $goalId, 'goal_label' => $goalLabel],
        'factual_message' => "\"{$goalLabel}\" is {$pct}% behind what the plan expected by now.",
        'deep_link'       => "/goals/{$goalId}",
    ];
}

/**
 * A NAV-tracked holding with no cached price at all ("price pending" —
 * amfi_scheme_code/units_held set but mf_nav_cache has never priced it) or
 * whose cached price hasn't refreshed in ALERTS_STALE_PRICE_DAYS. Reuses the
 * NAV cron's own freshness signal (nav_fetched_at) — no new staleness
 * concept invented.
 */
function stalePriceAlert(int $itemId, string $description, ?string $navFetchedAt, ?string $asOfDate = null, int $staleDays = ALERTS_STALE_PRICE_DAYS): ?array
{
    $subject = ['portfolio_item_id' => $itemId, 'description' => $description];

    if ($navFetchedAt === null) {
        return [
            'type'            => 'price_stale',
            'severity'        => 'attention',
            'subject'         => $subject,
            'factual_message' => "\"{$description}\" has no priced value yet — price pending the next sync.",
            'deep_link'       => '/goals',
        ];
    }

    try {
        $asOf = $asOfDate !== null ? new DateTime($asOfDate) : new DateTime();
        $fetched = new DateTime($navFetchedAt);
    } catch (Exception $e) {
        return null; // unparseable timestamp — nothing honest to alert on
    }
    $daysSince = ($asOf->getTimestamp() - $fetched->getTimestamp()) / 86400.0;
    if ($daysSince <= $staleDays) {
        return null;
    }
    $wholeDays = (int) floor($daysSince);
    return [
        'type'            => 'price_stale',
        'severity'        => 'attention',
        'subject'         => $subject,
        'factual_message' => "\"{$description}\"'s price is {$wholeDays} days old.",
        'deep_link'       => '/goals',
    ];
}

/**
 * A client's scheduled plan-review email is due — reuses
 * PlanReviewMailer.php's own cadence→interval mapping (planReviewIntervalSpec())
 * rather than a second definition of "due" that could drift from what the
 * cron actually sends. Pure: takes the schedule row's own fields plus an
 * as-of date, no DateTimeImmutable/"now" ambiguity leaking in from the caller.
 */
function reviewDueAlert(int $clientId, string $clientLabel, string $cadence, ?string $lastSentAt, ?string $asOfDate = null, ?string $possessive = null): ?array
{
    // See alertPossessive(): "Priya Raman's" for an adviser reading about a
    // client, "Your" for someone reading about themselves.
    $possessive = $possessive ?? "{$clientLabel}'s";
    $spec = planReviewIntervalSpec($cadence);
    if ($spec === null) {
        return null; // 'off', or an unrecognised cadence — nothing due
    }

    try {
        $asOf = $asOfDate !== null ? new DateTimeImmutable($asOfDate) : new DateTimeImmutable();
    } catch (Exception $e) {
        return null;
    }

    if ($lastSentAt === null) {
        $due = true; // never sent → due now, same rule as dueReviewClients()
    } else {
        try {
            $nextDue = (new DateTimeImmutable($lastSentAt))->add(new DateInterval($spec));
        } catch (Exception $e) {
            return null;
        }
        $due = $asOf >= $nextDue;
    }

    if (!$due) {
        return null;
    }
    return [
        'type'            => 'review_due',
        'severity'        => 'attention',
        'subject'         => ['client_id' => $clientId, 'client_label' => $clientLabel],
        'factual_message' => "{$possessive} {$cadence} plan review is due.",
        'deep_link'       => '/goals',
    ];
}

/**
 * How an alert refers to the person it is about.
 *
 * The engine is shared by two audiences (docs/12 principle 6) and they need
 * different grammar for the same fact. An adviser reading their book needs to
 * know WHICH client — "Priya Raman's emergency reserve has not been recorded
 * yet". A self-serve individual is reading about themselves, and third person
 * there is not a style nit: it reads as though the app is discussing them with
 * somebody else, and it lands the same way in the monthly digest EMAIL, which
 * embeds these messages verbatim (PersonalDigestMailer::composeDigestBody).
 *
 * Returning a POSSESSIVE rather than a name is what makes both forms work from
 * one template — "Your" cannot be produced by appending "'s" to a label, so a
 * label alone could never express the second-person case.
 *
 * Note this is purely grammatical. It changes no fact, no severity, and no
 * threshold — the same alert fires either way.
 */
function alertPossessive(string $clientLabel, bool $isSelf): string
{
    return $isSelf ? 'Your' : "{$clientLabel}'s";
}

/**
 * One alert per FinancialFoundations check that isn't a clean pass —
 * 'short'/'partial' (a real gap) or 'not_recorded' (an open question, never
 * silently treated as fine — the whole design point of that module).
 * 'not_applicable' (e.g. zero dependants → no life-cover gap) never alerts,
 * by definition.
 *
 * @param array{checks:list<array<string,mixed>>,unmet:int,open:int,ok:int} $foundationsSummary
 * @return list<array<string,mixed>>
 */
function foundationsGapAlerts(int $clientId, string $clientLabel, array $foundationsSummary, ?string $possessive = null): array
{
    $possessive = $possessive ?? "{$clientLabel}'s";

    $labels = [
        'emergency_reserve' => 'emergency reserve',
        'life_cover'        => 'life cover',
        'health_cover'      => 'health cover',
        'costly_debt'       => 'debt cost check',
    ];

    $alerts = [];
    foreach ($foundationsSummary['checks'] as $check) {
        $status = $check['status'];
        if (!in_array($status, ['short', 'partial', 'not_recorded'], true)) {
            continue;
        }
        $label = $labels[$check['id']] ?? $check['id'];
        $message = $status === 'not_recorded'
            ? "{$possessive} {$label} has not been recorded yet."
            : "{$possessive} {$label} falls short of the reference point.";
        $alerts[] = [
            'type'            => 'foundations_gap',
            'severity'        => 'attention',
            'subject'         => ['client_id' => $clientId, 'client_label' => $clientLabel, 'check_id' => $check['id']],
            'factual_message' => $message,
            'deep_link'       => '/goals',
        ];
    }
    return $alerts;
}

/**
 * The single engine both audiences read through (docs/12 principle 6): given
 * one client's already-gathered bundle, return every alert that applies.
 * Order: positive news first (goal_met), then attention items.
 *
 * @param array{
 *   client_id: int,
 *   client_label: string,
 *   goals: list<array{id:int,goal_label:string,is_met:?bool,progress_status:?array}>,
 *   nav_tracked_items: list<array{id:int,description:string,nav_fetched_at:?string}>,
 *   review_schedule: ?array{cadence:string,last_sent_at:?string},
 *   foundations: ?array
 * } $bundle
 * @return list<array<string,mixed>>
 */
function computeClientAlerts(array $bundle, ?string $asOfDate = null): array
{
    $alerts = [];

    foreach ($bundle['goals'] as $goal) {
        $metAlert = goalMetAlert((int) $goal['id'], (string) $goal['goal_label'], $goal['is_met'] ?? null);
        if ($metAlert !== null) {
            $alerts[] = $metAlert;
        }
    }
    foreach ($bundle['goals'] as $goal) {
        $driftAlert = goalDriftAlert((int) $goal['id'], (string) $goal['goal_label'], $goal['progress_status'] ?? null);
        if ($driftAlert !== null) {
            $alerts[] = $driftAlert;
        }
    }
    foreach ($bundle['nav_tracked_items'] as $item) {
        $priceAlert = stalePriceAlert((int) $item['id'], (string) $item['description'], $item['nav_fetched_at'] ?? null, $asOfDate);
        if ($priceAlert !== null) {
            $alerts[] = $priceAlert;
        }
    }
    // Second person when the reader IS the subject (a self-serve individual),
    // third person for an adviser reading their book. See alertPossessive().
    // Defaults to false so any caller that hasn't opted in keeps the existing
    // advisor-facing wording rather than silently addressing a stranger as
    // "you".
    $possessive = alertPossessive((string) $bundle['client_label'], (bool) ($bundle['is_self'] ?? false));

    if ($bundle['review_schedule'] !== null) {
        $reviewAlert = reviewDueAlert(
            (int) $bundle['client_id'],
            (string) $bundle['client_label'],
            (string) $bundle['review_schedule']['cadence'],
            $bundle['review_schedule']['last_sent_at'] ?? null,
            $asOfDate,
            $possessive
        );
        if ($reviewAlert !== null) {
            $alerts[] = $reviewAlert;
        }
    }
    if ($bundle['foundations'] !== null) {
        foreach (foundationsGapAlerts((int) $bundle['client_id'], (string) $bundle['client_label'], $bundle['foundations'], $possessive) as $gapAlert) {
            $alerts[] = $gapAlert;
        }
    }

    return $alerts;
}
