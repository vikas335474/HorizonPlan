<?php
declare(strict_types=1);

/**
 * Pure unit test for isDemoAccountEmail() — no network, no DB. This is the
 * one safety-net check api/lib/DemoAccess.php applies to every row it ever
 * resolves before treating it as loggable-into via the public demo-login
 * endpoint, independent of how the lookup query itself is constructed.
 */

require_once __DIR__ . '/../api/lib/DemoAccess.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

assertTrue(
    isDemoAccountEmail('admin@nirvana.demo.horizonplan.in') === true,
    'a real seeded demo account email is recognized'
);
assertTrue(
    isDemoAccountEmail('senior@lakshmi.demo.horizonplan.in') === true,
    'any handle under the demo domain is recognized, not just admin@'
);
assertTrue(
    isDemoAccountEmail('advisor@realfirm.com') === false,
    'a real (non-demo-domain) email is rejected'
);
assertTrue(
    isDemoAccountEmail('admin@demo.horizonplan.in.evil.com') === false,
    'a lookalike domain that merely CONTAINS the demo suffix, but does not END with it, is rejected'
);
assertTrue(
    isDemoAccountEmail('adminXdemo.horizonplan.in') === false,
    'a near-miss missing the leading dot is rejected'
);
assertTrue(
    isDemoAccountEmail('') === false,
    'an empty string is rejected'
);

echo "\nAll demo access validation tests passed.\n";
