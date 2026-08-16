<?php
declare(strict_types=1);

/**
 * Pure resolution logic for "where is the real db_config.php credentials
 * file", shared by api/db_config.php (the loader — needs this to decide
 * whether to require the file or fail loudly) and every tests/*_db.php file's
 * OWN pre-flight check, whose job is the opposite: skip QUIETLY when nothing
 * is configured, because an unconfigured local dev DB is a normal, expected
 * state for a fresh checkout, not a failure.
 *
 * WHY THIS IS ITS OWN FILE. Before api/db_config.php became a loader, every
 * DB-integration test carried its own `is_file(__DIR__ . '/../api/db_config.php')`
 * pre-flight check, and that was a correct way to answer "is a dev DB
 * configured?" — the file existed only when a developer had actually copied
 * db_config.example.php over it. Once api/db_config.php became a permanent,
 * always-present loader, that check silently stopped meaning anything (it's
 * always true now), and every one of those tests' graceful SKIP turned into a
 * hard FAIL the moment credentials genuinely weren't configured — the loader
 * itself caught the real problem instead, but with production's loud-failure
 * behavior, wrong for a test that's supposed to shrug and move on.
 *
 * Rather than give each test its own copy of the new resolution logic (and
 * risk it drifting from what the loader actually does — the exact bug this
 * function exists to prevent, and the same reasoning tools/*.php's now-removed
 * duplicate guards were cleaned up for), both sides call this ONE function.
 *
 * NO SIDE EFFECTS. Never exits, prints, or throws — just answers the
 * question, so each caller decides its own correct response.
 */

/**
 * Every path checked, in priority order. Exposed separately from
 * resolveDbConfigPath() so a caller building a diagnostic message (what did
 * we actually check?) doesn't have to re-derive the list itself.
 *
 * @return list<string>
 */
function dbConfigCandidates(): array
{
    return array_values(array_filter([
        getenv('HORIZONPLAN_DB_CONFIG') ?: null,
        __DIR__ . '/../db_config.local.php',
        // Production, confirmed over SSH (2026-08). EDIT via a commit, never
        // on the server directly — see api/db_config.php's header for why.
        '/home/u168175300/domains/the2ndinning.com/db_config.php',
    ]));
}

/** The first candidate that actually exists, or null if none do. */
function resolveDbConfigPath(): ?string
{
    foreach (dbConfigCandidates() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}
