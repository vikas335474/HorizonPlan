<?php
declare(strict_types=1);

/**
 * docs/09 Pre-Launch Hardening Session 1 — GoalFieldValidation.php coverage.
 *
 * validateGoalField()/validateGoalFields() are pure functions (no DB access),
 * so the boundary-value matrix below runs unconditionally, same precedent as
 * test_google_auth_validation.php. A second, DB-integration section (self-
 * skipping like every other _db.php test in this suite) confirms a real
 * fixture goal's own stored values still pass validation and that inserting
 * one via TenantScopedDb — exercising the same insert path goals_create.php
 * uses — behaves as expected end to end.
 *
 * Non-destructive: the DB section wraps its fixture tenant/user/goal in a
 * transaction, rolled back at the end — same pattern as
 * test_platform_settings.php / test_demo_access_db.php, not the older
 * blanket-DELETE pattern some sibling _db.php tests still use.
 */

require_once __DIR__ . '/../api/lib/GoalFieldValidation.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}
function skip(string $why): void
{
    echo "SKIP: $why\n";
    exit(0);
}

// --- pure boundary-value matrix ---------------------------------------------

// initial_net_worth: >= 0, <= 10,000,000,000
//
// 0 was rejected until the self-serve individual tier (sql/033) made the
// "nothing saved for this yet" case ordinary: a brand-new education or home
// goal usually starts at zero, and so does a young person's first retirement
// plan funded entirely by a monthly SIP. Rejecting it forced callers to invent
// a figure or skip the goal. Negatives are still rejected — a goal cannot
// start with less than nothing.
//
// The safety consequence is handled in PlanMath::readinessScoreForGoal(),
// which returns null rather than a score for a zero corpus: withdrawing a
// percentage of nothing survives every year and would otherwise score ~89/100,
// telling someone who has not started saving that they are in great shape.
assertTrue(validateGoalField('initial_net_worth', 0) === null, 'initial_net_worth = 0 is ACCEPTED — a goal you have not started saving for is a real state');
assertTrue(validateGoalField('initial_net_worth', -1) !== null, 'initial_net_worth < 0 is rejected');
assertTrue(validateGoalField('initial_net_worth', 0.01) === null, 'initial_net_worth just above 0 passes');
assertTrue(validateGoalField('initial_net_worth', 10_000_000_000) === null, 'initial_net_worth at the ceiling passes');
assertTrue(validateGoalField('initial_net_worth', 10_000_000_000.01) !== null, 'initial_net_worth just above the ceiling is rejected');
assertTrue(validateGoalField('initial_net_worth', 'not-a-number') !== null, 'initial_net_worth must be numeric');

// target_amount: optional, > 0 if present
assertTrue(validateGoalField('target_amount', null) === null, 'target_amount omitted (null) passes — optional field');
assertTrue(validateGoalField('target_amount', 0) !== null, 'target_amount = 0 is rejected when present');
assertTrue(validateGoalField('target_amount', 500000) === null, 'a normal target_amount passes');

// rate fields: 0-100 inclusive, optional
foreach (['inflation_rate', 'withdrawal_rate', 'drawdown_return_rate', 'accumulation_return_rate', 'locked_return_rate'] as $rateField) {
    assertTrue(validateGoalField($rateField, null) === null, "$rateField omitted (null) passes — optional");
    assertTrue(validateGoalField($rateField, 0) === null, "$rateField = 0 passes (inclusive floor)");
    assertTrue(validateGoalField($rateField, 100) === null, "$rateField = 100 passes (inclusive ceiling)");
    assertTrue(validateGoalField($rateField, -0.01) !== null, "$rateField just below 0 is rejected");
    assertTrue(validateGoalField($rateField, 100.01) !== null, "$rateField just above 100 is rejected");
    assertTrue(validateGoalField($rateField, 6.5) === null, "$rateField at a normal value (6.5) passes");
}

// liquid_corpus_amount / locked_corpus_amount: optional, >= 0
foreach (['liquid_corpus_amount', 'locked_corpus_amount'] as $corpusField) {
    assertTrue(validateGoalField($corpusField, null) === null, "$corpusField omitted (null) passes — optional");
    assertTrue(validateGoalField($corpusField, 0) === null, "$corpusField = 0 passes (inclusive floor, unlike initial_net_worth)");
    assertTrue(validateGoalField($corpusField, -0.01) !== null, "$corpusField just below 0 is rejected");
}

// current_age / retirement_age: optional, whole number 18-100
foreach (['current_age', 'retirement_age'] as $ageField) {
    assertTrue(validateGoalField($ageField, null) === null, "$ageField omitted (null) passes — optional");
    assertTrue(validateGoalField($ageField, 17) !== null, "$ageField = 17 is rejected (below 18)");
    assertTrue(validateGoalField($ageField, 18) === null, "$ageField = 18 passes (inclusive floor)");
    assertTrue(validateGoalField($ageField, 100) === null, "$ageField = 100 passes (inclusive ceiling)");
    assertTrue(validateGoalField($ageField, 101) !== null, "$ageField = 101 is rejected (above 100)");
    assertTrue(validateGoalField($ageField, 35.5) !== null, "$ageField must be a whole number, not fractional");
}

// target_date: optional, valid YYYY-MM-DD, no future-only constraint
assertTrue(validateGoalField('target_date', null) === null, 'target_date omitted (null) passes — optional');
assertTrue(validateGoalField('target_date', '') === null, 'target_date empty string passes — treated as omitted');
assertTrue(validateGoalField('target_date', '2040-06-15') === null, 'a future target_date passes');
assertTrue(validateGoalField('target_date', '2020-01-01') === null, 'a past target_date passes — no future-only constraint (a completed/historical goal is legitimate)');
assertTrue(validateGoalField('target_date', '2040-13-40') !== null, 'an impossible calendar date is rejected');
assertTrue(validateGoalField('target_date', 'not-a-date') !== null, 'a non-date string is rejected');

// fields with no rule at all pass through untouched
assertTrue(validateGoalField('goal_label', 'Anything at all') === null, 'goal_label has no range rule — passes through');
assertTrue(validateGoalField('monthly_sip_amount', -5) === null, 'monthly_sip_amount has no rule in this session\'s scope — passes through untouched, per docs/09\'s own "don\'t validate fields nobody asked about"');

// validateGoalFields(): first failure wins, omitted fields (not present in the array at all) are never checked
assertTrue(validateGoalFields(['goal_label' => 'ok', 'initial_net_worth' => 100000]) === null,
    'validateGoalFields() passes when every present field is valid');
assertTrue(validateGoalFields(['initial_net_worth' => -1, 'inflation_rate' => 6]) !== null,
    'validateGoalFields() catches an invalid field among valid ones');
assertTrue(validateGoalFields(['some_unrelated_field' => 'whatever']) === null,
    'validateGoalFields() ignores fields with no rule entirely (simulates a partial update touching none of the ranged fields)');

echo "\nAll pure goal field validation tests passed.\n";

// --- DB-integration section (self-skips without a real database) -----------

$configPath = __DIR__ . '/../api/db_config.php';
if (!is_file($configPath)) {
    skip('api/db_config.php not present — no database to test the insert path against.');
}

require_once $configPath;
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';

try {
    $db = getPdo();
} catch (Throwable $e) {
    skip('database unreachable (' . $e->getMessage() . ') — cannot run integration checks.');
}

$db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES (:name, 'distribution')")
       ->execute([':name' => "Goal Validation Test $suffix"]);
    $tenantId = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, 'hash', 'client')")
       ->execute([':t' => $tenantId, ':e' => "goal-validation-client-$suffix@example.invalid"]);
    $clientId = (int) $db->lastInsertId();

    $scopedDb = new TenantScopedDb($db, $tenantId);

    // A goal built with values that pass every rule inserts cleanly through
    // the exact same TenantScopedDb::insert() path goals_create.php uses.
    $validGoal = [
        'client_id'                => $clientId,
        'goal_type'                => 'retirement',
        'goal_label'               => 'Validation Fixture Goal',
        'initial_net_worth'        => 5_000_000.00,
        'inflation_rate'           => 6.00,
        'withdrawal_rate'          => 3.50,
        'drawdown_return_rate'     => 7.00,
        'projection_horizon_years' => 30,
        'current_age'              => 40,
        'retirement_age'           => 60,
    ];
    assertTrue(validateGoalFields($validGoal) === null, 'a realistic, valid goal payload passes validateGoalFields()');

    $goalId = $scopedDb->insert('base_plans', $validGoal);
    $stored = $scopedDb->select('base_plans', ['id' => $goalId]);
    assertTrue(count($stored) === 1, 'the valid goal actually inserted');

    // The same stored values, read back, still pass validation — confirms
    // the validator and the DB schema agree on what "valid" looks like.
    assertTrue(validateGoalField('initial_net_worth', $stored[0]['initial_net_worth']) === null,
        'the stored initial_net_worth reads back as valid');
    assertTrue(validateGoalField('current_age', $stored[0]['current_age']) === null,
        'the stored current_age reads back as valid');

    // A partial update touching only one field must not require/validate the
    // others — simulates goals_update.php's partial-update semantics: only
    // fields present in the "changes" set get validated.
    $partialChange = ['withdrawal_rate' => 4.00];
    assertTrue(validateGoalFields($partialChange) === null,
        'a partial update touching only withdrawal_rate validates independent of every other field');

    $invalidPartialChange = ['withdrawal_rate' => 150.00];
    assertTrue(validateGoalFields($invalidPartialChange) !== null,
        'a partial update with an out-of-range withdrawal_rate is caught, same as a full create would be');
} finally {
    $db->rollBack(); // fixture tenant/client/goal never persist beyond this test
}

echo "\nAll goal field validation DB-integration tests passed.\n";
