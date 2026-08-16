<?php
declare(strict_types=1);

// Billing / subscription plan-tier enforcement (docs/09 Session 8, sql/044).
//
// Deliberately narrow, matching the migration's own scope: this only checks
// advisor-seat headroom before an advisor is created. It does not touch
// clients, goals, or anything else — those are explicitly unlimited under
// every tier in this session (see sql/044's decision record).
//
// A personal-kind tenant (docs/13's self-serve individual tier) never has a
// plan_id at all — tenantPlan() returns null for one, and
// assertAdvisorSeatAvailable() treats "no plan" the same as "unlimited plan":
// nothing in that tier creates advisors in the first place (a personal
// tenant's one user is role='client'), so this is a defensive no-op there,
// not a gate that could ever fire.

final class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $planCode,
        public readonly string $planName,
        public readonly int $maxAdvisors,
        public readonly int $currentCount
    ) {
        parent::__construct(
            "This firm's plan ({$planName}) allows up to {$maxAdvisors} advisor" . ($maxAdvisors === 1 ? '' : 's')
            . ". Contact your account manager to upgrade the plan before adding another."
        );
    }
}

/**
 * The tenant's current plan row, or null if it has none (personal-kind
 * tenants, or a firm-kind tenant somehow left unassigned — treated as
 * unlimited by assertAdvisorSeatAvailable(), never as zero-seat).
 *
 * @return array{id:int, code:string, name:string, max_advisors:?int, price_inr_monthly:?int}|null
 */
function tenantPlan(PDO $db, int $tenantId): ?array
{
    $stmt = $db->prepare(
        'SELECT p.id, p.code, p.name, p.max_advisors, p.price_inr_monthly
           FROM tenants t
           JOIN subscription_plans p ON p.id = t.plan_id
          WHERE t.id = :tenant_id
          LIMIT 1'
    );
    $stmt->execute([':tenant_id' => $tenantId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id'                => (int) $row['id'],
        'code'              => $row['code'],
        'name'              => $row['name'],
        'max_advisors'      => $row['max_advisors'] !== null ? (int) $row['max_advisors'] : null,
        'price_inr_monthly' => $row['price_inr_monthly'] !== null ? (int) $row['price_inr_monthly'] : null,
    ];
}

/**
 * Throws PlanLimitExceededException if the tenant's plan has a finite
 * advisor-seat limit and it's already reached (counting existing advisors,
 * before the new one is inserted). No plan / a NULL max_advisors both mean
 * unlimited — this function only ever blocks a plan that names a real cap.
 */
function assertAdvisorSeatAvailable(PDO $db, int $tenantId): void
{
    $plan = tenantPlan($db, $tenantId);
    if ($plan === null || $plan['max_advisors'] === null) {
        return;
    }

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND role = 'advisor'"
    );
    $countStmt->execute([':tenant_id' => $tenantId]);
    $current = (int) $countStmt->fetchColumn();

    if ($current >= $plan['max_advisors']) {
        throw new PlanLimitExceededException($plan['code'], $plan['name'], $plan['max_advisors'], $current);
    }
}
