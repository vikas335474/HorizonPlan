<?php
declare(strict_types=1);

/**
 * CLI entry point for the periodic sourced tax-treatment sync (docs/12
 * Prompt D-2). CLI ONLY, same convention as tools/reference_costs_sync.php
 * and tools/mf_nav_sync.php — see api/lib/TaxReference.php for the actual
 * logic and the curated dataset (kept in api/lib/ rather than here, same
 * reasoning as every other sync tool's own split: .github/workflows/deploy.yml
 * never ships tools/ to production).
 *
 * No live source to fetch from — there is no single live API for Finance
 * Act / CBDT-style tax rules. The dataset lives in code, curated and cited,
 * and this job's only real work is upserting it into the tax_reference
 * cache the app actually reads from. Re-running this after editing the
 * dataset in TaxReference.php (a rate correction after a new budget, or
 * is_verified flipped by hand in the DB after a human checks a row) is how
 * an update reaches the app.
 *
 * Intended to run periodically via Hostinger's hPanel → Advanced → Cron Jobs
 * — see DEPLOY.md's "Sourced tax-reference sync" section. Given how
 * infrequently Indian tax rules change (essentially only at Union Budget
 * time), this needs no fixed schedule at all beyond "run it by hand after
 * editing the dataset" — a cron entry is optional, unlike the daily NAV sync.
 *
 *   php tools/tax_reference_sync.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this tool runs from the command line only.\n");
}

// api/db_config.php always exists now — it's a tracked loader, not a
// git-ignored file a server setup step might have skipped. It owns the
// "credentials genuinely missing" diagnostic itself (see its header); no
// existence check is needed here anymore.
require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TaxReference.php';

$db = getPdo();

// The transaction boundary lives here, not in syncTaxReference() itself —
// see api/lib/TaxReference.php's class-level note. A mid-run DB error rolls
// back the whole batch, leaving the existing cache exactly as it was rather
// than half-written.
$db->beginTransaction();
try {
    $result = syncTaxReference($db);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Tax-reference sync: FAILED — left the existing tax_reference cache untouched.\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Tax-reference sync: done.\n";
echo "  Rows written: {$result['rows_written']}\n";
echo "  Rows pruned (no longer in the dataset): {$result['rows_pruned']}\n";
