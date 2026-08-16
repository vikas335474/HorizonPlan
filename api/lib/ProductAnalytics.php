<?php
declare(strict_types=1);

/**
 * Platform product analytics — the missing consumer for `product_events`
 * (sql/040, docs/13 §5). super_admin-only, cross-tenant; the counterpart to
 * `firm_analytics.php` (one firm's book) except this reads the *platform*:
 * onboarding funnel, activation, retention, feature adoption, tenant growth.
 *
 * Same discipline as firm_analytics.php: everything here is a COUNT or a
 * ratio of counts already stored, never a score, grade, or target. And per
 * docs/13 §5 / ProductEvents.php's own privacy rule, this file never handles
 * a rupee figure — product_events physically cannot contain one (enforced by
 * tests/test_product_events.php), and the feature-adoption section below
 * reads only boolean "has this tenant ever written a row" facts, never an
 * amount, from the tenant-scoped tables it counts.
 *
 * Pure half only (like CashFlowSummary.php / AlertsEngine.php): every
 * function here takes already-fetched rows and returns a plain array. The
 * DB fetch (necessarily cross-tenant, hence raw PDO not TenantScopedDb — see
 * tenants_list.php / platform_settings_read.php for the same precedent) lives
 * in api/product_analytics.php.
 */

/** The onboarding funnel steps, in the order a person actually passes through
 * them. Not every step is wired up in the frontend yet (see docs/13 §5); a
 * step with no events yet just reads zero, which is the honest state, not an
 * error. */
const ONBOARDING_FUNNEL_STEPS = [
    'personal_signup_started',
    'onboarding_question_answered',
    'onboarding_risk_chosen',
    'plan_created',
];

/** Activation events — did a created plan turn into anything. */
const ACTIVATION_EVENTS = ['first_goal_opened', 'lever_previewed', 'plan_edited'];

/**
 * Build the funnel: distinct-user count per step, in order, plus each step's
 * conversion as a percentage of the first step. The first step is always
 * conversion 100 by definition, unless it has zero users, in which case
 * nothing downstream has a meaningful percentage either (returned as null,
 * never a divide-by-zero 0 that reads as "nobody converted" — the honest
 * answer is "no signal yet").
 *
 * @param array<string,int> $distinctUsersByEvent event_name => COUNT(DISTINCT user_id)
 * @return list<array{event_name:string,users:int,pct_of_first_step:?float}>
 */
function buildOnboardingFunnel(array $distinctUsersByEvent): array
{
    $first = $distinctUsersByEvent[ONBOARDING_FUNNEL_STEPS[0]] ?? 0;
    $out = [];
    foreach (ONBOARDING_FUNNEL_STEPS as $step) {
        $users = $distinctUsersByEvent[$step] ?? 0;
        $out[] = [
            'event_name'        => $step,
            'users'             => $users,
            'pct_of_first_step' => $first > 0 ? round(($users / $first) * 100, 1) : null,
        ];
    }
    return $out;
}

/**
 * Activation: of the users who ever created a plan, what share also did each
 * activation event at least once. Each row is independent (a user can open a
 * goal without ever previewing a lever), so these do NOT sum to 100 and are
 * not a second funnel — docs/13 §5 question 3 asks about lever_previewed
 * specifically, not a strict sequence.
 *
 * @param int $plannedUsers distinct users with a plan_created event
 * @param array<string,int> $distinctUsersByEvent event_name => COUNT(DISTINCT user_id),
 *   restricted by the caller's query to users who also have plan_created
 * @return list<array{event_name:string,users:int,pct_of_planners:?float}>
 */
function buildActivationRates(int $plannedUsers, array $distinctUsersByEvent): array
{
    $out = [];
    foreach (ACTIVATION_EVENTS as $event) {
        $users = $distinctUsersByEvent[$event] ?? 0;
        $out[] = [
            'event_name'      => $event,
            'users'           => $users,
            'pct_of_planners' => $plannedUsers > 0 ? round(($users / $plannedUsers) * 100, 1) : null,
        ];
    }
    return $out;
}

/**
 * Retention (docs/13 §5 question 5): of everyone who ever logged an event, what
 * share were seen on more than one distinct calendar day. A single-session
 * user has first_seen === last_seen; a returning one doesn't. Deliberately
 * not "came back within N days" — with five day-one events actually wired
 * up (see ProductAnalytics.php header), a coarse "did they ever come back at
 * all" is the honest-strength claim; a manufactured N-day window would imply
 * more precision than the data supports.
 *
 * @param array<int,array{first_seen:string,last_seen:string}> $spanByUser
 *   user_id => the earliest/latest occurred_at date string ('Y-m-d') for that user
 * @return array{total_users:int,returning_users:int,pct_returning:?float}
 */
function returningUserStats(array $spanByUser): array
{
    $total = count($spanByUser);
    $returning = 0;
    foreach ($spanByUser as $span) {
        if ($span['first_seen'] !== $span['last_seen']) {
            $returning++;
        }
    }
    return [
        'total_users'     => $total,
        'returning_users' => $returning,
        'pct_returning'   => $total > 0 ? round(($returning / $total) * 100, 1) : null,
    ];
}

/**
 * ISO week label ('2026-W07') for a MySQL datetime string, used to bucket
 * weekly-active-user counts. Pure string/date math, no DB — a stable label a
 * SQL GROUP BY can't produce portably across MySQL versions.
 */
function isoWeekBucket(string $mysqlDatetime): string
{
    $dt = new DateTimeImmutable($mysqlDatetime);
    return $dt->format('o') . '-W' . $dt->format('W');
}

/**
 * Feature adoption by tenant kind: for each of the tenant-scoped tables that
 * represent a core write action, what share of tenants of each kind (firm /
 * personal) have ever written at least one row. Counts of tenants, never of
 * rupee amounts — "has this tenant used this feature", not "how much".
 *
 * @param array<string,int> $tenantsByKind kind => total tenant count of that kind
 * @param array<string,array<string,int>> $adoptedByFeatureAndKind
 *   feature => (kind => distinct tenant count with >=1 row)
 * @return array<string,array{firm:array{tenants:int,pct:?float},personal:array{tenants:int,pct:?float}}>
 */
function buildFeatureAdoption(array $tenantsByKind, array $adoptedByFeatureAndKind): array
{
    $out = [];
    foreach ($adoptedByFeatureAndKind as $feature => $byKind) {
        $row = [];
        foreach (['firm', 'personal'] as $kind) {
            $total = $tenantsByKind[$kind] ?? 0;
            $adopted = $byKind[$kind] ?? 0;
            $row[$kind] = [
                'tenants' => $adopted,
                'pct'     => $total > 0 ? round(($adopted / $total) * 100, 1) : null,
            ];
        }
        $out[$feature] = $row;
    }
    return $out;
}
