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
        // tenants has no tenant_id column of its own (it IS the tenant
        // registry) — raw insert, same as tenants_create.php's super_admin path.
        $db->prepare(
            "INSERT INTO tenants (company_name, advisory_mode, signup_source) VALUES (:name, 'distribution', 'trial_signup')"
        )->execute([':name' => $companyName]);
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
