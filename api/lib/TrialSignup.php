<?php
declare(strict_types=1);

// Self-serve trial signup's DB-touching core, split out of api/signup.php so
// it's directly testable against a real database without an HTTP round
// trip — same precedent as GoogleAuth.php/DemoAccess.php: pure input
// validation (format checks, rate limiting, the friendly pre-flight
// email-uniqueness message) stays in the endpoint; deterministic,
// transaction-wrapped DB logic lives here.

require_once __DIR__ . '/TenantScopedDb.php';

/**
 * Creates a new trial tenant — ALWAYS distribution mode, signup_source =
 * 'trial_signup' (sql/022); advisory_mode is never accepted as an input here,
 * only ever hardcoded, so this function cannot be used to create an advisory
 * tenant no matter what a caller passes — and its founding advisor
 * (firm_admin — the only person in a brand-new firm). Caller is responsible
 * for input validation and the pre-flight email-uniqueness check (for a
 * friendly 409 instead of a raw constraint-violation 500); this function
 * still runs inside its own transaction so a failure partway through never
 * leaves an orphaned tenant with no user, and the users.email UNIQUE
 * constraint is the real safety net if a race slips past the caller's
 * pre-check.
 *
 * @return array{tenant_id:int, user_id:int}
 */
function createTrialTenant(PDO $db, string $companyName, string $email, string $passwordHash): array
{
    $db->beginTransaction();
    try {
        // docs/09 Session 8: a self-serve trial starts on the Starter plan —
        // the same default tenants_create.php's super_admin path now uses for
        // a newly onboarded firm (sql/044's decision record). A trial that
        // hits its seat limit is exactly the gating story this billing
        // groundwork exists to demo.
        $starterPlanId = $db->query("SELECT id FROM subscription_plans WHERE code = 'starter' LIMIT 1")->fetchColumn();

        // tenants has no tenant_id column of its own (it IS the tenant
        // registry) — raw insert, same as tenants_create.php's super_admin path.
        $db->prepare(
            "INSERT INTO tenants (company_name, advisory_mode, signup_source, plan_id) VALUES (:name, 'distribution', 'trial_signup', :plan_id)"
        )->execute([':name' => $companyName, ':plan_id' => $starterPlanId ?: null]);
        $tenantId = (int) $db->lastInsertId();

        $scopedDb = new TenantScopedDb($db, $tenantId);
        $userId = $scopedDb->insert('users', [
            'email'         => $email,
            'password_hash' => $passwordHash,
            'role'          => 'advisor',
            'firm_role'     => 'firm_admin',
        ]);
        $scopedDb->logChange(
            'user',
            $userId,
            'created',
            null,
            json_encode(['email' => $email, 'role' => 'advisor', 'signup_source' => 'trial_signup']),
            $userId
        );

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['tenant_id' => $tenantId, 'user_id' => (int) $userId];
}

/**
 * Self-serve INDIVIDUAL signup — the "tenant of one" (sql/033).
 *
 * Same shape as createTrialTenant() above, three deliberate differences:
 *
 *   * kind = 'personal', which is what unlocks self-service writes for this
 *     user and nobody else (see api/lib/SelfService.php).
 *   * role = 'client', not 'advisor'. An individual is the subject of the
 *     plan, not a practitioner, so they get exactly the client surface that
 *     already exists — goals, projections, what-ifs, portfolio, cash flow,
 *     progress — with authoring added on top.
 *   * advisory_mode = 'self_directed'. There is no adviser behind this plan,
 *     and the disclosure must say something true (CLAUDE.md rule #3).
 *
 * advisory_mode and kind are hardcoded, never taken from input, for the same
 * reason createTrialTenant() hardcodes 'distribution': this function must not
 * be usable to mint an advisory-mode tenant, or to flip an individual's
 * account into a firm, no matter what a caller passes.
 *
 * `company_name` carries the person's own display name — the column is the
 * tenant's label everywhere in the UI (AppHeader brand, report letterhead),
 * and for an individual that label is simply them.
 *
 * @return array{tenant_id:int, user_id:int}
 */
function createPersonalTenant(PDO $db, string $displayName, string $email, string $passwordHash): array
{
    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO tenants (company_name, kind, advisory_mode, signup_source)
             VALUES (:name, 'personal', 'self_directed', 'personal_signup')"
        )->execute([':name' => $displayName]);
        $tenantId = (int) $db->lastInsertId();

        $scopedDb = new TenantScopedDb($db, $tenantId);
        $userId = $scopedDb->insert('users', [
            'email'         => $email,
            // sql/035: the name they gave at signup belongs on the PERSON, not
            // only on tenants.company_name. It was always slightly odd to
            // store a human name as a company name, and it matters once a
            // tenant can hold a couple and a household view has to name both.
            'display_name'  => $displayName !== '' ? $displayName : null,
            'password_hash' => $passwordHash,
            'role'          => 'client',
        ]);
        $scopedDb->logChange(
            'user',
            $userId,
            'created',
            null,
            json_encode(['email' => $email, 'role' => 'client', 'signup_source' => 'personal_signup']),
            $userId
        );

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['tenant_id' => $tenantId, 'user_id' => (int) $userId];
}
