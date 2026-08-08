<?php
declare(strict_types=1);

/**
 * Cash-flow module (docs/10): a client's income (inflows) + expenses (outflows)
 * summed into one monthly surplus figure, and — for the advisor — that surplus
 * measured against the total monthly SIP the client's goals assume ("is the
 * plan actually fundable from cash flow?").
 *
 * The core (summarizeCashFlowItems + cashFlowItemMonthly) is pure arithmetic
 * with NO database, exactly like PlanMath.php — normalise each line to a monthly
 * number, sum income, sum expense, subtract. That makes the surplus math
 * trivially unit-testable and independent of how the rows are stored.
 *
 * The two DB-touching helpers below (totalMonthlySipForClient,
 * householdCashFlowAggregate) read through a TenantScopedDb, so the SIP total
 * and the household roll-up stay tenant-scoped and can never pull in another
 * firm's client — the same contract HouseholdProjection.php follows.
 */

/**
 * One line item's contribution as a MONTHLY figure. An annual line (bonus,
 * yearly insurance premium, school fees) is spread evenly across the year
 * (÷ 12); a monthly line is taken as-is. This is the one normalisation the
 * whole module rests on.
 */
function cashFlowItemMonthly(array $item): float
{
    $amount = (float) $item['amount'];
    return ($item['cadence'] ?? 'monthly') === 'annual' ? $amount / 12.0 : $amount;
}

/**
 * Pure summary of a list of cash-flow rows: monthly income, monthly expense,
 * and the surplus (income − expense), all as like-for-like monthly figures.
 * Inactive rows (is_active = 0) are excluded from the totals — they're parked,
 * not part of the current picture. No DB, no rounding surprises: each side is
 * rounded to paise, and surplus is income − expense of those rounded sides, so
 * the displayed numbers always reconcile exactly.
 *
 * @param array<int,array<string,mixed>> $items rows with kind, amount, cadence, is_active
 * @return array{monthly_income: float, monthly_expense: float, monthly_surplus: float}
 */
function summarizeCashFlowItems(array $items): array
{
    $income = 0.0;
    $expense = 0.0;

    foreach ($items as $item) {
        // is_active may arrive as "1"/"0" (PDO string), 1/0 (int), or true/false.
        if (!(bool) ((int) ($item['is_active'] ?? 1))) {
            continue;
        }
        $monthly = cashFlowItemMonthly($item);
        if ($item['kind'] === 'income') {
            $income += $monthly;
        } elseif ($item['kind'] === 'expense') {
            $expense += $monthly;
        }
    }

    $income = round($income, 2);
    $expense = round($expense, 2);

    return [
        'monthly_income'  => $income,
        'monthly_expense' => $expense,
        'monthly_surplus' => round($income - $expense, 2),
    ];
}

/**
 * Total CURRENT monthly SIP committed across all of a client's goals — the sum
 * of base_plans.monthly_sip_amount (the plain monthly contribution, before any
 * step-up; a step-up is a future assumption, not this month's actual outgo).
 * Read tenant-scoped, so a caller can never total another firm's client.
 */
function totalMonthlySipForClient(TenantScopedDb $scopedDb, int $clientId): float
{
    $goals = $scopedDb->select('base_plans', ['client_id' => $clientId]);
    $total = 0.0;
    foreach ($goals as $goal) {
        if ($goal['monthly_sip_amount'] !== null) {
            $total += (float) $goal['monthly_sip_amount'];
        }
    }
    return round($total, 2);
}

/**
 * The advisor-facing comparison: does the monthly surplus cover the total
 * monthly SIP the plan assumes? gap = surplus − SIPs (positive = room to spare,
 * negative = the plan out-commits the cash flow). Pure — takes the two figures,
 * returns the framing.
 *
 * @return array{total_monthly_sip: float, gap: float, funded: bool}
 */
function cashFlowSipComparison(float $monthlySurplus, float $totalMonthlySip): array
{
    return [
        'total_monthly_sip' => round($totalMonthlySip, 2),
        'gap'               => round($monthlySurplus - $totalMonthlySip, 2),
        'funded'            => ($monthlySurplus - $totalMonthlySip) >= 0,
    ];
}

/**
 * A single client's full cash-flow picture for an advisor: the pure summary
 * plus the SIP comparison. The SIP comparison is deliberately NOT part of the
 * bare summary so the client-facing read view (cash_flow_list.php for a client
 * session) can return the summary alone, without the advisor-only planning
 * framing.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array{monthly_income: float, monthly_expense: float, monthly_surplus: float, sip_comparison: array{total_monthly_sip: float, gap: float, funded: bool}}
 */
function advisorCashFlowForClient(TenantScopedDb $scopedDb, int $clientId, array $items): array
{
    $summary = summarizeCashFlowItems($items);
    $summary['sip_comparison'] = cashFlowSipComparison(
        $summary['monthly_surplus'],
        totalMonthlySipForClient($scopedDb, $clientId)
    );
    return $summary;
}

/**
 * Household roll-up: each member client's own cash-flow summary + SIP total,
 * plus the household totals (element-wise sums). The aggregate is provably the
 * sum of members' independently-recorded statements — no shared pool, the same
 * invariant HouseholdProjection.php holds for the plan projection. Returns null
 * if the household isn't in this tenant (caller 404s).
 *
 * @return array{
 *   household: array{id:int, name:string},
 *   members: list<array{client_id:int, email:string, monthly_income:float, monthly_expense:float, monthly_surplus:float, total_monthly_sip:float}>,
 *   totals: array{monthly_income:float, monthly_expense:float, monthly_surplus:float, total_monthly_sip:float, gap:float, funded:bool}
 * }|null
 */
function computeHouseholdCashFlow(TenantScopedDb $scopedDb, int $householdId): ?array
{
    $households = $scopedDb->select('households', ['id' => $householdId]);
    if ($households === []) {
        return null; // not in this tenant (or doesn't exist)
    }
    $household = $households[0];

    $members = $scopedDb->select('users', ['household_id' => $householdId, 'role' => 'client']);

    $memberOut = [];
    $totalIncome = 0.0;
    $totalExpense = 0.0;
    $totalSip = 0.0;

    foreach ($members as $member) {
        $clientId = (int) $member['id'];
        $items = $scopedDb->select('cash_flow_items', ['client_id' => $clientId]);
        $summary = summarizeCashFlowItems($items);
        $sip = totalMonthlySipForClient($scopedDb, $clientId);

        $totalIncome += $summary['monthly_income'];
        $totalExpense += $summary['monthly_expense'];
        $totalSip += $sip;

        $memberOut[] = [
            'client_id'         => $clientId,
            'email'             => $member['email'],
            // sql/035: optional, NULL for anyone predating it — callers fall
            // back to email, exactly what they rendered before.
            'display_name'      => $member['display_name'] ?? null,
            'monthly_income'    => $summary['monthly_income'],
            'monthly_expense'   => $summary['monthly_expense'],
            'monthly_surplus'   => $summary['monthly_surplus'],
            'total_monthly_sip' => $sip,
        ];
    }

    $totalIncome = round($totalIncome, 2);
    $totalExpense = round($totalExpense, 2);
    $totalSip = round($totalSip, 2);
    $totalSurplus = round($totalIncome - $totalExpense, 2);

    return [
        'household' => ['id' => (int) $household['id'], 'name' => $household['name']],
        'members'   => $memberOut,
        'totals'    => [
            'monthly_income'  => $totalIncome,
            'monthly_expense' => $totalExpense,
            'monthly_surplus' => $totalSurplus,
        ] + cashFlowSipComparison($totalSurplus, $totalSip),
    ];
}
