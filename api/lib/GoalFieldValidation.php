<?php
declare(strict_types=1);

// docs/09 Pre-Launch Hardening, Session 1: per-field type/range validation for
// base_plans fields, shared between goals_create.php and goals_update.php so
// the same rule can't drift between the two entry points to this data.
//
// Every check here operates on a single already-present value — the caller
// (goals_create.php / goals_update.php) decides whether a field is present at
// all (create requires some, update is a partial-update endpoint and must
// only validate what's actually being changed). Nothing here duplicates the
// existing cross-field invariants (retirement_age > current_age, corpus
// both-or-neither/sum-to-initial_net_worth) — those already live in the two
// endpoints and stay there, since they need "effective value" reasoning across
// multiple fields that a single-field validator can't express.
//
// Rate fields floor at 0, not a negative stress-test floor: docs/02's
// sequence-of-returns stress test (Section 4.3) constructs its below/above-
// average synthetic sequence *inside* PlanMath from a single stored average
// rate — it never needs the stored assumption itself to be negative. No doc
// documents an intent to store a negative return/inflation/withdrawal
// assumption, so 0-100 is the correct floor per docs/09's own instruction to
// check before assuming a negative floor is wanted.

/**
 * Validate one field's value against its known range/type rule.
 *
 * @return string|null an error message if invalid, null if the value passes
 */
function validateGoalField(string $field, mixed $value): ?string
{
    switch ($field) {
        case 'initial_net_worth':
            return validatePositiveAmount($value, $field, 10_000_000_000);

        case 'target_amount':
            if ($value === null) {
                return null; // optional field
            }
            return validatePositiveAmount($value, $field, 10_000_000_000);

        case 'inflation_rate':
        case 'withdrawal_rate':
        case 'drawdown_return_rate':
        case 'accumulation_return_rate':
        case 'locked_return_rate':
            if ($value === null) {
                return null; // optional for non-retirement goals / not-yet-set accumulation fields
            }
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                return "$field must be between 0 and 100.";
            }
            return null;

        case 'liquid_corpus_amount':
        case 'locked_corpus_amount':
            if ($value === null) {
                return null; // both-or-neither is enforced by the caller, not here
            }
            if (!is_numeric($value) || (float) $value < 0) {
                return "$field must be zero or greater.";
            }
            return null;

        case 'current_age':
        case 'retirement_age':
            if ($value === null) {
                return null;
            }
            if (!is_numeric($value) || (int) $value != $value || (int) $value < 18 || (int) $value > 100) {
                return "$field must be a whole number between 18 and 100.";
            }
            return null;

        case 'target_date':
            if ($value === null || $value === '') {
                return null; // optional field
            }
            $parsed = DateTime::createFromFormat('Y-m-d', (string) $value);
            if (!$parsed || $parsed->format('Y-m-d') !== $value) {
                return 'target_date must be a valid date in YYYY-MM-DD format.';
            }
            // No "must be in the future" constraint: a goal tracking a past
            // target (e.g. one already reached, kept for historical record)
            // is a legitimate use case, not a data-entry error to reject.
            return null;

        default:
            return null; // no range rule for this field (e.g. goal_label, monthly_sip_amount, sip_step_up_rate)
    }
}

function validatePositiveAmount(mixed $value, string $field, float $ceiling): ?string
{
    if (!is_numeric($value) || (float) $value <= 0) {
        return "$field must be greater than 0.";
    }
    if ((float) $value > $ceiling) {
        return "$field must not exceed $ceiling.";
    }
    return null;
}

/**
 * Validate every field present in $values (field => value). Returns the
 * first error found, or null if every present field passes. Fields with no
 * rule in validateGoalField() (or absent from $values entirely) are ignored.
 *
 * @param array<string,mixed> $values
 */
function validateGoalFields(array $values): ?string
{
    foreach ($values as $field => $value) {
        $error = validateGoalField($field, $value);
        if ($error !== null) {
            return $error;
        }
    }
    return null;
}
