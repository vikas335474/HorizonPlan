<?php
declare(strict_types=1);

/**
 * CLI entry point for the periodic sourced-reference-cost sync (docs/11
 * Prompt E-3). CLI ONLY, same convention as tools/mf_nav_sync.php and
 * tools/bootstrap_admin.php — see api/lib/ReferenceCosts.php for the actual
 * logic and the curated dataset (kept in api/lib/ rather than here, same
 * reasoning as MfNavSync.php's own split: .github/workflows/deploy.yml never
 * ships tools/ to production).
 *
 * Unlike the NAV sync, this doesn't call out to a live source at runtime —
 * there is no single live API for AICTE/NMC/MOSPI/7th-CPC style figures. The
 * dataset lives in code, curated and cited, and this job's job is to push it
 * into the reference_costs cache the app actually reads from — same "app
 * reads only the cache, never computes this in-request" shape as the NAV
 * sync, just with a curated source instead of a fetched one. Re-running this
 * after editing the dataset in ReferenceCosts.php is how a verified figure
 * (is_verified flipped by hand in the DB, or the dataset updated with a
 * freshly-checked range) reaches the app.
 *
 * Intended to run periodically via Hostinger's hPanel → Advanced → Cron Jobs
 * — see DEPLOY.md's "Sourced reference-cost library sync" section:
 *
 *   php tools/reference_costs_sync.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this tool runs from the command line only.\n");
}

$configPath = __DIR__ . '/../api/db_config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "FAIL: api/db_config.php not found.\n");
    exit(2);
}
require_once $configPath;
require_once __DIR__ . '/../api/lib/ReferenceCosts.php';

$db = getPdo();

// The transaction boundary lives here, not in syncReferenceCosts() itself —
// see api/lib/ReferenceCosts.php's class-level note. A mid-run DB error rolls
// back the whole batch, leaving the existing cache exactly as it was rather
// than half-written.
$db->beginTransaction();
try {
    $result = syncReferenceCosts($db);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Reference-cost sync: FAILED — left the existing reference_costs cache untouched.\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Reference-cost sync: done.\n";
echo "  Rows written: {$result['rows_written']}\n";
