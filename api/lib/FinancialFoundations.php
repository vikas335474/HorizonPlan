<?php
declare(strict_types=1);

// docs/10 P1-4 · The "financial foundations" check — pure arithmetic over
// figures that already live elsewhere in the schema, in the same no-DB style as
// PlanMath.php and CashFlowSummary.php so every rule here is unit-testable
// without a database.
//
// WHAT THIS IS. Four checks that a planning standard puts BEFORE optimising a
// long-horizon goal: an emergency reserve, income replacement for dependants,
// medical cover, and debt that costs more than the plan expects to earn.
//
// WHAT THIS IS NOT — and the distinction is the whole design:
//
//   * It is not advice. Every threshold below is a widely-cited REFERENCE
//     POINT, carried with its provenance, in exactly the framing
//     lib/strategyPresets.js already uses for withdrawal rates: here is a
//     commonly used number, here is where you actually stand, decide for
//     yourself. Nothing returns "you should buy" anything.
//   * It is not distribution. No product, insurer, or premium appears here.
//     docs/10 P1-4 scopes this as planning-side precisely so it stays clear of
//     the incumbents' insurance-selling motion.
//   * It never invents a figure. Anything unrecorded returns 'not_recorded',
//     which the UI must render as an open question — never as a passed check.
//     A blank protection statement is the most likely real-world state and it
//     must not read as "you're covered".
//
// PROVENANCE OF THE REFERENCE POINTS:
//   * 3-6 months of expenses held liquid — the standard emergency-reserve
//     range, effectively universal across planning bodies. The wider end (6)
//     is used as the "comfortable" mark and 3 as the floor.
//   * 10-15x annual income of life cover — the common Indian income-
//     replacement rule of thumb, as used in SEBI/AMFI investor-education
//     material and by every major Indian insurer's own needs calculator.
//   * Rs 5 lakh health cover as a reference floor — the most commonly cited
//     individual baseline; genuinely varies with city and family size, which
//     is why it is reported as a floor to compare against rather than a
//     target to hit. This is the softest of the four and is labelled as such.
//
// The debt check is the one place the arithmetic speaks for itself: if a loan
// charges more than the plan assumes the corpus will earn, that gap is a fact
// about two recorded numbers, not an opinion about what to do with it.

final class FinancialFoundations
{
    /** Emergency reserve, in months of total recorded expenses. */
    public const EMERGENCY_MONTHS_FLOOR = 3.0;
    public const EMERGENCY_MONTHS_COMFORTABLE = 6.0;

    /** Life cover as a multiple of annual income, where dependants exist. */
    public const LIFE_COVER_MULTIPLE_FLOOR = 10.0;
    public const LIFE_COVER_MULTIPLE_COMFORTABLE = 15.0;

    /** Health cover reference floor, in rupees. */
    public const HEALTH_COVER_FLOOR = 500000.0;

    /**
     * Months of expenses covered by liquid assets.
     *
     * The denominator is TOTAL recorded monthly expenses, not an "essential"
     * subset. cash_flow_items carries a free-text category but nothing marks a
     * line essential, and the app deciding on someone's behalf which of their
     * expenses they could drop in a crisis would be both presumptuous and
     * wrong for most people. Total expenses is the conservative denominator —
     * it reports FEWER months of cover, never more — which is the safe
     * direction for a guardrail.
     *
     * @return array{id:string,status:string,months:?float,liquid:float,monthly_expenses:float,floor:float,comfortable:float}
     */
    public static function emergencyReserve(float $monthlyExpenses, float $liquidAssets, string $scope = 'person'): array
    {
        $base = [
            'id'               => 'emergency_reserve',
            // 'person' or 'household' — see the class note on why this check is
            // aggregated for a couple while cover is not. Reported so the UI
            // can say WHOSE figures these are; a reserve silently computed at a
            // different scope than the label implies is worse than either.
            'scope'            => $scope,
            'liquid'           => max(0.0, $liquidAssets),
            'monthly_expenses' => max(0.0, $monthlyExpenses),
            'floor'            => self::EMERGENCY_MONTHS_FLOOR,
            'comfortable'      => self::EMERGENCY_MONTHS_COMFORTABLE,
        ];

        // No expenses recorded means there is no denominator. Reporting
        // "infinite months of cover" from an empty cash-flow statement would
        // be the single most flattering wrong answer this check could give.
        if ($monthlyExpenses <= 0.0) {
            return $base + ['status' => 'not_recorded', 'months' => null];
        }

        $months = max(0.0, $liquidAssets) / $monthlyExpenses;
        $status = 'short';
        if ($months >= self::EMERGENCY_MONTHS_COMFORTABLE) {
            $status = 'ok';
        } elseif ($months >= self::EMERGENCY_MONTHS_FLOOR) {
            $status = 'partial';
        }

        return $base + ['status' => $status, 'months' => $months];
    }

    /**
     * Income-replacement cover, sized only when somebody actually depends on
     * this person's income.
     *
     * dependants === 0 is NOT a failure — it is 'not_applicable'. Term life
     * replaces income for people who rely on it; with nobody relying on it
     * there is nothing to replace. Treating 0 as a shortfall would nag every
     * single person without dependants about cover they have no use for, which
     * is exactly the mis-selling reflex this module is meant to avoid.
     *
     * @return array{id:string,status:string,multiple:?float,cover:?float,annual_income:float,dependants:?int,floor:float,comfortable:float}
     */
    public static function lifeCover(?int $dependants, ?float $termCover, float $annualIncome): array
    {
        $base = [
            'id'            => 'life_cover',
            'cover'         => $termCover,
            'annual_income' => max(0.0, $annualIncome),
            'dependants'    => $dependants,
            'floor'         => self::LIFE_COVER_MULTIPLE_FLOOR,
            'comfortable'   => self::LIFE_COVER_MULTIPLE_COMFORTABLE,
        ];

        if ($dependants === null) {
            return $base + ['status' => 'not_recorded', 'multiple' => null];
        }
        if ($dependants === 0) {
            return $base + ['status' => 'not_applicable', 'multiple' => null];
        }
        if ($termCover === null) {
            return $base + ['status' => 'not_recorded', 'multiple' => null];
        }
        // Cover is recorded but there is no income to size it against. The
        // cover amount is real; the judgement about whether it is enough is
        // not available, so this reports as unsized rather than guessing.
        if ($annualIncome <= 0.0) {
            return $base + ['status' => 'not_recorded', 'multiple' => null];
        }

        $multiple = max(0.0, $termCover) / $annualIncome;
        $status = 'short';
        if ($multiple >= self::LIFE_COVER_MULTIPLE_FLOOR) {
            $status = 'ok';
        } elseif ($multiple > 0.0) {
            $status = 'partial';
        }

        return $base + ['status' => $status, 'multiple' => $multiple];
    }

    /**
     * Medical cover against a reference floor.
     *
     * The softest of the four checks: the right sum insured genuinely varies
     * with city, age and family size, so this compares against a floor rather
     * than scoring against a target, and the caller is expected to say so.
     *
     * @return array{id:string,status:string,cover:?float,floor:float}
     */
    public static function healthCover(?float $healthCover): array
    {
        $base = ['id' => 'health_cover', 'cover' => $healthCover, 'floor' => self::HEALTH_COVER_FLOOR];

        if ($healthCover === null) {
            return $base + ['status' => 'not_recorded'];
        }
        if ($healthCover <= 0.0) {
            return $base + ['status' => 'short'];
        }
        return $base + ['status' => $healthCover >= self::HEALTH_COVER_FLOOR ? 'ok' : 'partial'];
    }

    /**
     * Debt that costs more than the plan assumes the corpus will earn.
     *
     * This is the one check that needs no reference point at all: both numbers
     * are already recorded, and comparing them is arithmetic. A loan at 36%
     * against a plan assuming 11% is a 25-point certain gap, and saying so is
     * a statement about the figures rather than an instruction about what to
     * do with them.
     *
     * A liability with no interest_rate recorded is counted as UNRATED and
     * surfaced as such. Assuming an unrated loan is cheap would quietly hide
     * the exact case this check exists to catch.
     *
     * $ledgerHasRows distinguishes the two ways a liability list can be empty.
     * Somebody who has recorded a portfolio and simply owes nothing genuinely
     * passes this check. Somebody who has recorded NOTHING AT ALL has not
     * passed anything — an untouched ledger is an unanswered question, and
     * showing it as a green "no expensive debt" on a brand-new account is the
     * same flattering-blank failure the emergency-reserve denominator guards
     * against. There is no "I have no loans" field to tick, so an empty ledger
     * is the only signal available.
     *
     * @param array<int,array{label?:string,value:mixed,interest_rate:mixed}> $liabilities
     * @return array{id:string,status:string,costly:array<int,array{label:string,value:float,interest_rate:float,gap:float}>,unrated:int,assumed_return:?float,total_costly:float}
     */
    public static function costlyDebt(
        array $liabilities,
        ?float $assumedReturnRate,
        bool $ledgerHasRows = true,
        string $scope = 'person'
    ): array {
        $costly = [];
        $unrated = 0;
        $totalCostly = 0.0;

        foreach ($liabilities as $row) {
            $rate = $row['interest_rate'] ?? null;
            if ($rate === null || $rate === '' || !is_numeric($rate)) {
                $unrated++;
                continue;
            }
            $rate = (float) $rate;
            $value = is_numeric($row['value'] ?? null) ? (float) $row['value'] : 0.0;

            // With no return assumption to compare against there is no gap to
            // report — the loan is recorded, but the comparison this check
            // makes is unavailable, so it is not called costly.
            if ($assumedReturnRate === null) {
                continue;
            }
            if ($rate > $assumedReturnRate) {
                $costly[] = [
                    'label'         => (string) ($row['label'] ?? 'Loan'),
                    'value'         => $value,
                    'interest_rate' => $rate,
                    'gap'           => $rate - $assumedReturnRate,
                ];
                $totalCostly += $value;
            }
        }

        $status = 'ok';
        if ($costly !== []) {
            $status = 'short';
        } elseif ($unrated > 0) {
            $status = 'not_recorded';
        } elseif ($assumedReturnRate === null && $liabilities !== []) {
            $status = 'not_recorded';
        } elseif ($liabilities === [] && !$ledgerHasRows) {
            // Nothing recorded anywhere — not a clean bill of health.
            $status = 'not_recorded';
        }

        return [
            'id'             => 'costly_debt',
            'scope'          => $scope,
            'status'         => $status,
            'costly'         => $costly,
            'unrated'        => $unrated,
            'assumed_return' => $assumedReturnRate,
            'total_costly'   => $totalCostly,
        ];
    }

    /**
     * Assemble the four checks plus the counts a caller needs to caption a
     * readiness score honestly.
     *
     * `unmet` counts only checks that are actually falling short — 'partial'
     * and 'short'. 'not_recorded' is counted separately as `open`, because an
     * unanswered question and a known shortfall are different things and
     * collapsing them would either overstate a problem or hide one.
     *
     * @param array<int,array{label?:string,value:mixed,interest_rate:mixed}> $liabilities
     * @return array{checks:array<int,array<string,mixed>>,unmet:int,open:int,ok:int}
     */
    public static function summary(
        float $monthlyExpenses,
        float $liquidAssets,
        float $annualIncome,
        ?int $dependants,
        ?float $termCover,
        ?float $healthCover,
        array $liabilities,
        ?float $assumedReturnRate,
        bool $ledgerHasRows = true,
        string $sharedScope = 'person'
    ): array {
        // WHY THE SCOPE SPLITS, and it follows the arithmetic of each check
        // rather than a preference:
        //
        //   * Reserve and debt are HOUSEHOLD facts for a couple. Rent,
        //     groceries and EMIs are recorded by whoever entered them, so a
        //     per-person reserve makes that partner look badly under-reserved
        //     and the other look fine — and NEITHER number is true. The joint
        //     savings cover the joint outgoings.
        //   * Cover is a PER-PERSON fact and must stay one. Life cover
        //     replaces a specific person's income; replacing a ₹40L earner and
        //     a ₹12L earner are different problems, and averaging them would
        //     under-insure one and over-insure the other. Medical cover is
        //     per-person for the same reason (and dependants are recorded
        //     per-person too).
        $checks = [
            self::emergencyReserve($monthlyExpenses, $liquidAssets, $sharedScope),
            self::lifeCover($dependants, $termCover, $annualIncome),
            self::healthCover($healthCover),
            self::costlyDebt($liabilities, $assumedReturnRate, $ledgerHasRows, $sharedScope),
        ];

        $unmet = 0;
        $open = 0;
        $ok = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'short' || $c['status'] === 'partial') {
                $unmet++;
            } elseif ($c['status'] === 'not_recorded') {
                $open++;
            } elseif ($c['status'] === 'ok') {
                $ok++;
            }
            // 'not_applicable' counts toward none of the three: it is neither
            // a gap nor an achievement nor an open question.
        }

        return ['checks' => $checks, 'unmet' => $unmet, 'open' => $open, 'ok' => $ok];
    }
}
