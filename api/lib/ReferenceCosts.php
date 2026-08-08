<?php
declare(strict_types=1);

/**
 * The sourced reference-cost library (docs/11 Prompt E-3). Same three-layer
 * split as MfNavSync.php:
 *   1. referenceCostDataset() — pure, no DB/network. The curated dataset
 *      itself: cited cost ranges by DRIVER, not aspiration (docs/11 §3).
 *      Fully unit-testable with no fixture needed — it IS the fixture.
 *   2. syncReferenceCosts() — DB-touching, upserts the dataset into
 *      reference_costs (sql/036). Deliberately does NOT manage its own
 *      transaction — same convention as MfNavSync.php's
 *      applyParsedNavToCache(), caller owns the transaction boundary:
 *      tools/reference_costs_sync.php wraps the call in one so a mid-run
 *      failure rolls back the whole batch rather than leaving a half-written
 *      cache, and a test can wrap the same call in its own rolled-back
 *      transaction without hitting a nested-transaction error.
 *   3. getReferenceCost() / listReferenceCosts() — plain reads, used by
 *      api/reference_costs_get.php.
 *
 * *** Every row here is is_verified = 0 on first sync. *** These are
 * training-knowledge approximations of the kind of figure AICTE / NMC /
 * MOSPI / the 7th Pay Commission publish, not fetched from a live source in
 * this session — no runtime or CLI-session internet access (docs/11 §3,
 * CLAUDE.md's outbound-proxy note). Same disclosure as market_history's own
 * seed (sql/015). A human must verify each row against its named source and
 * flip is_verified before a real client meeting leans on the exact number.
 *
 * Costs are ranges with a driver, never a point estimate (docs/11 principle
 * 4: "suggestions anchor, hard"). City tier adjusts an EXPENSE multiplier
 * only, never inflation (docs/11 §3 — a load-bearing, non-obvious call).
 *
 * @return list<array{category:string,subcategory:string,label:string,unit:string,low:float,high:float,source_name:string,source_url:?string,as_of_date:string}>
 */
function referenceCostDataset(): array
{
    $asOf = '2025-01-01';

    return [
        // ── Education: government vs. private is the ~20x driver (docs/11 §3) ──
        [
            'category' => 'education', 'subcategory' => 'engineering_government',
            'label' => 'Government / state-quota engineering degree (total, ~4 years)',
            'unit' => 'inr_total', 'low' => 150000.0, 'high' => 400000.0,
            'source_name' => 'AICTE / state counselling-authority fee disclosures (illustrative — verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
        [
            'category' => 'education', 'subcategory' => 'engineering_private',
            'label' => 'Private engineering degree (total, ~4 years)',
            'unit' => 'inr_total', 'low' => 800000.0, 'high' => 1600000.0,
            'source_name' => 'AICTE / institute fee disclosures (illustrative — verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
        [
            'category' => 'education', 'subcategory' => 'medical_government',
            'label' => 'Government MBBS seat (total, ~5.5 years)',
            'unit' => 'inr_total', 'low' => 100000.0, 'high' => 500000.0,
            'source_name' => 'NMC / state counselling-authority fee disclosures (illustrative — verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
        [
            'category' => 'education', 'subcategory' => 'medical_private',
            'label' => 'Private MBBS seat (total, ~5.5 years)',
            'unit' => 'inr_total', 'low' => 6000000.0, 'high' => 12000000.0,
            'source_name' => 'NMC / state counselling-authority fee disclosures (illustrative — verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],

        // ── Healthcare: financial consequence, not a diagnosis (docs/11 §5) ──
        [
            'category' => 'healthcare', 'subcategory' => 'ongoing_medical_annual',
            'label' => 'Ongoing medical cost for a household member with a chronic condition (annual, out-of-pocket)',
            'unit' => 'inr_annual', 'low' => 30000.0, 'high' => 150000.0,
            'source_name' => 'Illustrative range — no single published index; verify against IRDAI health-claims data before relying on it',
            'source_url' => null, 'as_of_date' => $asOf,
        ],

        // ── City tier: EXPENSE multiplier only, never inflation (docs/11 §3) ──
        [
            'category' => 'city_expense_multiplier', 'subcategory' => 'city_tier_x',
            'label' => 'Tier X (metro) city — household expense multiplier vs. national baseline',
            'unit' => 'multiplier', 'low' => 1.20, 'high' => 1.40,
            'source_name' => '7th Pay Commission HRA city classification (illustrative multiplier — no official Indian cost-of-living index exists; verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
        [
            'category' => 'city_expense_multiplier', 'subcategory' => 'city_tier_y',
            'label' => 'Tier Y city — household expense multiplier vs. national baseline',
            'unit' => 'multiplier', 'low' => 0.90, 'high' => 1.05,
            'source_name' => '7th Pay Commission HRA city classification (illustrative multiplier — no official Indian cost-of-living index exists; verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
        [
            'category' => 'city_expense_multiplier', 'subcategory' => 'city_tier_z',
            'label' => 'Tier Z city — household expense multiplier vs. national baseline',
            'unit' => 'multiplier', 'low' => 0.70, 'high' => 0.85,
            'source_name' => '7th Pay Commission HRA city classification (illustrative multiplier — no official Indian cost-of-living index exists; verify before relying on it)',
            'source_url' => null, 'as_of_date' => $asOf,
        ],
    ];
}

/**
 * Upserts the curated dataset into reference_costs. Does NOT begin/commit its
 * own transaction — see the class-level note above; the caller decides the
 * transaction boundary. is_verified is deliberately NOT touched on an
 * existing row (see ON DUPLICATE KEY UPDATE below): once a human has verified
 * a row against its source, a later dataset sync must not silently flip it
 * back to unverified — that would be the same class of bug the docs/09
 * mandatory-MFA default-flip session was written to catch, applied here
 * instead of there.
 *
 * @return array{rows_written:int}
 */
function syncReferenceCosts(PDO $db): array
{
    $dataset = referenceCostDataset();

    $upsert = $db->prepare(
        'INSERT INTO reference_costs
            (category, subcategory, label, unit, low, high, source_name, source_url, as_of_date, is_verified, fetched_at)
         VALUES
            (:category, :subcategory, :label, :unit, :low, :high, :source_name, :source_url, :as_of_date, 0, :fetched_at)
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
            ':fetched_at' => $now,
        ]);
    }

    return ['rows_written' => count($dataset)];
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
