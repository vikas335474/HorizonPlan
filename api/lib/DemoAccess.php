<?php
declare(strict_types=1);

// Backs the public "try a live demo" entry point (api/demo_login.php,
// api/demo_firms_list.php). Split out from those endpoints the same way
// GoogleAuth.php splits network/pure/DB layers — this file is pure DB reads,
// no session/HTTP side effects, so it's directly unit-testable.
//
// The design decision this file exists to enforce: the public demo-login
// button must NOT depend on platform_settings.demo_mode to bypass MFA.
// demo_mode='on' skips the mandatory-MFA gate PLATFORM-WIDE (every tenant,
// including real self-serve trial-signup tenants created via api/signup.php
// that may share this same production instance) — that's the right behavior
// for an internal/QA sandbox where every tenant is disposable, but wrong for
// a public production site that also hosts real trial firms with real
// security expectations. Instead, tools/seed_demo_data_full.php gives each
// firm's 'admin' handle (the firm_admin) a REAL, randomly generated TOTP
// secret at seed time, so it's a genuinely MFA-enrolled account like any
// other — demo_login.php authenticates it directly (the visitor never types
// or sees a password or code), and every other security control in the app
// runs completely unmodified and unaware anything special happened.
//
// Safety boundary: every function here only ever resolves a user whose email
// is under the fixed *.demo.horizonplan.in domain (tools/seed_demo_data_full.php's
// own convention for every row it creates) — checked explicitly, not just
// implied by the query shape, so a future refactor of the lookup query can't
// accidentally widen it to match a real account.

const DEMO_ACCOUNT_EMAIL_SUFFIX = '.demo.horizonplan.in';

/**
 * Pure check, no DB — whether an email is under the fixed demo-account
 * domain. Extracted so it's directly unit-testable on its own (no need to
 * contrive a bad database row to exercise it), and reused as the one
 * safety-net check resolveDemoFirmAdmin() applies to whatever its query
 * returns, independent of how that query happens to be constructed.
 */
function isDemoAccountEmail(string $email): bool
{
    return str_ends_with($email, DEMO_ACCOUNT_EMAIL_SUFFIX);
}

/**
 * The FIRMS blueprint (name/slug/advisory_mode/color) lives in
 * api/lib/DemoSeeder.php — read from there rather than duplicated here, same
 * reasoning api/demo_reset.php already uses: one source of truth that can't
 * drift. NOT tools/seed_demo_data_full.php — that file is CLI-only and never
 * deployed to production (see its own docblock), so requiring it from here
 * would 500 on a real deploy with "failed to open stream".
 */
function loadDemoFirmsBlueprint(): array
{
    require_once __DIR__ . '/DemoSeeder.php';
    return FIRMS;
}

/**
 * Resolve the given firm slug's firm_admin demo account — the one account
 * tools/seed_demo_data_full.php gives a real TOTP secret to. Returns null if
 * the slug is unknown, the firm hasn't actually been seeded yet, or (should
 * never happen, but checked rather than assumed) the resolved row somehow
 * isn't a real MFA-enrolled *.demo.horizonplan.in account.
 *
 * @return array{id:int, tenant_id:int, role:string, email:string, company_name:string}|null
 */
function resolveDemoFirmAdmin(PDO $db, string $slug): ?array
{
    $firms = loadDemoFirmsBlueprint();
    $firm = null;
    foreach ($firms as $candidate) {
        if ($candidate['slug'] === $slug) {
            $firm = $candidate;
            break;
        }
    }
    if ($firm === null) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT u.id, u.tenant_id, u.role, u.email, t.company_name
         FROM users u
         JOIN tenants t ON t.id = u.tenant_id
         WHERE t.company_name = :company_name
           AND u.email = :email
           AND u.role = 'advisor'
           AND u.firm_role = 'firm_admin'
           AND u.mfa_secret IS NOT NULL
         LIMIT 1"
    );
    $stmt->execute([
        ':company_name' => $firm['name'],
        ':email'        => "admin@{$firm['slug']}" . DEMO_ACCOUNT_EMAIL_SUFFIX,
    ]);
    $row = $stmt->fetch();

    if (!$row || !isDemoAccountEmail($row['email'])) {
        return null;
    }

    return [
        'id'           => (int) $row['id'],
        'tenant_id'    => (int) $row['tenant_id'],
        'role'         => $row['role'],
        'email'        => $row['email'],
        'company_name' => $row['company_name'],
    ];
}

/**
 * The list of demo firms actually ready for the public "try a live demo"
 * picker — only ones that are both seeded (the tenant exists) and
 * demo-login-ready (their firm_admin resolves via resolveDemoFirmAdmin()).
 * Returns an empty array (not an error) if nothing has been seeded yet —
 * the frontend hides the whole section in that case, same graceful-
 * degradation precedent as the Google Sign-In button with no Client ID.
 *
 * @return array<int, array{slug:string, name:string, advisory_mode:string}>
 */
function listAvailableDemoFirms(PDO $db): array
{
    $available = [];
    foreach (loadDemoFirmsBlueprint() as $firm) {
        if (resolveDemoFirmAdmin($db, $firm['slug']) !== null) {
            $available[] = [
                'slug'          => $firm['slug'],
                'name'          => $firm['name'],
                'advisory_mode' => $firm['advisory_mode'],
            ];
        }
    }
    return $available;
}
