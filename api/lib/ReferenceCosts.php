<?php
declare(strict_types=1);

/**
 * The sourced reference-cost library (docs/11 Prompt E-3). Same three-layer
 * split as MfNavSync.php:
 *   1. referenceCostDataset() — pure, no DB/network. The curated dataset
 *      itself: cited cost ranges by DRIVER, not aspiration (docs/11 §3).
 *      Fully unit-testable with no fixture needed — it IS the fixture.
 *   2. syncReferenceCosts() — DB-touching, upserts the dataset into
 *      reference_costs (sql/037), then prunes any cached row the dataset no
 *      longer defines. Deliberately does NOT manage its own transaction —
 *      same convention as MfNavSync.php's applyParsedNavToCache(), caller
 *      owns the transaction boundary: tools/reference_costs_sync.php wraps
 *      the call in one so a mid-run failure rolls back the whole batch
 *      rather than leaving a half-written cache, and a test can wrap the
 *      same call in its own rolled-back transaction without hitting a
 *      nested-transaction error.
 *   3. getReferenceCost() / listReferenceCosts() — plain reads, used by
 *      api/reference_costs_get.php and PersonalisationReference.php's
 *      cache-first education lookup (see the RECONCILIATION note below).
 *
 * RECONCILIATION WITH docs/11 Prompt E-2 (api/lib/PersonalisationReference.php).
 * E-2 shipped in a parallel session with its own hand-authored education
 * cost ranges, keyed by the same driver concept this table meant to cache —
 * but at first pass this dataset used course-specific keys
 * (engineering_government/engineering_private/medical_government/
 * medical_private) while E-2's `client_dependants.cost_driver` enum is
 * generic (government/private/overseas, docs/11 §4 Prompt E-2 scope: the
 * question asked is the driver, never the course). Reconciled by widening
 * this dataset's education subcategories to that same generic enum —
 * course-level granularity was never asked for anywhere in the app, so it
 * was dead weight, not a feature. The values below are
 * PersonalisationReference's own already-live, already-tested figures
 * (unchanged, so this reconciliation is not a behavior change for anyone
 * using the self-serve queue today) — this file is now the SOURCE for those
 * figures (see PersonalisationReference::educationRangeForDriver()'s
 * cache-first read), not a second, drifting copy of them.
 *
 * City tier deliberately stays OUT of this table. PersonalisationReference's
 * multiplier is an exact derivation from a published 7th-CPC HRA percentage
 * (X=1.00 baseline by construction, Y/Z below it) — not an uncertain
 * estimate, so it needs no range and no cache; a "sourced range" table is
 * the wrong shape for a precise formula, and an earlier draft of this
 * dataset that cached city-tier multipliers used a different, incompatible
 * baseline (relative to a "national average" rather than tier X) that was
 * never actually wired to anything. Rather than reconcile two baselines,
 * the unused/incompatible copy was removed — see docs/11 §6.
 *
 * *** Every row here defaults to is_verified = 0 UNLESS the dataset says
 * otherwise. *** Most of these are training-knowledge approximations of the
 * kind of figure AICTE / NMC / MOSPI / the 7th Pay Commission publish, not
 * fetched from a live source in this session — no runtime or CLI-session
 * internet access (docs/11 §3, CLAUDE.md's outbound-proxy note). Same
 * disclosure as market_history's own seed (sql/015). The education rows are
 * the one exception: they carry the is_verified value E-2's own file
 * already asserted (true for government/private, false for the overseas
 * bucket, which has no single Indian regulator source) — reconciling data
 * that already went through review shouldn't discard that review. A human
 * must still verify any is_verified=0 row against its named source before a
 * real client meeting leans on the exact number. A re-sync never touches
 * is_verified on an EXISTING row either way (see ON DUPLICATE KEY UPDATE
 * below) — only a first insert sets it.
 *
 * Costs are ranges with a driver, never a point estimate (docs/11 principle
 * 4: "suggestions anchor, hard").
 *
 * @return list<array{category:string,subcategory:string,label:string,unit:string,low:float,high:float,source_name:string,source_url:?string,as_of_date:string,is_verified:bool}>
 */
function referenceCostDataset(): array
{
    $asOf = '2025-01-01';

    return [
        // ── Education: the cost DRIVER, not the course (docs/11 §3/§4 E-2) —
        // same three buckets and figures as PersonalisationReference's own
        // EDUCATION_COST_RANGES, reconciled here as the single source (see
        // this file's header note). Government vs. private is the driver
        // that actually moves the number; overseas has no single Indian
        // regulator source and stays flagged unverified.
        [
            'category' => 'education', 'subcategory' => 'government',
            'label' => 'Government seat (total course cost)',
            'unit' => 'inr_total', 'low' => 300000.0, 'high' => 1000000.0,
            'source_name' => 'AICTE / NMC / state-counselling government-seat fee disclosures',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => true,
        ],
        [
            'category' => 'education', 'subcategory' => 'private',
            'label' => 'Private institute (total course cost)',
            'unit' => 'inr_total', 'low' => 1500000.0, 'high' => 4000000.0,
            'source_name' => 'AICTE / state-counselling private-seat fee disclosures',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => true,
        ],
        [
            'category' => 'education', 'subcategory' => 'overseas',
            'label' => 'Overseas (total course + living cost)',
            'unit' => 'inr_total', 'low' => 5000000.0, 'high' => 12000000.0,
            'source_name' => 'Illustrative overseas course + living-cost range — no single Indian regulator source',
            'source_url' => null, 'as_of_date' => '2025-01-01', 'is_verified' => false,
        ],

        // ── Healthcare: financial consequence, not a diagnosis (docs/11 §5) ──
        [
            'category' => 'healthcare', 'subcategory' => 'ongoing_medical_annual',
            'label' => 'Ongoing medical cost for a household member with a chronic condition (annual, out-of-pocket)',
            'unit' => 'inr_annual', 'low' => 30000.0, 'high' => 150000.0,
            'source_name' => 'Illustrative range — no single published index; verify against IRDAI health-claims data before relying on it',
            'source_url' => null, 'as_of_date' => $asOf, 'is_verified' => false,
        ],
    ];
}

/**
 * Upserts the curated dataset into reference_costs, then PRUNES any cached
 * row whose (category, subcategory) the dataset no longer defines — this
 * table is entirely CLI-cron-owned (no user data ever lands in it), so
 * unlike mf_nav_cache it's safe and correct to hard-sync rather than only
 * ever grow: an orphaned row from a dataset that has since dropped or
 * renamed a subcategory (see this file's header note on the E-2
 * reconciliation) would otherwise sit there silently, still returned by
 * listReferenceCosts(), misrepresenting what's actually current.
 *
 * Does NOT begin/commit its own transaction — see the class-level note
 * above; the caller decides the transaction boundary. is_verified is set
 * from the dataset ONLY on a first insert; an existing row's is_verified is
 * deliberately NOT touched by a re-sync (see ON DUPLICATE KEY UPDATE below):
 * once a human has verified a row against its source, a later dataset sync
 * must not silently flip it back to unverified — that would be the same
 * class of bug the docs/09 mandatory-MFA default-flip session was written to
 * catch, applied here instead of there.
 *
 * @return array{rows_written:int,rows_pruned:int}
 */
function syncReferenceCosts(PDO $db): array
{
    $dataset = referenceCostDataset();

    $upsert = $db->prepare(
        'INSERT INTO reference_costs
            (category, subcategory, label, unit, low, high, source_name, source_url, as_of_date, is_verified, fetched_at)
         VALUES
            (:category, :subcategory, :label, :unit, :low, :high, :source_name, :source_url, :as_of_date, :is_verified, :fetched_at)
         ON DUPLICATE KEY UPDATE
            label       = VALUES(label),
            unit        = VALUES(unit),
            low         = VALUES(low),
            high        = VALUES(high),
            source_name = VALUES(source_name),
            source_url  = VALUES(source_url),
            as_of_date  = VALUES(as_of_date),
            fetched_at  = VALUES(fetched_at)'
    );

    $now = date('Y-m-d H:i:s');
    $keep = [];
    foreach ($dataset as $row) {
        $upsert->execute([
            ':category' => $row['category'],
            ':subcategory' => $row['subcategory'],
            ':label' => $row['label'],
            ':unit' => $row['unit'],
            ':low' => $row['low'],
            ':high' => $row['high'],
            ':source_name' => $row['source_name'],
            ':source_url' => $row['source_url'],
            ':as_of_date' => $row['as_of_date'],
            ':is_verified' => $row['is_verified'] ? 1 : 0,
            ':fetched_at' => $now,
        ]);
        $keep[] = $row['category'] . '|' . $row['subcategory'];
    }

    $pruned = 0;
    $existing = $db->query('SELECT id, category, subcategory FROM reference_costs')->fetchAll();
    $deleteStmt = $db->prepare('DELETE FROM reference_costs WHERE id = :id');
    foreach ($existing as $row) {
        if (!in_array($row['category'] . '|' . $row['subcategory'], $keep, true)) {
            $deleteStmt->execute([':id' => $row['id']]);
            $pruned++;
        }
    }

    return ['rows_written' => count($dataset), 'rows_pruned' => $pruned];
}

/**
 * Single-row read for the opt-in "shall I look this up for you?" prompt.
 * Returns null if the category/subcategory combination isn't cached yet
 * (e.g. the sync has never run) — the caller must treat that as "nothing to
 * suggest," never fabricate a range.
 *
 * @return ?array<string,mixed>
 */
function getReferenceCost(PDO $db, string $category, string $subcategory): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM reference_costs WHERE category = :category AND subcategory = :subcategory'
    );
    $stmt->execute([':category' => $category, ':subcategory' => $subcategory]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * All cached rows, optionally filtered to one category — the listing an
 * admin/debug view or a broader picker UI would use.
 *
 * @return list<array<string,mixed>>
 */
function listReferenceCosts(PDO $db, ?string $category = null): array
{
    if ($category !== null) {
        $stmt = $db->prepare('SELECT * FROM reference_costs WHERE category = :category ORDER BY subcategory ASC');
        $stmt->execute([':category' => $category]);
    } else {
        $stmt = $db->query('SELECT * FROM reference_costs ORDER BY category ASC, subcategory ASC');
    }
    return $stmt->fetchAll();
}

/**
 * Shapes one DB row for API output — typed fields, is_verified as a real
 * bool. Shared by reference_costs_get.php so the endpoint carries no logic
 * of its own beyond the HTTP plumbing.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function formatReferenceCostRow(array $row): array
{
    return [
        'category'    => $row['category'],
        'subcategory' => $row['subcategory'],
        'label'       => $row['label'],
        'unit'        => $row['unit'],
        'low'         => (float) $row['low'],
        'high'        => (float) $row['high'],
        'source_name' => $row['source_name'],
        'source_url'  => $row['source_url'],
        'as_of_date'  => $row['as_of_date'],
        'is_verified' => (bool) $row['is_verified'],
    ];
}
