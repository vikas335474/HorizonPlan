<?php
declare(strict_types=1);

/**
 * Shared validation for strategy-template fields, used by templates_create.php,
 * templates_fork.php, and templates_customize.php so the allocation/risk-profile
 * rules can't drift between the three entry points that accept them.
 */

const TEMPLATE_RISK_PROFILES = ['conservative', 'moderate', 'balanced', 'aggressive'];

/**
 * Validates an asset-allocation map (e.g. {"equity": 60, "debt": 30, "gold": 10}):
 * every value numeric, non-negative, and the whole thing sums to ~100.
 * Returns an error message string, or null if valid.
 */
function validateAllocationJson($allocation): ?string
{
    if (!is_array($allocation) || empty($allocation)) {
        return 'allocation_json must be a non-empty object of asset => percentage.';
    }

    $sum = 0.0;
    foreach ($allocation as $asset => $pct) {
        if (!is_string($asset) || $asset === '') {
            return 'allocation_json keys must be non-empty asset names.';
        }
        if (!is_numeric($pct) || (float) $pct < 0) {
            return "allocation_json value for '$asset' must be a non-negative number.";
        }
        $sum += (float) $pct;
    }

    if (abs($sum - 100.0) > 0.5) {
        return "allocation_json percentages must sum to 100 (got {$sum}).";
    }

    return null;
}

/**
 * Returns an error message string, or null if valid. NULL/absent risk profile
 * is allowed (optional field) — pass only a non-null value here.
 */
function validateRiskProfile(string $riskProfile): ?string
{
    if (!in_array($riskProfile, TEMPLATE_RISK_PROFILES, true)) {
        return 'risk_profile_enum must be one of: ' . implode(', ', TEMPLATE_RISK_PROFILES) . '.';
    }
    return null;
}
