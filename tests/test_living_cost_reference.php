<?php
declare(strict_types=1);

// Pure tests for the living-cost anchor (MOSPI HCES) behind the
// retirement-spend estimate.
//
// THE RULE THIS FILE PROTECTS. MPCE is average per-capita CONSUMPTION, not
// "what a comfortable retirement costs". Every guard below exists because the
// failure mode is one-directional and harmful: an anchor that reads low, or
// that quietly substitutes a national average for a local one, understates
// someone's retirement target. Understating is the one error this app must not
// make quietly.
//
// Pure: no DB. livingCostAnchor() takes an already-loaded cache map, the same
// split PortfolioTaxContext.php uses.

require_once __DIR__ . '/../api/lib/LivingCostReference.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- the dataset itself -----------------------------------------------------
$dataset = livingCostDataset();
assertTrue($dataset !== [], 'the dataset is not empty');

$bySub = [];
foreach ($dataset as $row) {
    $bySub[$row['subcategory']] = $row;
}

assertTrue(
    isset($bySub['all_india_rural'], $bySub['all_india_urban']),
    'the all-India fallback rows exist — everything else depends on them'
);

foreach ($dataset as $row) {
    assertTrue($row['category'] === 'living_cost', "{$row['subcategory']}: category is living_cost");
    assertTrue($row['unit'] === 'inr_monthly_per_capita', "{$row['subcategory']}: unit names the per-capita basis");
    assertTrue($row['low'] > 0.0, "{$row['subcategory']}: a positive figure");
    assertTrue(
        $row['low'] === $row['high'],
        "{$row['subcategory']}: low == high — HCES publishes a point average, and inventing a spread would be fabrication"
    );
    assertTrue(
        $row['is_verified'] === false,
        "{$row['subcategory']}: seeded UNVERIFIED — these are transcribed, not fetched from a live source"
    );
    assertTrue(
        $row['source_url'] !== null && str_contains($row['source_url'], 'mospi.gov.in'),
        "{$row['subcategory']}: cites the MOSPI fact sheet, never 'the internet'"
    );
}

// A published pair we know, as a transcription check against the fact sheet.
assertTrue($bySub['all_india_rural']['low'] === 4122.0, 'all-India rural MPCE is the published 4,122');
assertTrue($bySub['all_india_urban']['low'] === 6996.0, 'all-India urban MPCE is the published 6,996');

// Urban is above rural wherever both are published — a sanity check on the
// transcription, since swapping the two would bias every anchor the wrong way.
foreach (livingCostByState() as $key => $state) {
    if ($state['rural'] !== null && $state['urban'] !== null) {
        assertTrue(
            $state['urban'] > $state['rural'],
            "{$key}: urban MPCE exceeds rural, as published (a swap here would understate urban plans)"
        );
    }
}

// --- resolution and the fallback -------------------------------------------
$cache = [];
foreach ($dataset as $row) {
    $cache[$row['subcategory']] = [
        'low' => $row['low'], 'high' => $row['high'], 'is_verified' => $row['is_verified'],
    ];
}

$tn = livingCostAnchor($cache, 'tamil_nadu', 'urban');
assertTrue($tn !== null && $tn['per_capita'] === 8165.0, "a state we hold resolves to its OWN figure");
assertTrue($tn['is_fallback'] === false, 'and is not marked a fallback');

// A state genuinely absent from the partial dataset.
$bihar = livingCostAnchor($cache, 'bihar', 'urban');
assertTrue($bihar !== null, 'an unlisted state still resolves — via all-India');
assertTrue($bihar['per_capita'] === 6996.0, 'and gets the all-India urban figure');
assertTrue(
    $bihar['is_fallback'] === true,
    'THE FALLBACK IS REPORTED, never silent — a national average shown as if it were local is a figure that looks personalised and is not'
);

// A state we hold for one sector only must fall back for the other, not
// cross-read the sector we do have.
$keralaUrban = livingCostAnchor($cache, 'kerala', 'urban');
assertTrue(
    $keralaUrban['is_fallback'] === true && $keralaUrban['per_capita'] === 6996.0,
    "Kerala has only a rural figure, so its urban anchor falls back rather than reusing the rural one"
);
$keralaRural = livingCostAnchor($cache, 'kerala', 'rural');
assertTrue(
    $keralaRural['is_fallback'] === false && $keralaRural['per_capita'] === 6611.0,
    'while its rural anchor is the real published figure'
);

assertTrue(livingCostAnchor($cache, null, 'urban')['is_fallback'] === true, 'no state given -> all-India, flagged');
assertTrue(livingCostAnchor($cache, 'tamil_nadu', 'suburban') === null, 'an unknown sector resolves to null, never a guess');
assertTrue(livingCostAnchor([], 'tamil_nadu', 'urban') === null, 'an empty cache resolves to null rather than inventing a figure');

assertTrue(
    livingCostAnchor($cache, 'tamil_nadu', 'urban')['is_verified'] === false,
    'the unverified flag survives resolution, so the UI can keep saying so'
);

// --- per-capita to household ------------------------------------------------
// The step that matters most: MPCE is PER PERSON, and showing it against a
// household budget without scaling understates by the household size.
$one = livingCostForHousehold(6996.0, 1);
assertTrue($one['monthly_household'] === 6996.0, 'a household of one is the per-capita figure');

$four = livingCostForHousehold(6996.0, 4);
assertTrue($four['monthly_household'] === 27984.0, 'a household of four is four times the per-capita figure');
assertTrue($four['per_capita'] === 6996.0, 'and the per-capita figure is still reported alongside it');
assertTrue($four['household_size'] === 4, 'as is the size used, so the UI can state the arithmetic');

assertTrue(livingCostForHousehold(6996.0, 0)['household_size'] === 1, 'a size of 0 clamps to 1, never a zero monthly figure');
assertTrue(livingCostForHousehold(6996.0, -3)['household_size'] === 1, 'a negative size clamps to 1');
assertTrue(
    livingCostForHousehold(6996.0, 0)['monthly_household'] > 0.0,
    'so a bad household size can never silently flatter a plan with a zero spend'
);

echo "\nAll living-cost reference tests passed.\n";
