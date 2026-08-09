<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-4 · Extracted from foundations_read.php so the alerts
 * engine (which needs the SAME foundations verdict a client's own page
 * shows) reads through one function instead of a second, drifting copy of
 * the household/tenant-scoped gathering logic. FinancialFoundations.php
 * itself stays pure/no-DB (its own header says so); this is the DB-touching
 * half that assembles its inputs, same split as ProgressSnapshot.php's own
 * capture-vs-compute layering.
 *
 * Byte-identical to what foundations_read.php computed inline before this
 * extraction — see test_foundations_db.php for the household/tenant-
 * isolation coverage this inherits unchanged.
 *
 * @return array{
 *   protection: array{dependants_count:?int,term_life_cover:?float,health_cover:?float,recorded:bool},
 *   scope: string,
 *   member_count: int,
 *   foundations: array{checks:list<array<string,mixed>>,unmet:int,open:int,ok:int}
 * }
 */
function foundationsSummaryForClient(TenantScopedDb $scopedDb, int $clientId): array
{
    // --- scope: whose expenses and debts count? -----------------------------
    // Reserve and debt are household facts when this client is in a household
    // (sql/029 for an advisor's family, sql/035 for a self-serve couple):
    // shared expenses get recorded by whichever member entered them, so a
    // per-person reserve would report one partner as badly under-reserved and
    // the other as fine, with neither figure true. Cover stays per-person
    // throughout — see FinancialFoundations::summary().
    $clientRows = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
    $householdId = ($clientRows !== [] && $clientRows[0]['household_id'] !== null)
        ? (int) $clientRows[0]['household_id']
        : 0;

    $sharedClientIds = [$clientId];
    $sharedScope = 'person';
    if ($householdId > 0) {
        $members = $scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']);
        $ids = array_map(static fn(array $m): int => (int) $m['id'], $members);
        // A household of one is not a household for labelling purposes.
        if (count($ids) > 1) {
            $sharedClientIds = $ids;
            $sharedScope = 'household';
        }
    }

    // --- the figures this module does not own --------------------------------
    $ownCashFlow = summarizeCashFlowItems($scopedDb->select('cash_flow_items', ['client_id' => $clientId]));

    $sharedExpenses = 0.0;
    $portfolio = [];
    foreach ($sharedClientIds as $sharedId) {
        $sharedExpenses += (float) summarizeCashFlowItems(
            $scopedDb->select('cash_flow_items', ['client_id' => $sharedId])
        )['monthly_expense'];
        foreach ($scopedDb->select('client_portfolio_items', ['client_id' => $sharedId]) as $row) {
            $portfolio[] = $row;
        }
    }

    $liquidAssets = 0.0;
    $liabilities = [];
    foreach ($portfolio as $row) {
        if ($row['item_kind'] === 'asset') {
            if ($row['bucket'] === 'liquid') {
                $liquidAssets += (float) $row['value'];
            }
            continue;
        }
        $liabilities[] = [
            'label'         => (string) ($row['description'] ?? '') !== '' ? (string) $row['description'] : (string) $row['category'],
            'value'         => (float) $row['value'],
            'interest_rate' => $row['interest_rate'],
        ];
    }

    // The return assumption to price debt against: the HIGHEST accumulation
    // rate across this client's goals (the conservative choice — flags the
    // fewest loans). NULL when no goal carries a rate.
    $assumedReturn = null;
    foreach ($sharedClientIds as $sharedId) {
        foreach ($scopedDb->select('base_plans', ['client_id' => $sharedId]) as $plan) {
            $rate = $plan['accumulation_return_rate'] ?? null;
            if ($rate !== null && is_numeric($rate)) {
                $assumedReturn = $assumedReturn === null ? (float) $rate : max($assumedReturn, (float) $rate);
            }
        }
    }

    // --- the little this module does own --------------------------------------
    $protectionRows = $scopedDb->select('client_protection', ['client_id' => $clientId]);
    $protection = $protectionRows[0] ?? null;

    $dependants = ($protection && $protection['dependants_count'] !== null)
        ? (int) $protection['dependants_count']
        : null;
    $termCover = ($protection && $protection['term_life_cover'] !== null)
        ? (float) $protection['term_life_cover']
        : null;
    $healthCover = ($protection && $protection['health_cover'] !== null)
        ? (float) $protection['health_cover']
        : null;

    $foundations = FinancialFoundations::summary(
        $sharedExpenses,
        $liquidAssets,
        (float) $ownCashFlow['monthly_income'] * 12.0,
        $dependants,
        $termCover,
        $healthCover,
        $liabilities,
        $assumedReturn,
        $portfolio !== [],
        $sharedScope
    );

    return [
        'protection' => [
            'dependants_count' => $dependants,
            'term_life_cover'  => $termCover,
            'health_cover'     => $healthCover,
            'recorded'         => $protection !== null,
        ],
        'scope'        => $sharedScope,
        'member_count' => count($sharedClientIds),
        'foundations'  => $foundations,
    ];
}
