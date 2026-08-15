<?php
declare(strict_types=1);

/**
 * docs/13 I-5 · Product analytics — the validation layer in front of
 * `product_events` (sql/040).
 *
 * The pure half of this file (everything except recordProductEvent) takes no
 * PDO and can be unit-tested with no database, the same split as
 * PortfolioTaxContext.php / AlertsEngine.php.
 *
 * *** THE PRIVACY RULE THIS FILE ENFORCES ***
 * Record what a person DID, never what they ARE, and never a figure. See
 * sql/040's header for why this matters more than a usual privacy footnote:
 * every financial value already lives in a tenant-scoped table, and an event
 * log full of financial detail is precisely the asset that turns a planning
 * product into a selling one.
 *
 * Enforcement is deliberately mechanical rather than cultural:
 *   - EVENT_NAMES is a closed set. An unknown name is rejected, not recorded,
 *     because a typo'd name is a silently missing funnel step.
 *   - CONTEXT_KEYS is a closed set. An unknown key is rejected.
 *   - sanitiseContext() additionally rejects any value that looks like a
 *     financial figure, so even a whitelisted key cannot smuggle one in.
 *
 * If you are adding an event and find yourself wanting to attach an amount:
 * don't. Record that the thing happened, and get the amount from the real
 * table under tenant scope when you actually need it.
 */

require_once __DIR__ . '/TenantScopedDb.php';

/**
 * Every event this product records. Closed by design — see the file header.
 * The ten below are docs/13 §5's day-one set; adding an eleventh is a
 * deliberate act, which is the point.
 */
const EVENT_NAMES = [
    // Onboarding funnel — where people drop out (docs/13 §5 question 1)
    'personal_signup_started',
    'onboarding_question_answered',
    'onboarding_risk_chosen',
    'onboarding_abandoned',
    'plan_created',
    // Activation — does a plan become a habit (questions 2 and 3)
    'first_goal_opened',
    'lever_previewed',
    'plan_edited',
    // Depth and growth (questions 4 and 5)
    'refinement_answered',
    'partner_invited',
];

/**
 * The only keys allowed inside event_context. All of them describe a position
 * in the product, never a fact about the person's money.
 *
 *   step        — which wizard step (integer index)
 *   screen      — which screen the event happened on (short slug)
 *   lever       — 'sip' | 'deferral', which gap-closing lever was previewed
 *   question_id — which refinement question was answered (id, not answer)
 *   outcome     — a small enum slug, e.g. 'skipped' | 'answered'
 */
const CONTEXT_KEYS = ['step', 'screen', 'lever', 'question_id', 'outcome'];

/** Longest a context slug may be. Keeps this a label, not a payload. */
const CONTEXT_VALUE_MAX_LENGTH = 40;

/**
 * Is this a known event name?
 */
function isKnownProductEvent(string $eventName): bool
{
    return in_array($eventName, EVENT_NAMES, true);
}

/**
 * Reduce a caller-supplied context array to the whitelisted, non-financial
 * subset that is safe to store.
 *
 * Rejects, rather than coerces:
 *   - any key not in CONTEXT_KEYS
 *   - any value that is an array or object (a payload, not a label)
 *   - any float, and any int outside a small bounded range — a step index is
 *     0..99; a rupee amount is not. This is the check that stops a figure
 *     reaching the database through an otherwise-legitimate key.
 *   - any string longer than CONTEXT_VALUE_MAX_LENGTH, or that parses as a
 *     number (so "250000" cannot arrive dressed as a slug)
 *
 * @param array<string,mixed> $context
 * @return array<string,int|string> the surviving pairs; empty array when
 *   nothing survives, which callers store as SQL NULL
 */
function sanitiseProductEventContext(array $context): array
{
    $clean = [];
    foreach ($context as $key => $value) {
        if (!is_string($key) || !in_array($key, CONTEXT_KEYS, true)) {
            continue;
        }
        if (is_int($value)) {
            // A bounded index. Anything larger is not a position in a wizard,
            // and is far more likely to be a figure someone tried to log.
            if ($value >= 0 && $value <= 99) {
                $clean[$key] = $value;
            }
            continue;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strlen($trimmed) > CONTEXT_VALUE_MAX_LENGTH) {
                continue;
            }
            // A slug that parses as a number is a figure wearing a costume.
            if (is_numeric($trimmed)) {
                continue;
            }
            $clean[$key] = $trimmed;
            continue;
        }
        // floats, bools, arrays, objects, null — never a legitimate label here
    }
    return $clean;
}

/**
 * Write one event.
 *
 * Silently does nothing for an unknown event name rather than throwing:
 * analytics must never be able to break the user-facing action that triggered
 * it. The endpoint validates and reports separately, so a genuine client bug
 * is still visible there — this is the last-resort guard, not the only one.
 *
 * @param array<string,mixed> $context see sanitiseProductEventContext()
 */
function recordProductEvent(
    TenantScopedDb $scopedDb,
    int $userId,
    string $eventName,
    array $context = []
): void {
    if (!isKnownProductEvent($eventName)) {
        return;
    }
    $clean = sanitiseProductEventContext($context);
    $scopedDb->insert('product_events', [
        'user_id'       => $userId,
        'event_name'    => $eventName,
        'event_context' => $clean === [] ? null : json_encode($clean),
    ]);
}
