<?php
declare(strict_types=1);

// Google Sign-In support — see sql/021_google_auth.sql for the schema
// decision and CLAUDE.md for the "Google login counts as MFA" product
// decision. Split into three layers, same reasoning as PlanMath.php's own
// split between pure computation and DB-touching callers:
//   1. fetchGoogleTokenInfo() — the one function that actually calls out to
//      Google over HTTPS. Not unit-tested here (no live Google credentials/
//      browser consent flow available in a CI sandbox) — code-reviewed only.
//   2. validateGooglePayload() — pure, no network/DB — checks the decoded
//      payload against this app's own trust requirements. Fully unit-testable
//      with fixture payloads.
//   3. resolveOrLinkGoogleUser() — DB-touching but deterministic given its
//      inputs (a validated email + Google "sub"), so it's testable against a
//      real database with a fake payload, without ever calling Google.

final class GoogleAuthException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Call Google's tokeninfo endpoint to validate an ID token's signature and
 * expiry and decode its claims. This is the "no JWKS library needed" shortcut
 * — Google performs the cryptographic verification server-side; the caller
 * (validateGooglePayload()) still must re-check aud/iss/email_verified itself,
 * since tokeninfo does not restrict those to any particular client.
 *
 * Returns null on any network failure or non-200 response (an invalid,
 * expired, or malformed token all land here) — deliberately not distinguished
 * further, since the caller only ever needs "valid" vs "not valid".
 */
function fetchGoogleTokenInfo(string $idToken): ?array
{
    if (!function_exists('curl_init')) {
        // Fails closed — no token is ever accepted without a successful,
        // affirmatively-verified Google response.
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        return null;
    }

    $payload = json_decode($body, true);
    return is_array($payload) ? $payload : null;
}

/**
 * Check a decoded tokeninfo payload against this app's trust requirements.
 * Pure — no network, no DB, so it's directly unit-testable with fixtures.
 *
 * Google's tokeninfo endpoint returns email_verified as the *string* "true"/
 * "false", not a JSON boolean — a real, easy-to-miss detail, so this checks
 * both string and boolean forms defensively rather than assuming one.
 */
function validateGooglePayload(array $payload, string $expectedClientId): bool
{
    $aud = $payload['aud'] ?? null;
    $iss = $payload['iss'] ?? null;
    $emailVerified = $payload['email_verified'] ?? null;
    $email = $payload['email'] ?? null;
    $sub = $payload['sub'] ?? null;

    $emailVerifiedBool = $emailVerified === true || $emailVerified === 'true';

    return $aud === $expectedClientId
        && in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)
        && $emailVerifiedBool
        && is_string($email) && $email !== ''
        && is_string($sub) && $sub !== '';
}

/**
 * Resolve a validated Google login to an existing HorizonPlan user, linking
 * the account on first use. Throws GoogleAuthException (with an HTTP status
 * the caller can respond with directly) on any failure — never returns a
 * partial/ambiguous result.
 *
 * No self-serve signup (docs/04): a Google login can only authenticate into
 * an account an admin already created, matched by verified email — never
 * create a new one. First successful login for that email binds google_sub;
 * every later login must present the *same* sub (an email match alone is not
 * enough once a sub is bound — Google's sub is stable and non-reassignable,
 * so a mismatch here means the linked Google account changed underneath the
 * email, which this app treats as suspicious rather than silently re-linking).
 *
 * @return array{id:int, tenant_id:int, role:string, mfa_secret:?string, google_sub:?string}
 */
function resolveOrLinkGoogleUser(PDO $db, string $email, string $googleSub): array
{
    $stmt = $db->prepare(
        "SELECT id, tenant_id, role, mfa_secret, google_sub FROM users WHERE email = :email LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new GoogleAuthException(
            'No HorizonPlan account found for that Google email. Contact your firm administrator.',
            404
        );
    }

    if (!empty($user['google_sub'])) {
        if (!hash_equals((string) $user['google_sub'], $googleSub)) {
            throw new GoogleAuthException(
                'This Google account no longer matches the one linked to this HorizonPlan account.',
                409
            );
        }
        return $user;
    }

    // First Google login for this account — bind it now. The UNIQUE key on
    // google_sub (sql/021) is the actual enforcement that a given Google
    // account can only ever be linked to one HorizonPlan user; a race here
    // surfaces as a PDO exception, which the caller lets propagate as a 500
    // rather than silently succeeding against the wrong row.
    $db->prepare("UPDATE users SET google_sub = :sub WHERE id = :id")
        ->execute([':sub' => $googleSub, ':id' => $user['id']]);
    $user['google_sub'] = $googleSub;

    return $user;
}
