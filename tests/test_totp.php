<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/Totp.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// Reach the private HOTP + base32 primitives so we can generate a genuinely
// valid code for "now" and prove verify() accepts it — testing the real code
// path rather than a re-implementation.
$hotp = new ReflectionMethod(Totp::class, 'hotp');
$hotp->setAccessible(true);
$b32d = new ReflectionMethod(Totp::class, 'base32Decode');
$b32d->setAccessible(true);

function currentCode(string $secret, int $offsetSteps = 0): string
{
    global $hotp, $b32d;
    $counter = (int) floor(time() / 30) + $offsetSteps;
    $key = $b32d->invoke(null, $secret);
    return $hotp->invoke(null, $key, $counter);
}

// --- secret generation --------------------------------------------------------
$secret = Totp::generateSecret();
assertTrue(strlen($secret) === 32, 'generateSecret() returns a 32-char base32 string (160-bit secret)');
assertTrue(strspn($secret, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567') === strlen($secret),
    'generateSecret() uses only the RFC 4648 base32 alphabet');
assertTrue(Totp::generateSecret() !== Totp::generateSecret(),
    'two generated secrets differ (randomness sanity)');

// --- verify accepts the live code and the ±1 drift window ---------------------
assertTrue(Totp::verify($secret, currentCode($secret)) === true,
    'verify() accepts the code for the current 30s step');
assertTrue(Totp::verify($secret, currentCode($secret, -1)) === true,
    'verify() accepts the previous step (−1) for clock drift');
assertTrue(Totp::verify($secret, currentCode($secret, +1)) === true,
    'verify() accepts the next step (+1) for clock drift');

// --- verify rejects codes outside the window and malformed input --------------
assertTrue(Totp::verify($secret, currentCode($secret, +2)) === false,
    'verify() rejects a code 2 steps away (window is deliberately only ±1)');
assertTrue(Totp::verify($secret, '000000') === false || currentCode($secret) === '000000',
    'verify() rejects an unrelated code');
assertTrue(Totp::verify($secret, 'abcdef') === false, 'verify() rejects non-numeric input');
assertTrue(Totp::verify($secret, '12345') === false, 'verify() rejects a 5-digit code');
assertTrue(Totp::verify($secret, '') === false, 'verify() rejects an empty code');

// --- a code from a different secret must not verify ---------------------------
$other = Totp::generateSecret();
assertTrue(Totp::verify($secret, currentCode($other)) === false,
    'verify() rejects a valid code generated from a different secret');

// --- otpauth URI is well-formed and carries the secret ------------------------
$uri = Totp::otpauthUri($secret, 'user@example.com');
assertTrue(str_starts_with($uri, 'otpauth://totp/'), 'otpauthUri() has the otpauth scheme');
assertTrue(str_contains($uri, 'secret=' . $secret), 'otpauthUri() embeds the secret');
assertTrue(str_contains($uri, 'issuer=HorizonPlan'), 'otpauthUri() carries the issuer');

echo "\nAll TOTP tests passed.\n";
