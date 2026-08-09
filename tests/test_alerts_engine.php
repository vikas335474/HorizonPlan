<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-4 — the alerts/rules engine. Pure, no DB — every trigger
 * type checked in isolation, then computeClientAlerts() checked as the
 * composed whole.
 */

require_once __DIR__ . '/../api/lib/AlertsEngine.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- goalMetAlert() ---------------------------------------------------------

assertTrue(goalMetAlert(1, 'College fund', null) === null, 'a null is_met (goal not projectable) never alerts');
assertTrue(goalMetAlert(1, 'College fund', false) === null, 'is_met=false never alerts');

$met = goalMetAlert(402, "Daughter's college", true);
assertTrue($met !== null, 'is_met=true fires an alert');
assertTrue($met['type'] === 'goal_met' && $met['severity'] === 'positive', 'goal_met is a positive-severity alert');
assertTrue(str_contains($met['factual_message'], "Daughter's college") && str_contains($met['factual_message'], 'reached its target'), 'the message states the fact, not an instruction');
assertTrue($met['deep_link'] === '/goals/402', 'the deep link points at the goal itself');
assertTrue(!str_contains(strtolower($met['factual_message']), 'sell') && !str_contains(strtolower($met['factual_message']), 'should'), 'never phrases the fact as an instruction');

// --- goalDriftAlert() --------------------------------------------------------

assertTrue(goalDriftAlert(1, 'Retirement', null) === null, 'no progress status (nothing to compare against) never alerts');
assertTrue(goalDriftAlert(1, 'Retirement', ['status' => 'on_track', 'drift' => 0.0, 'drift_pct' => 2.0]) === null, 'on_track never alerts');
assertTrue(goalDriftAlert(1, 'Retirement', ['status' => 'ahead', 'drift' => 500000.0, 'drift_pct' => 12.0]) === null, 'ahead is good news, not an attention alert — visible on the goal\'s own chart instead');

$drift = goalDriftAlert(401, 'Retiring at 60', ['status' => 'behind', 'drift' => -300000.0, 'drift_pct' => -8.5]);
assertTrue($drift !== null, 'behind fires an alert');
assertTrue($drift['type'] === 'goal_drift' && $drift['severity'] === 'attention', 'goal_drift is an attention-severity alert');
assertTrue(str_contains($drift['factual_message'], '8.5%') && str_contains($drift['factual_message'], 'behind'), 'the message states the drift percentage as a fact — reuses PlanMath::progressStatus\'s own ±5% dead zone, no second threshold');

// --- stalePriceAlert() -------------------------------------------------------

$pending = stalePriceAlert(1, 'HDFC Flexi Cap Fund', null, '2026-08-09');
assertTrue($pending !== null && str_contains($pending['factual_message'], 'price pending'), 'no cached price at all -> "price pending"');

$fresh = stalePriceAlert(1, 'HDFC Flexi Cap Fund', '2026-08-08', '2026-08-09');
assertTrue($fresh === null, 'a price fetched yesterday is not stale — inside ALERTS_STALE_PRICE_DAYS');

$boundary = stalePriceAlert(1, 'HDFC Flexi Cap Fund', '2026-08-06', '2026-08-09'); // exactly 3 days
assertTrue($boundary === null, 'exactly at the stale-days threshold is NOT yet flagged — only strictly beyond it');

$stale = stalePriceAlert(1, 'HDFC Flexi Cap Fund', '2026-08-01', '2026-08-09'); // 8 days
assertTrue($stale !== null && $stale['type'] === 'price_stale' && str_contains($stale['factual_message'], '8 days old'), 'a price 8 days old is flagged stale, with the actual age stated as a fact');

assertTrue(stalePriceAlert(1, 'x', 'not-a-real-date', '2026-08-09') === null, 'an unparseable timestamp never crashes, never fabricates an alert');

// --- reviewDueAlert() ---------------------------------------------------------

assertTrue(reviewDueAlert(1, 'Asha Rao', 'off', null, '2026-08-09') === null, 'cadence=off never alerts');

$neverSent = reviewDueAlert(1, 'Asha Rao', 'quarterly', null, '2026-08-09');
assertTrue($neverSent !== null && $neverSent['type'] === 'review_due', 'never sent -> due now');

$justSent = reviewDueAlert(1, 'Asha Rao', 'quarterly', '2026-08-01', '2026-08-09');
assertTrue($justSent === null, 'sent a week ago, quarterly cadence -> not due yet');

$overdueQuarterly = reviewDueAlert(1, 'Asha Rao', 'quarterly', '2026-01-01', '2026-08-09');
assertTrue($overdueQuarterly !== null, 'sent over 3 months ago, quarterly cadence -> due');

$overdueAnnual = reviewDueAlert(1, 'Asha Rao', 'annually', '2025-01-01', '2026-08-09');
assertTrue($overdueAnnual !== null, 'sent over a year ago, annual cadence -> due');
$notYetAnnual = reviewDueAlert(1, 'Asha Rao', 'annually', '2026-01-01', '2026-08-09');
assertTrue($notYetAnnual === null, 'sent 7 months ago, annual cadence -> not due yet');

// --- foundationsGapAlerts() ---------------------------------------------------

$allOk = foundationsGapAlerts(1, 'Asha Rao', [
    'checks' => [
        ['id' => 'emergency_reserve', 'status' => 'ok'],
        ['id' => 'life_cover', 'status' => 'not_applicable'],
        ['id' => 'health_cover', 'status' => 'ok'],
        ['id' => 'costly_debt', 'status' => 'ok'],
    ],
    'unmet' => 0, 'open' => 0, 'ok' => 3,
]);
assertTrue($allOk === [], 'a fully-clean foundations summary (including not_applicable) produces zero alerts');

$mixed = foundationsGapAlerts(1, 'Asha Rao', [
    'checks' => [
        ['id' => 'emergency_reserve', 'status' => 'short'],
        ['id' => 'life_cover', 'status' => 'not_recorded'],
        ['id' => 'health_cover', 'status' => 'partial'],
        ['id' => 'costly_debt', 'status' => 'ok'],
    ],
    'unmet' => 2, 'open' => 1, 'ok' => 1,
]);
assertTrue(count($mixed) === 3, 'short + not_recorded + partial each produce exactly one alert; ok produces none');
$types = array_column($mixed, 'type');
assertTrue(count(array_unique($types)) === 1 && $types[0] === 'foundations_gap', 'every foundations alert carries the same type');
$reserveAlert = array_values(array_filter($mixed, static fn($a) => $a['subject']['check_id'] === 'emergency_reserve'))[0];
assertTrue(str_contains($reserveAlert['factual_message'], 'falls short'), 'a real shortfall (short/partial) is worded as a gap');
$lifeCoverAlert = array_values(array_filter($mixed, static fn($a) => $a['subject']['check_id'] === 'life_cover'))[0];
assertTrue(str_contains($lifeCoverAlert['factual_message'], 'not been recorded'), 'not_recorded is worded as an open question, never a flattering pass');

// --- computeClientAlerts(): the composed whole -------------------------------

$bundle = [
    'client_id' => 211,
    'client_label' => 'Priya Iyer',
    'goals' => [
        ['id' => 402, 'goal_label' => "Daughter's college", 'is_met' => true, 'progress_status' => null],
        ['id' => 401, 'goal_label' => 'Retiring at 60', 'is_met' => false, 'progress_status' => ['status' => 'behind', 'drift' => -100.0, 'drift_pct' => -7.0]],
    ],
    'nav_tracked_items' => [
        ['id' => 5, 'description' => 'Some Fund', 'nav_fetched_at' => null],
    ],
    'review_schedule' => ['cadence' => 'quarterly', 'last_sent_at' => null],
    'foundations' => [
        'checks' => [['id' => 'emergency_reserve', 'status' => 'short']],
        'unmet' => 1, 'open' => 0, 'ok' => 0,
    ],
];

$all = computeClientAlerts($bundle, '2026-08-09');
assertTrue(count($all) === 5, 'one alert per trigger that actually fired: goal_met, goal_drift, price_stale, review_due, foundations_gap');
assertTrue($all[0]['type'] === 'goal_met', 'positive news (goal_met) sorts first');

$emptyBundle = [
    'client_id' => 1, 'client_label' => 'Nobody',
    'goals' => [], 'nav_tracked_items' => [], 'review_schedule' => null, 'foundations' => null,
];
assertTrue(computeClientAlerts($emptyBundle) === [], 'an empty bundle produces zero alerts, never a fabricated one');

// --- No alert here ever phrases a fact as an instruction ---------------------
$bannedWords = ['should', 'sell', 'buy', 'harvest', 'withdraw now', 'consider selling'];
foreach ($all as $a) {
    foreach ($bannedWords as $w) {
        assertTrue(!str_contains(strtolower($a['factual_message']), $w), "no alert message ever contains the word \"$w\" (type: {$a['type']})");
    }
}

echo "\nAll alerts-engine tests passed.\n";
