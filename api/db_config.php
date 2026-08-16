<?php
declare(strict_types=1);

/**
 * TRACKED LOADER — safe to commit and ship. Contains no credentials.
 *
 * *** READ THIS BEFORE CHANGING ANYTHING BELOW ***
 *
 * THE PROBLEM THIS FILE EXISTS TO SOLVE. Every api/*.php and tools/*.php entry
 * point requires this exact path — `__DIR__ . '/db_config.php'` (or the
 * equivalent from a subdirectory). The obvious design is: this file holds the
 * real credentials, stays out of git, and every deploy leaves it untouched
 * because a plain `git pull` only ever updates tracked files. That was this
 * codebase's original design, and the assumption was WRONG for Hostinger.
 *
 * Hostinger's hPanel "Auto Deployment" does not do a plain `git pull` — it
 * resets/cleans the working tree on every push (equivalent to `git clean -fd`)
 * so the deployed directory exactly matches the `deploy` branch. That wipes
 * EVERY untracked file inside the git-managed directory, credentials included,
 * on every single deploy — discovered in production when the daily NAV-sync
 * cron started failing with "FAIL: api/db_config.php not found" after a
 * deploy that never touched this file directly. `chmod` does not help: file
 * permissions control who can READ a file, not whether git considers it
 * disposable. No filename or permission choice fixes this — nothing INSIDE
 * the deployed tree is safe from a reset, no matter what it's called.
 *
 * THE FIX. This file — the one path every entry point already trusts — is now
 * a tiny, secret-free LOADER that requires an ABSOLUTE PATH OUTSIDE the git
 * working directory: a location Hostinger's reset never reaches, because it
 * isn't inside the git-managed tree at all. Being tracked and secret-free,
 * THIS file is restored identically by every deploy, exactly as committed —
 * there is nothing here for a reset to destroy. The 90+ call sites across the
 * codebase needed zero changes; only this file's CONTENT changed, from "the
 * real secrets" to "a pointer to where the real secrets now live".
 *
 * Resolution order, first match wins:
 *   1. HORIZONPLAN_DB_CONFIG env var — if your hosting panel exposes per-site
 *      PHP environment variables, this is the most portable option (no path
 *      baked into a committed file at all). Not assumed to exist; if unset,
 *      falls through.
 *   2. db_config.local.php, sitting NEXT TO this file — for LOCAL DEVELOPMENT
 *      only. Git-ignored, so it never ships, and a dev machine is never
 *      subject to Hostinger's reset problem in the first place (nothing
 *      deploys to a laptop). Copy db_config.example.php to this filename to
 *      get the exact pre-this-change developer workflow back.
 *   3. The hardcoded PRODUCTION path below. This is safe to commit — it is a
 *      constant, identical on every deploy, never edited on the server. If
 *      you change hosting or the file's location, edit this ONE line, commit,
 *      and push; do not edit it directly on the server, since the next deploy
 *      would silently revert it back to whatever is in git.
 *
 * See DEPLOY.md "Database credentials" for the full setup story and the
 * SSH commands to create the external file at a new server.
 */

$candidates = array_filter([
    getenv('HORIZONPLAN_DB_CONFIG') ?: null,
    __DIR__ . '/db_config.local.php',
    // Production, confirmed over SSH (2026-08). EDIT via a commit, never on
    // the server directly — see the header above for why.
    '/home/u168175300/domains/the2ndinning.com/db_config.php',
]);

$resolved = null;
foreach ($candidates as $path) {
    if (is_file($path)) {
        $resolved = $path;
        break;
    }
}

if ($resolved === null) {
    $checked = implode("\n  - ", $candidates);
    $detail = "No database credentials file found. Checked:\n  - {$checked}\n"
        . "See DEPLOY.md \"Database credentials\" for setup.";

    // CLI (tools/*.php crons) never installs the web JSON-error handler —
    // it isn't a web request, so there is nothing to install it for. Match
    // the exact style tools/*.php already uses for its own guard clauses.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "FAIL: {$detail}\n");
        exit(1);
    }

    // Web context. Handled explicitly here rather than left to fall through
    // to a raw fatal: this file loads before most endpoints install their own
    // JSON error handler (security_gatekeeper.php requires THIS file), so a
    // silent failure here would be the one path in the app that could still
    // produce a blank page instead of the structured 500 every other error
    // in this codebase guarantees.
    error_log('[HorizonPlan] ' . str_replace("\n", ' | ', $detail));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server not configured: database credentials file missing.',
    ]);
    exit();
}

require_once $resolved;
