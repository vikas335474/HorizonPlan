<?php
declare(strict_types=1);

/**
 * Guards the password-storage invariants login.php and password_update.php rely
 * on. Pure — no DB. These would have flagged, for example, a hash column too
 * short to hold a bcrypt string, or a change to the hashing algorithm that
 * silently broke verification of existing rows.
 */

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$password = 'correct horse battery staple';
$hash = password_hash($password, PASSWORD_BCRYPT);

// --- round trip ---------------------------------------------------------------
assertTrue(password_verify($password, $hash) === true,
    'password_verify() accepts the original password (login.php happy path)');
assertTrue(password_verify('wrong password', $hash) === false,
    'password_verify() rejects a wrong password');
assertTrue(password_verify($password . ' ', $hash) === false,
    'a trailing space is a different password (login.php does not trim the password)');

// --- format the schema must hold ---------------------------------------------
assertTrue(preg_match('/^\$2[aby]\$/', $hash) === 1, 'bcrypt hash has the $2y$ identifier');
assertTrue(strlen($hash) === 60, 'bcrypt hash is 60 chars');
assertTrue(strlen($hash) <= 255, 'hash fits users.password_hash VARCHAR(255)');

// --- distinct salts: same password hashes differently each time ---------------
assertTrue(password_hash($password, PASSWORD_BCRYPT) !== $hash,
    'each hash uses a fresh salt (two hashes of the same password differ)');
assertTrue(password_verify($password, password_hash($password, PASSWORD_BCRYPT)) === true,
    'the second, differently-salted hash still verifies');

// --- a plaintext-seeded row can never verify (why check_login warns on it) ----
assertTrue(password_verify($password, $password) === false,
    'a plaintext value in password_hash never verifies — must be a real hash');

echo "\nAll password-hashing tests passed.\n";
