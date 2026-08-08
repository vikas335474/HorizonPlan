<?php
declare(strict_types=1);

// Risk profiler (docs/06 §B): score a completed questionnaire and store the
// profile. POST, advisor-only (the advisor administers/records the
// questionnaire; an individual may also record their own in a personal
// tenant). Tenant-scoped; the client must be in this tenant (404).
//
// Scores the answers against the firm's own question set + rubric
// (RiskProfileScoring) — HorizonPlan never authors these. The profile is stored
// regardless of the set's approval state; only the suggested_return_pct in the
// RESPONSE is gated on current approval (guardrail 2), never invented as a
// fallback. Output: {status, risk_profile_id, score, band,
// suggested_return_assumption_pct|null, question_set_approved}. Errors:
// 400 (missing input / no set / score outside every band), 404 (client),
// 405 (non-POST).

require_once __DIR__ . '/lib/security_gatekeeper.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/TenantScopedDb.php';
require_once __DIR__ . '/lib/SelfService.php';
require_once __DIR__ . '/lib/RiskProfileScoring.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$db = getPdo();
// Self-serve individual tier (sql/033): advisor-only in a firm, and
// additionally self-service for an individual writing their OWN data in a
// personal tenant. verifySelfServiceWrite() refuses an advisor-managed
// client exactly as before — see api/lib/SelfService.php.
$session = verifySelfServiceWrite($db);
$tenantId = (int) $session['tenant_id'];
$userId = (int) $session['user_id'];
$scopedDb = new TenantScopedDb($db, $tenantId);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['client_id'] ?? 0);
// A personal user always writes their own data; any client_id in the body
// is ignored rather than trusted, same posture as the client-facing reads.
$clientId = resolveSelfServiceClientId($session, $clientId);
$answers = $input['answers'] ?? null;

if ($clientId <= 0 || !is_array($answers) || empty($answers)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'client_id and a non-empty answers object are required.']);
    exit();
}

$clientMatches = $scopedDb->select('users', ['id' => $clientId, 'role' => 'client']);
if (empty($clientMatches)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No client with that ID in this tenant.']);
    exit();
}

$questionSetRows = $scopedDb->select('risk_question_sets', []);
if (empty($questionSetRows)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This firm has not set up a risk questionnaire yet.']);
    exit();
}
$questionSet = $questionSetRows[0];
$questions = json_decode($questionSet['questions_json'], true);
$rubric = json_decode($questionSet['scoring_rubric_json'], true);

$score = RiskProfileScoring::score($answers, $questions);
$band = RiskProfileScoring::bandFor($score, $rubric);

if ($band === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Score does not fall within any band in this firm\'s rubric — check the rubric\'s band ranges.']);
    exit();
}

// docs/06 guardrail 2: captured regardless of the question set's approval
// state — only the READ side (below, and risk_profile_read.php) gates
// whether the band's suggested_return_pct is allowed to surface.
$profileId = $scopedDb->insert('risk_profiles', [
    'client_id'          => $clientId,
    'question_set_id'    => (int) $questionSet['id'],
    'score'               => $score,
    'band'                => $band['name'],
    'answers_json'        => json_encode($answers),
    'created_by_user_id' => $userId,
]);

$isApproved = $questionSet['status'] === 'approved';

echo json_encode([
    'status'                       => 'success',
    'risk_profile_id'              => $profileId,
    'score'                        => $score,
    'band'                         => $band['name'],
    // Only present when the question set backing this profile is CURRENTLY
    // approved — never invented or shown as a fallback (docs/06 guardrail 2).
    'suggested_return_assumption_pct' => $isApproved ? (float) $band['suggested_return_pct'] : null,
    'question_set_approved'       => $isApproved,
]);
