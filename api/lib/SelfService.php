<?php
declare(strict_types=1);

/**
 * Self-serve individual tier — the write-permission gate.
 *
 * THE PROBLEM THIS SOLVES. Every data-entry endpoint in this codebase is
 * advisor-only (`verifyAccess($db, 'advisor')`), because in the B2B2C model
 * the advisor authors the plan and the client reads it. An individual planning
 * alone has no advisor, so they must be able to author their own data — but
 * granting that to the 'client' ROLE would hand the same power to every
 * advisor-managed client on the platform, silently breaking:
 *
 *   * the Jr -> Sr plan-approval workflow (advice a senior signed off on could
 *     be edited afterwards by the client it was given to),
 *   * the change_log's implicit meaning (that an advisor made the change),
 *   * and the advisory relationship itself.
 *
 * So the gate is on the TENANT KIND, not the role. A client may author their
 * own data only inside a 'personal' tenant — one containing no advisor whose
 * work could be overwritten.
 *
 * THE TWO CHECKS, both required:
 *   1. the acting session's tenant is kind='personal', and
 *   2. the client_id being written is the session's OWN user id.
 *
 * CHECK (2) IS LOAD-BEARING, and it is worth being precise about why, because
 * the reason changed. This gate was written for a "tenant of one", and (2) was
 * then merely defence in depth: a personal tenant held exactly one person, so
 * there was nobody else's data to write. Since sql/035 a personal tenant can
 * hold a COUPLE planning together, so that premise no longer holds and (2) is
 * now the only thing stopping one partner from editing the other's plan.
 *
 * No code changed when that happened — the check was already correct, for a
 * reason that has since become the actual reason. Do not "simplify" it away on
 * the grounds that a personal tenant is a tenant of one. It is not, any more.
 *
 * Advisors are unaffected throughout. An advisor session in a firm tenant
 * continues to pass the existing verifyAccess('advisor') check exactly as
 * before; nothing here widens what an advisor can do.
 */

require_once __DIR__ . '/TenantScopedDb.php';

/**
 * Is this tenant a self-serve individual ("tenant of one")?
 *
 * Request-cached: a single endpoint can ask several times (the access gate,
 * then the disclosure mode, then a response field) and each PHP request is its
 * own process, so the cache can never go stale across requests. Same caching
 * reasoning as getPlatformSettings().
 *
 * Fails CLOSED — an unreadable or missing tenant row reads as 'firm', the
 * restrictive answer, so a broken lookup can never accidentally grant
 * self-service write access.
 */
function tenantIsPersonal(PDO $db, int $tenantId): bool
{
    static $cache = [];
    if (array_key_exists($tenantId, $cache)) {
        return $cache[$tenantId];
    }

    $stmt = $db->prepare('SELECT kind FROM tenants WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tenantId]);
    $kind = $stmt->fetchColumn();

    $cache[$tenantId] = ($kind === 'personal');
    return $cache[$tenantId];
}

/**
 * Authorise a write on $clientId's data by the current session, for endpoints
 * that are advisor-only in a firm but self-service for an individual.
 *
 * Returns the verified session on success; exits with a JSON error otherwise,
 * the same contract as verifyAccess() so callers never handle a null return.
 *
 * Accepts either:
 *   * an 'advisor' (or super_admin) session — the existing behaviour, any
 *     client in their own tenant; or
 *   * a 'client' session in a PERSONAL tenant, writing their OWN data.
 *
 * A client session in a FIRM tenant is refused with the same 403 an advisor-
 * only endpoint has always returned — an advisor-managed client's ability to
 * read but not author their plan is unchanged by this tier existing.
 *
 * @param int $clientId the client whose data is being written. Pass 0 when the
 *   endpoint takes no client_id (a personal user is always writing their own,
 *   so it is resolved from the session in that case).
 * @return array{user_id:int, tenant_id:int, role:string} the verified session
 */
function verifySelfServiceWrite(PDO $db, int $clientId = 0): array
{
    // verifyAccessAny() performs the CSRF check, session lookup and MFA gate
    // exactly as verifyAccess() would; the role narrowing happens below.
    $session = verifyAccessAny($db, ['advisor', 'client']);

    if ($session['role'] !== 'client') {
        return $session; // advisor / super_admin — unchanged path
    }

    $tenantId = (int) $session['tenant_id'];
    $userId   = (int) $session['user_id'];

    if (!tenantIsPersonal($db, $tenantId)) {
        // An advisor-managed client. Deliberately the SAME message an
        // advisor-only endpoint has always produced, so this tier's existence
        // is not detectable by probing a firm client's session.
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
        exit();
    }

    if ($clientId !== 0 && $clientId !== $userId) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not your data.']);
        exit();
    }

    return $session;
}

/**
 * The client id a write applies to.
 *
 * For an advisor it is whatever they supplied (they act on someone else's
 * data). For a personal user it is always themselves, and any client_id in the
 * request body is IGNORED rather than trusted — the same posture every
 * client-facing read endpoint already takes with a query-string client_id
 * (see cash_flow_list.php, client_portfolio_list.php).
 */
function resolveSelfServiceClientId(array $session, int $suppliedClientId): int
{
    return $session['role'] === 'client'
        ? (int) $session['user_id']
        : $suppliedClientId;
}

/**
 * Authorise a HOUSEHOLD read (sql/035) — the combined view a couple planning
 * together needs.
 *
 * Same tenant-kind-not-role shape as verifySelfServiceWrite(), and for the same
 * reason: households are advisor-only in a firm (docs/10 P0-1 — "clients never
 * see households", because a firm's household groups an advisor's clients and
 * one client has no business enumerating the others). In a personal tenant the
 * household IS the two people who deliberately joined it, so there is nothing
 * to leak that they did not opt into.
 *
 * A personal client is additionally pinned to their OWN household. Without
 * that they could pass any household_id and, in a tenant that happened to hold
 * more than one, read a household they are not in — so the id is not trusted
 * from the request, it is verified against their own row.
 *
 * @return array{session:array,household_id:?int,is_client:bool} household_id is
 *   the caller's own household for a personal client (null when they have not
 *   set one up), and null for an advisor, who supplies it per request and is
 *   scoped by tenant as before.
 */
function verifyHouseholdAccess(PDO $db): array
{
    $session = verifyAccessAny($db, ['advisor', 'client']);

    if ($session['role'] !== 'client') {
        return ['session' => $session, 'household_id' => null, 'is_client' => false];
    }

    if (!tenantIsPersonal($db, (int) $session['tenant_id'])) {
        // An advisor-managed client. Identical response to the 'advisor'-only
        // gate these endpoints carried before, so a firm client probing them
        // cannot tell this tier exists.
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Privilege escalation blocked.']);
        exit();
    }

    $stmt = $db->prepare('SELECT household_id FROM users WHERE id = :id AND tenant_id = :t LIMIT 1');
    $stmt->execute([':id' => (int) $session['user_id'], ':t' => (int) $session['tenant_id']]);
    $own = $stmt->fetchColumn();

    return [
        'session'      => $session,
        'household_id' => ($own === false || $own === null) ? null : (int) $own,
        'is_client'    => true,
    ];
}

/**
 * The household id a read applies to, mirroring resolveSelfServiceClientId().
 *
 * An advisor gets what they asked for (tenant-scoped downstream as always); a
 * personal client always gets their own, and a supplied id is ignored rather
 * than trusted.
 */
function resolveHouseholdId(array $access, int $suppliedHouseholdId): int
{
    if (!$access['is_client']) {
        return $suppliedHouseholdId;
    }
    return $access['household_id'] ?? 0;
}
