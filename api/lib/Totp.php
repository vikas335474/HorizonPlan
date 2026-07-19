<?php
declare(strict_types=1);

/**
 * RFC 6238 TOTP implementation — no Composer, no external dependencies.
 *
 * This is straightforward enough to implement correctly directly rather than
 * pulling in a library: TOTP is HMAC-SHA1 over a time counter, truncated to
 * 6 digits. The only non-trivial piece is base32 encoding/decoding, which is
 * also included here.
 *
 * Security properties maintained:
 * - Secrets are generated via random_bytes() — cryptographically random.
 * - Verification accepts a ±1 window (one 30-second step in either direction)
 *   to tolerate minor clock drift between server and authenticator app.
 * - The window is deliberately NOT wider than ±1 — a wider window reduces
 *   effective brute-force resistance (6-digit code space is only 1,000,000;
 *   a ±1 window exposes ~3 valid codes at any moment, which is already the
 *   standard trade-off Google Authenticator / Authy use).
 * - Constant-time comparison (hash_equals) prevents timing attacks on code
 *   verification.
 *
 * Compatible with Google Authenticator, Authy, Bitwarden, 1Password, and any
 * other RFC 6238-compliant TOTP app.
 */
final class Totp
{
    private const DIGITS  = 6;
    private const STEP    = 30;  // seconds per counter increment
    private const WINDOW  = 1;   // ±steps to accept for clock-drift tolerance
    private const SECRET_BYTES = 20; // 160-bit secret → 32-character base32 string

    /**
     * Generate a new cryptographically random TOTP secret.
     * Returns a base32-encoded string suitable for storing in users.mfa_secret
     * and for embedding in the otpauth:// URI handed to the authenticator app.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Build the otpauth:// URI for a QR code. The authenticator app scans this
     * to import the secret without the user having to type the base32 string.
     *
     * @param string $secret Base32-encoded secret from generateSecret()
     * @param string $email  The user's email — shown as the account label in the app
     * @param string $issuer Display name shown in the app (the app/service name)
     */
    public static function otpauthUri(string $secret, string $email, string $issuer = 'HorizonPlan'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::STEP
        );
    }

    /**
     * Verify a user-submitted OTP code against a stored secret.
     * Returns true if the code is valid within the ±WINDOW drift allowance.
     *
     * @param string $secret     Base32-encoded secret from users.mfa_secret
     * @param string $userCode   The 6-digit code the user typed
     */
    public static function verify(string $secret, string $userCode): bool
    {
        $userCode = str_pad(trim($userCode), self::DIGITS, '0', STR_PAD_LEFT);
        if (!ctype_digit($userCode) || strlen($userCode) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(time() / self::STEP);
        $key = self::base32Decode($secret);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $expected = self::hotp($key, $counter + $offset);
            if (hash_equals($expected, $userCode)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Internal: HOTP (RFC 4226) — the counter-based primitive TOTP is built on
    // -------------------------------------------------------------------------

    private static function hotp(string $key, int $counter): string
    {
        // Pack counter as a big-endian 64-bit unsigned integer (8 bytes)
        $counterBytes = pack('N*', 0) . pack('N*', $counter);

        $hmac = hash_hmac('sha1', $counterBytes, $key, true);

        // Dynamic truncation per RFC 4226 Section 5.4
        $offset = ord($hmac[19]) & 0x0F;
        $code   = (
            (ord($hmac[$offset])     & 0x7F) << 24 |
            (ord($hmac[$offset + 1]) & 0xFF) << 16 |
            (ord($hmac[$offset + 2]) & 0xFF) <<  8 |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // -------------------------------------------------------------------------
    // Internal: RFC 4648 Base32 (uppercase, no padding needed for our fixed
    // SECRET_BYTES length, but decode handles padding gracefully anyway)
    // -------------------------------------------------------------------------

    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Encode(string $data): string
    {
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer    = ($buffer << 8) | ord($char);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output   .= self::BASE32_CHARS[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::BASE32_CHARS[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }

    private static function base32Decode(string $encoded): string
    {
        // Normalise: uppercase, strip padding
        $encoded  = strtoupper(rtrim($encoded, '='));
        $charMap  = array_flip(str_split(self::BASE32_CHARS));
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split($encoded) as $char) {
            if (!isset($charMap[$char])) {
                // Silently skip unknown characters (spaces, dashes users might type)
                continue;
            }
            $buffer    = ($buffer << 5) | $charMap[$char];
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
