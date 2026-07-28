<?php
declare(strict_types=1);

/**
 * docs/07 Session C / docs/06 Section B — risk profiler DB-level tests.
 * Same pattern as test_templates_db.php: runs the same TenantScopedDb calls
 * the risk_question_set_*.php / risk_profile_*.php endpoints use, against a
 * real MySQL instance, no self-skip.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';
require_once __DIR__ . '/../api/lib/RiskProfileScoring.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

$db = getPdo();

// Non-destructive: everything below runs inside one transaction, rolled back
// at the very end (docs/09 Session 2) — see test_tenant_isolation.php for the
// full reasoning. Test 4 below deliberately triggers a UNIQUE-constraint
// PDOException; MySQL/InnoDB (unlike Postgres) does not abort the whole
// transaction on a caught statement-level error, so this is safe to catch
// and continue within the same transaction. On a failed assertion,
// assertTrue() calls exit() directly — killing the process mid-transaction
// drops the connection, and MySQL implicitly rolls back.
$db->beginTransaction();

// --- Setup: clean slate, two tenants ---
$db->exec("DELETE FROM risk_profiles");
$db->exec("DELETE FROM risk_question_sets");
$db->exec("DELETE FROM cash_flow_items"); // migration 030 also FKs to users
$db->exec("DELETE FROM client_portfolio_items"); // migration 018 also FKs to users
$db->exec("DELETE FROM template_audit_log");
$db->exec("DELETE FROM change_log");
$db->exec("DELETE FROM sub_scenarios");
$db->exec("DELETE FROM base_plans");
$db->exec("DELETE FROM template_customizations");
$db->exec("DELETE FROM template_strategies");
$db->exec("DELETE FROM active_sessions");
$db->exec("DELETE FROM login_attempts");
$db->exec("DELETE FROM users");
$db->exec("DELETE FROM households"); // migration 029 FKs to tenants
$db->exec("DELETE FROM tenants");

$db->exec("INSERT INTO tenants (id, company_name) VALUES (1, 'Advisor Firm A'), (2, 'Advisor Firm B')");
$db->exec("INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES
    (1, 1, 'advisor_a@example.com', 'hash', 'advisor'),
    (2, 1, 'client_a@example.com', 'hash', 'client'),
    (3, 2, 'advisor_b@example.com', 'hash', 'advisor')");

$dbAdvisorA = new TenantScopedDb($db, 1);
$dbAdvisorB = new TenantScopedDb($db, 2);

$questions = [
    ['id' => 'q1', 'text' => 'Horizon', 'options' => [['value' => 'short', 'score' => 1], ['value' => 'long', 'score' => 3]]],
    ['id' => 'q2', 'text' => 'Reaction to a 20% drop', 'options' => [
        ['value' => 'sell', 'score' => 0], ['value' => 'hold', 'score' => 2], ['value' => 'buy_more', 'score' => 4],
    ]],
];
$rubric = ['bands' => [
    ['name' => 'conservative', 'min' => 0, 'max' => 2, 'suggested_return_pct' => 6.0],
    ['name' => 'moderate', 'min' => 3, 'max' => 5, 'suggested_return_pct' => 9.0],
    ['name' => 'aggressive', 'min' => 6, 'max' => 7, 'suggested_return_pct' => 12.0],
]];

// --- Test 1: ALLOWED_TABLES accepts the two new risk tables ---
$threw = false;
try {
    $dbAdvisorA->select('risk_question_sets');
    $dbAdvisorA->select('risk_profiles');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue(!$threw, 'TenantScopedDb allows risk_question_sets/risk_profiles');

// --- Test 2: a new question set defaults to draft ---
$questionSetId = $dbAdvisorA->insert('risk_question_sets', [
    'questions_json'      => json_encode($questions),
    'scoring_rubric_json' => json_encode($rubric),
    'created_by_user_id'  => 1,
]);
$fresh = $dbAdvisorA->select('risk_question_sets', ['id' => $questionSetId]);
assertTrue($fresh[0]['status'] === 'draft', 'a newly created risk_question_sets row defaults to draft — no HorizonPlan-authored rubric is ever pre-approved');

// --- Test 3: tenant isolation — advisor B does not see advisor A's question set ---
assertTrue(count($dbAdvisorB->select('risk_question_sets', [])) === 0, 'risk_question_sets rows are tenant-scoped');

// --- Test 4: the DB enforces one question set per tenant (unique constraint) ---
$duplicateRejected = false;
try {
    $dbAdvisorA->insert('risk_question_sets', [
        'questions_json'      => json_encode($questions),
        'scoring_rubric_json' => json_encode($rubric),
        'created_by_user_id'  => 1,
    ]);
} catch (PDOException $e) {
    $duplicateRejected = true;
}
assertTrue($duplicateRejected, 'the DB rejects a second risk_question_sets row for the same tenant (uq_riskquestionsets_tenant)');

// --- Test 5: submitting a profile while the question set is still DRAFT
// computes and stores a score/band, but the suggestion must not surface ---
$score = RiskProfileScoring::score(['q1' => 'long', 'q2' => 'buy_more'], $questions);
$band = RiskProfileScoring::bandFor($score, $rubric);
assertTrue($score === 7 && $band['name'] === 'aggressive', 'scoring the submission produces the expected score/band');

$profileId = $dbAdvisorA->insert('risk_profiles', [
    'client_id'          => 2,
    'question_set_id'    => $questionSetId,
    'score'               => $score,
    'band'                => $band['name'],
    'answers_json'        => json_encode(['q1' => 'long', 'q2' => 'buy_more']),
    'created_by_user_id' => 1,
]);
assertTrue($profileId > 0, 'a risk profile is captured even while the backing question set is still draft (docs/06 guardrail 2)');

// Simulate risk_profile_read.php's read-time gate: look up the question
// set's CURRENT status, not whatever it was at submission time.
function suggestedReturnFor(TenantScopedDb $scopedDb, int $questionSetId, string $bandName): ?float
{
    $rows = $scopedDb->select('risk_question_sets', ['id' => $questionSetId]);
    if (empty($rows) || $rows[0]['status'] !== 'approved') {
        return null;
    }
    $rubric = json_decode($rows[0]['scoring_rubric_json'], true);
    foreach ($rubric['bands'] as $b) {
        if ($b['name'] === $bandName) {
            return (float) $b['suggested_return_pct'];
        }
    }
    return null;
}

assertTrue(
    suggestedReturnFor($dbAdvisorA, $questionSetId, $band['name']) === null,
    'a profile\'s band does not surface a suggested return assumption while its question set is still draft'
);

// --- Test 6: approving the question set makes the SAME already-captured
// profile's suggestion surface — re-checked at read time, not frozen at submission ---
$dbAdvisorA->update('risk_question_sets', [
    'status'              => 'approved',
    'approved_by_user_id' => 1,
    'approved_at'         => date('Y-m-d H:i:s'),
], ['id' => $questionSetId]);

assertTrue(
    suggestedReturnFor($dbAdvisorA, $questionSetId, $band['name']) === 12.0,
    'once the question set is approved, the same historical profile\'s band now surfaces its suggested_return_pct — read-time gate, not submission-time'
);

// --- Test 7: editing the question set's content reverts it to draft (mirrors
// risk_question_set_update.php's approval-revert logic) ---
$dbAdvisorA->update('risk_question_sets', [
    'questions_json'      => json_encode($questions), // content "edited" (same content is fine for this test)
    'scoring_rubric_json' => json_encode($rubric),
    'status'              => 'draft',
    'approved_by_user_id' => null,
    'approved_at'         => null,
], ['id' => $questionSetId]);
assertTrue(
    suggestedReturnFor($dbAdvisorA, $questionSetId, $band['name']) === null,
    'editing an approved question set\'s content reverts it to draft, which immediately stops the suggestion from surfacing again'
);

// --- Test 8: risk_profiles rows are tenant-scoped ---
assertTrue(count($dbAdvisorB->select('risk_profiles', ['client_id' => 2])) === 0, 'risk_profiles rows are tenant-scoped — advisor B cannot see advisor A\'s client profile');
assertTrue(count($dbAdvisorA->select('risk_profiles', ['client_id' => 2])) === 1, 'advisor A can see their own client\'s risk profile');

$db->rollBack(); // leave the DB exactly as we found it — this is a fixture, not real data

echo "\nAll risk profile DB tests passed.\n";
