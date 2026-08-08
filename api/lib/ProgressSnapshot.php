<?php
declare(strict_types=1);

/**
 * P1-1 · Goal-progress tracking over time (docs/10) — the capture side.
 *
 * A snapshot answers, for one date: what the goal's corpus actually was, what
 * the plan expected it to be, and (for target-based goals) what share of the
 * inflated target that covered. Reading and charting live elsewhere; this file
 * only writes.
 *
 * Split the same way MfNavSync.php is, and for the same reason:
 *
 *   captureClientProgress()  — TenantScopedDb, in-request. Backs the advisor's
 *                              manual "Record now" for ONE client.
 *   captureAllProgress()     — raw SQL across every tenant, CLI/cron only. A
 *                              cron has no session and therefore no tenant to
 *                              scope to; it is trusted global by construction,
 *                              exactly like syncMfNavCache().
 *
 * THE PLAN ANCHOR. "What did the plan expect on this date" needs a t=0. That
 * anchor is base_plans.created_at — the moment the plan started being a plan.
 * Elapsed time is measured from there to the snapshot date, and the expected
 * value is read off the goal's own projected series at that point (see
 * PlanMath::expectedCorpusAfter). It is the only date on the row that means
 * "when this plan began"; using "now" would make every goal permanently
 * on-track, and there is no separate plan_start_date column to prefer.
 *
 * IDEMPOTENT BY DAY. Both paths upsert on the (goal_id, as_of_date) /
 * (client_id, as_of_date) unique keys from migration 032, so the monthly cron
 * running twice, or a cron and a manual capture landing on the same day,
 * converge on one row per day instead of stacking duplicates.
 *
 * NOTHING IS INVENTED. A goal that cannot be projected records a NULL
 * expected_value rather than a guessed one; a client with no portfolio rows
 * records a zero net worth (which is a true reading of an empty ledger), and
 * no history is ever backfilled for dates nobody observed.
 */

require_once __DIR__ . '/PlanMath.php';
require_once __DIR__ . '/TenantScopedDb.php';

/**
 * Fractional years between two dates, floored at 0. Shared by both capture
 * paths so the plan anchor is measured identically everywhere.
 */
function progressYearsElapsed(string $planCreatedAt, string $asOfDate): float
{
    $start = strtotime($planCreatedAt);
    $end   = strtotime($asOfDate);
    if ($start === false || $end === false || $end <= $start) {
        return 0.0;
    }
    return ($end - $start) / (365.25 * 24 * 60 * 60);
}

/**
 * Derive the snapshot values for ONE goal row. Pure apart from its inputs —
 * no DB access — so both capture paths and the tests share one definition of
 * what a snapshot means.
 *
 * @param array<string,mixed> $goal a base_plans row
 * @return array{corpus_value: float, expected_value: ?float, funding_covered_pct: ?float}
 */
function progressValuesForGoal(array $goal, string $asOfDate): array
{
    $corpus = (float) $goal['initial_net_worth'];

    $expected = PlanMath::expectedCorpusAfter(
        progressYearsElapsed((string) $goal['created_at'], $asOfDate),
        $corpus,
        $goal['withdrawal_rate'] !== null ? (float) $goal['withdrawal_rate'] : null,
        (float) $goal['inflation_rate'],
        $goal['drawdown_return_rate'] !== null ? (float) $goal['drawdown_return_rate'] : null,
        (int) $goal['projection_horizon_years'],
        $goal['current_age'] !== null ? (int) $goal['current_age'] : null,
        $goal['retirement_age'] !== null ? (int) $goal['retirement_age'] : null,
        $goal['accumulation_return_rate'] !== null ? (float) $goal['accumulation_return_rate'] : null,
        $goal['monthly_sip_amount'] !== null ? (float) $goal['monthly_sip_amount'] : null,
        $goal['sip_step_up_rate'] !== null ? (float) $goal['sip_step_up_rate'] : null
    );

    // Target-based goals have no expected curve; their progress is the
    // coverage trend instead. The two are mutually exclusive by construction
    // (a goal with a target carries no withdrawal/drawdown rate).
    $funding = PlanMath::targetGoalFunding(
        $goal['target_amount'] !== null ? (float) $goal['target_amount'] : null,
        $goal['target_date'],
        $corpus,
        (float) $goal['inflation_rate'],
        $asOfDate
    );

    return [
        'corpus_value'        => $corpus,
        'expected_value'      => $expected,
        'funding_covered_pct' => $funding !== null ? (float) $funding['covered_pct'] : null,
    ];
}

/**
 * Net worth from a client's portfolio ledger rows — the same
 * assets/liabilities split client_portfolio_list.php computes on read, kept
 * in one place so the snapshot can never disagree with the card the advisor
 * is looking at.
 *
 * @param array<int,array<string,mixed>> $items client_portfolio_items rows
 * @return array{net_worth: float, assets_total: float, liabilities_total: float}
 */
function progressNetWorthFromItems(array $items): array
{
    $assets = 0.0;
    $liabilities = 0.0;
    foreach ($items as $r) {
        $value = (float) $r['value'];
        if ($r['item_kind'] === 'liability') {
            $liabilities += $value;
        } else {
            $assets += $value;
        }
    }
    return [
        'net_worth'         => round($assets - $liabilities, 2),
        'assets_total'      => round($assets, 2),
        'liabilities_total' => round($liabilities, 2),
    ];
}

/**
 * Upsert one goal snapshot. Raw prepared statement rather than
 * TenantScopedDb::insert() because this needs INSERT ... ON DUPLICATE KEY
 * UPDATE (the day-idempotency the helper has no vocabulary for); tenant_id is
 * still written explicitly on every row and every caller has already
 * established which tenant the goal belongs to.
 */
function upsertGoalSnapshot(PDO $db, int $tenantId, int $goalId, int $clientId, string $asOfDate, array $values, ?int $userId): void
{
    $stmt = $db->prepare(
        "INSERT INTO goal_snapshots
            (tenant_id, goal_id, client_id, as_of_date, corpus_value, expected_value, funding_covered_pct, created_by_user_id)
         VALUES (:tenant_id, :goal_id, :client_id, :as_of, :corpus, :expected, :funding, :uid)
         ON DUPLICATE KEY UPDATE
            corpus_value = VALUES(corpus_value),
            expected_value = VALUES(expected_value),
            funding_covered_pct = VALUES(funding_covered_pct),
            created_by_user_id = VALUES(created_by_user_id)"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':goal_id'   => $goalId,
        ':client_id' => $clientId,
        ':as_of'     => $asOfDate,
        ':corpus'    => $values['corpus_value'],
        ':expected'  => $values['expected_value'],
        ':funding'   => $values['funding_covered_pct'],
        ':uid'       => $userId,
    ]);
}

/** Upsert one client net-worth snapshot. Same day-idempotency reasoning. */
function upsertNetWorthSnapshot(PDO $db, int $tenantId, int $clientId, string $asOfDate, array $nw, ?int $userId): void
{
    $stmt = $db->prepare(
        "INSERT INTO client_net_worth_snapshots
            (tenant_id, client_id, as_of_date, net_worth, assets_total, liabilities_total, created_by_user_id)
         VALUES (:tenant_id, :client_id, :as_of, :nw, :assets, :liabilities, :uid)
         ON DUPLICATE KEY UPDATE
            net_worth = VALUES(net_worth),
            assets_total = VALUES(assets_total),
            liabilities_total = VALUES(liabilities_total),
            created_by_user_id = VALUES(created_by_user_id)"
    );
    $stmt->execute([
        ':tenant_id'   => $tenantId,
        ':client_id'   => $clientId,
        ':as_of'       => $asOfDate,
        ':nw'          => $nw['net_worth'],
        ':assets'      => $nw['assets_total'],
        ':liabilities' => $nw['liabilities_total'],
        ':uid'         => $userId,
    ]);
}

/**
 * Capture every goal of ONE client plus that client's net worth, in-request.
 * Tenant-scoped throughout — the caller's TenantScopedDb is what guarantees a
 * cross-tenant client_id simply finds nothing to snapshot.
 *
 * @param int|null $userId the acting advisor (NULL only for cron-style calls)
 * @param string|null $asOfDate defaults to today
 * @return array{as_of_date: string, goals_captured: int, net_worth_captured: bool}
 */
function captureClientProgress(TenantScopedDb $scopedDb, PDO $db, int $tenantId, int $clientId, ?int $userId, ?string $asOfDate = null): array
{
    $asOf = $asOfDate ?? date('Y-m-d');

    $goals = $scopedDb->select('base_plans', ['client_id' => $clientId]);
    foreach ($goals as $goal) {
        upsertGoalSnapshot(
            $db,
            $tenantId,
            (int) $goal['id'],
            $clientId,
            $asOf,
            progressValuesForGoal($goal, $asOf),
            $userId
        );
    }

    $items = $scopedDb->select('client_portfolio_items', ['client_id' => $clientId]);
    upsertNetWorthSnapshot($db, $tenantId, $clientId, $asOf, progressNetWorthFromItems($items), $userId);

    return [
        'as_of_date'         => $asOf,
        'goals_captured'     => count($goals),
        'net_worth_captured' => true,
    ];
}

/**
 * Cron path: capture every client of every tenant. CLI only.
 *
 * Deliberately raw SQL, not TenantScopedDb — a cron is a trusted global
 * process with no session and therefore no tenant to bind to, the same
 * reasoning (and the same comment) as MfNavSync::syncMfNavCache(). tenant_id
 * is still carried explicitly onto every row written.
 *
 * created_by_user_id is left NULL, which is how a reader tells a scheduled
 * reading from one a person took.
 *
 * @return array{as_of_date: string, clients: int, goals: int}
 */
function captureAllProgress(PDO $db, ?string $asOfDate = null): array
{
    $asOf = $asOfDate ?? date('Y-m-d');

    $clients = $db->query("SELECT id, tenant_id FROM users WHERE role = 'client'")->fetchAll();

    $goalStmt = $db->prepare("SELECT * FROM base_plans WHERE client_id = :cid AND tenant_id = :tid");
    $itemStmt = $db->prepare("SELECT item_kind, value FROM client_portfolio_items WHERE client_id = :cid AND tenant_id = :tid");

    $goalCount = 0;
    foreach ($clients as $c) {
        $clientId = (int) $c['id'];
        $tenantId = (int) $c['tenant_id'];

        $goalStmt->execute([':cid' => $clientId, ':tid' => $tenantId]);
        foreach ($goalStmt->fetchAll() as $goal) {
            upsertGoalSnapshot($db, $tenantId, (int) $goal['id'], $clientId, $asOf, progressValuesForGoal($goal, $asOf), null);
            $goalCount++;
        }

        $itemStmt->execute([':cid' => $clientId, ':tid' => $tenantId]);
        upsertNetWorthSnapshot($db, $tenantId, $clientId, $asOf, progressNetWorthFromItems($itemStmt->fetchAll()), null);
    }

    return [
        'as_of_date' => $asOf,
        'clients'    => count($clients),
        'goals'      => $goalCount,
    ];
}
