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
 * own data only inside a 'personal' tenant — a tenant of one, which by
 * construction contains nobody else's data and no advisor whose work could be
 * overwritten.
 *
 * THE TWO CHECKS, both required:
 *   1. the acting session's tenant is kind='personal', and
 *   2. the client_id being written is the session's OWN user id.
 *
 * (2) matters even though (1) implies a tenant of one: it is defence in depth,
 * costs one integer comparison, and means a bug that somehow put two users in
 * a personal tenant still cannot let one edit the other.
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
