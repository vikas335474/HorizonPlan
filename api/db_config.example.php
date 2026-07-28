<?php
// Copy this file to db_config.php (git-ignored) and fill in real values.
// Never commit db_config.php.

define('DB_HOST', 'localhost');
define('DB_NAME', 'horizonplan');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

// Google Sign-In (api/auth_google.php) — the OAuth 2.0 Client ID from a
// Google Cloud Console project (APIs & Services → Credentials → OAuth client
// ID → Web application). This is the same value the frontend build needs as
// VITE_GOOGLE_CLIENT_ID (see frontend/.env.example) — it's a public
// identifier, not a secret (it ships inside the frontend JS bundle), but it
// still lives in this git-ignored file because it's per-deployment config
// (a different Client ID for local dev vs. the production domain, since
// Google OAuth clients are registered against specific authorized origins).
// Leave empty to disable Google Sign-In entirely — auth_google.php 503s
// cleanly rather than accepting tokens it can't actually verify against a
// real Client ID.
define('GOOGLE_CLIENT_ID', '');

// Absolute base URL of the deployed app (no trailing slash), e.g.
// 'https://app.yourfirm.com'. Used by the scheduled plan-review email cron
// (tools/plan_review_send.php) to build the client login link. Only the cron
// needs it; leave it as-is if you don't run scheduled reviews.
define('APP_BASE_URL', 'https://horizonplan.example');

function getPdo(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
