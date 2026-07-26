<?php
declare(strict_types=1);

// docs/09 Group 2 Session 4 — magic-link invite activation. Replaces the
// shared-temp-password pattern in tenants_create.php/admin_advisor_create.php
// with a real invite-link flow, reusing password_resets (migration 009 +
// migration 025's `purpose` column) rather than a parallel mechanism —
// redemption is identical to a password reset (password_reset_confirm.php
// needs zero changes), only issuance differs: a 7-day expiry instead of 30
// minutes, since a real admin-provisioned invite might not be opened same-day.

require_once __DIR__ . '/../db_config.php';

const INVITE_TOKEN_TTL_SECONDS = 7 * 24 * 3600; // 7 days to accept an invite

/**
 * Issue an invite token for a newly created user: stores a SHA-256 hash of a
 * fresh random token (never the raw value — same property password_resets
 * already has for the "forgot password" flow, and doubly important here
 * since the account's own password_hash is unusable/unknown to anyone).
 * Returns the raw token — put it in the emailed link, never store it.
 */
function issueInviteToken(PDO $db, int $userId): string
{
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + INVITE_TOKEN_TTL_SECONDS);

    $db->prepare(
        'INSERT INTO password_resets (token_hash, user_id, purpose, expires_at)
         VALUES (:token_hash, :user_id, :purpose, :expires_at)'
    )->execute([
        ':token_hash' => $tokenHash,
        ':user_id'    => $userId,
        ':purpose'    => 'invite',
        ':expires_at' => $expiresAt,
    ]);

    return $rawToken;
}

/**
 * Build the activation link for a raw invite token. Separate route
 * (/accept-invite) from the forgot-password flow's /reset-password — same
 * underlying token/redemption mechanism, different landing-page copy
 * ("welcome, set your password" vs. "reset your password").
 */
function buildInviteLink(string $rawToken): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    return "{$scheme}://{$host}/accept-invite?token={$rawToken}";
}

/**
 * A password nobody will ever know or need to type — set on an
 * invite-created account so users.password_hash is never NULL/empty, but
 * the account can only actually be accessed by redeeming the invite token
 * (or, later, a real password reset).
 */
function unusablePasswordHash(): string
{
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
}
