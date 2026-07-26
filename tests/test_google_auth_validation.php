<?php
declare(strict_types=1);

/**
 * Pure unit test for validateGooglePayload() — no network, no DB. Covers the
 * trust checks auth_google.php relies on to accept a Google-verified payload:
 * aud must match this deployment's Client ID, iss must be Google's, and
 * email_verified must be true — including Google's real tokeninfo quirk of
 * returning it as the *string* "true"/"false", not a JSON boolean.
 *
 * fetchGoogleTokenInfo() itself (the actual network call) is deliberately not
 * covered here — see CLAUDE.md for why a live valid-token round trip isn't
 * possible without a real Google Cloud OAuth Client ID and a real browser
 * consent flow, neither available in this test environment.
 */

require_once __DIR__ . '/../api/lib/GoogleAuth.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';

function validPayload(array $overrides = []): array
{
    return array_merge([
        'aud'            => CLIENT_ID,
        'iss'            => 'accounts.google.com',
        'email_verified' => 'true', // Google's real tokeninfo shape: a string, not a bool
        'email'          => 'advisor@example.com',
        'sub'            => '1234567890',
    ], $overrides);
}

assertTrue(
    validateGooglePayload(validPayload(), CLIENT_ID) === true,
    'a fully valid payload passes'
);

assertTrue(
    validateGooglePayload(validPayload(['iss' => 'https://accounts.google.com']), CLIENT_ID) === true,
    'the long-form iss (https://accounts.google.com) is also accepted'
);

assertTrue(
    validateGooglePayload(validPayload(['email_verified' => true]), CLIENT_ID) === true,
    'a genuine JSON boolean true for email_verified is also accepted, not just the string form'
);

assertTrue(
    validateGooglePayload(validPayload(['aud' => 'someone-elses-client-id']), CLIENT_ID) === false,
    'a token issued for a different Client ID (wrong aud) is rejected'
);

assertTrue(
    validateGooglePayload(validPayload(['iss' => 'evil.example.com']), CLIENT_ID) === false,
    'a non-Google iss is rejected'
);

assertTrue(
    validateGooglePayload(validPayload(['email_verified' => 'false']), CLIENT_ID) === false,
    'email_verified=false (string) is rejected'
);

assertTrue(
    validateGooglePayload(validPayload(['email_verified' => false]), CLIENT_ID) === false,
    'email_verified=false (bool) is rejected'
);

assertTrue(
    validateGooglePayload(validPayload(['email' => '']), CLIENT_ID) === false,
    'an empty email is rejected'
);

assertTrue(
    validateGooglePayload(validPayload(['sub' => '']), CLIENT_ID) === false,
    'an empty sub is rejected'
);

assertTrue(
    validateGooglePayload([], CLIENT_ID) === false,
    'a completely empty payload is rejected, not fatally erroring on missing keys'
);

echo "\nAll Google auth validation tests passed.\n";
