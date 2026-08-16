<?php
declare(strict_types=1);

// Pure tests for api/lib/ProductAnalytics.php — the aggregation layer behind
// the super_admin product-analytics dashboard (the missing consumer for
// product_events, docs/13 §5). No DB: every function here takes already-
// fetched rows, same split as CashFlowSummary.php / AlertsEngine.php.

require_once __DIR__ . '/../api/lib/ProductAnalytics.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- buildOnboardingFunnel ---------------------------------------------------
$funnel = buildOnboardingFunnel([
    'personal_signup_started'      => 100,
    'onboarding_question_answered' => 80,
    'onboarding_risk_chosen'       => 50,
    'plan_created'                 => 40,
]);
assertTrue(count($funnel) === 4, 'funnel has all four steps, in order');
assertTrue($funnel[0]['event_name'] === 'personal_signup_started', 'first step is signup');
assertTrue($funnel[3]['event_name'] === 'plan_created', 'last step is plan_created');
assertTrue($funnel[0]['pct_of_first_step'] === 100.0, 'the first step is always 100% of itself');
assertTrue($funnel[3]['pct_of_first_step'] === 40.0, '40 of 100 converts to 40%');

$emptyFunnel = buildOnboardingFunnel([]);
assertTrue($emptyFunnel[0]['users'] === 0, 'a step with no events reads zero, not an error');
assertTrue($emptyFunnel[0]['pct_of_first_step'] === null, 'zero first-step users gives null, never a misleading 0%');
assertTrue($emptyFunnel[3]['pct_of_first_step'] === null, 'downstream steps also read null when the funnel has no signal at all');

// --- buildActivationRates ----------------------------------------------------
$activation = buildActivationRates(40, ['first_goal_opened' => 30, 'plan_edited' => 10]);
$byName = [];
foreach ($activation as $row) { $byName[$row['event_name']] = $row; }
assertTrue($byName['first_goal_opened']['pct_of_planners'] === 75.0, '30 of 40 planners opened a goal: 75%');
assertTrue($byName['lever_previewed']['users'] === 0, 'an event with no rows reads zero users');
assertTrue($byName['lever_previewed']['pct_of_planners'] === 0.0, 'zero of a nonzero denominator is a real 0%, not null');
assertTrue(
    buildActivationRates(0, ['first_goal_opened' => 0])[0]['pct_of_planners'] === null,
    'zero planners gives null (no denominator), not a divide-by-zero 0%'
);

// --- returningUserStats -------------------------------------------------------
$stats = returningUserStats([
    1 => ['first_seen' => '2026-01-01', 'last_seen' => '2026-01-01'],
    2 => ['first_seen' => '2026-01-01', 'last_seen' => '2026-01-05'],
    3 => ['first_seen' => '2026-02-01', 'last_seen' => '2026-02-01'],
]);
assertTrue($stats['total_users'] === 3, 'counts every user with at least one event');
assertTrue($stats['returning_users'] === 1, 'only the user seen on two different days counts as returning');
assertTrue(abs($stats['pct_returning'] - 33.3) < 0.05, '1 of 3 is 33.3%');
assertTrue(returningUserStats([])['pct_returning'] === null, 'no users at all gives null, not 0%');

// --- isoWeekBucket ------------------------------------------------------------
assertTrue(isoWeekBucket('2026-02-16 10:00:00') === '2026-W08', 'buckets a datetime into its ISO week');
assertTrue(
    isoWeekBucket('2026-02-16 08:00:00') === isoWeekBucket('2026-02-20 23:00:00'),
    'two dates in the same ISO week share a bucket'
);
assertTrue(
    isoWeekBucket('2026-02-16 00:00:00') !== isoWeekBucket('2026-02-23 00:00:00'),
    'adjacent weeks get different buckets'
);

// --- buildFeatureAdoption -----------------------------------------------------
$adoption = buildFeatureAdoption(
    ['firm' => 10, 'personal' => 20],
    ['goal_created' => ['firm' => 8, 'personal' => 5]]
);
assertTrue($adoption['goal_created']['firm']['pct'] === 80.0, '8 of 10 firm tenants adopted: 80%');
assertTrue($adoption['goal_created']['personal']['pct'] === 25.0, '5 of 20 personal tenants adopted: 25%');
assertTrue(
    buildFeatureAdoption(['firm' => 0, 'personal' => 0], ['goal_created' => []])['goal_created']['firm']['pct'] === null,
    'a tenant kind with zero tenants gives null, not a divide-by-zero 0%'
);

echo "All ProductAnalytics tests passed.\n";
