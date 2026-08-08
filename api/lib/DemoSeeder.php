<?php
declare(strict_types=1);

/**
 * docs/09 Piece 3 — the rich, multi-firm demo dataset's actual seeding logic.
 *
 * Moved here from tools/seed_demo_data_full.php (which is now a thin CLI
 * wrapper around this file) because api/demo_reset.php and
 * api/lib/DemoAccess.php both need it at real request time, not just from a
 * CLI invocation — and .github/workflows/deploy.yml's publish step only ever
 * copies frontend/dist/ and api/ into the deployed public_html tree, by
 * design (tools/ is documented as CLI-only, never meant to be web-reachable).
 * A require_once reaching into tools/ from production API code therefore
 * 500s on a real deploy with "failed to open stream" — the file simply isn't
 * there. Business logic that runtime code depends on belongs in api/lib/,
 * same as GoogleAuth.php/DemoAccess.php/TrialSignup.php; tools/ stays for
 * genuinely CLI-only entry points (bootstrap_admin.php, set_password.php),
 * consistent with this project's own established split.
 *
 * 1 SaaS-provider super_admin, 4 firms across the firm-level role hierarchy
 * (docs/09 Piece 2), 16 advisor-tier employees, and 160 clients spanning
 * realistic Indian age brackets and life stages — enough variety for the
 * advisor dashboard's health-signal filters (Needs attention / sort-by-
 * readiness) to have something real to demonstrate.
 *
 * Idempotent PER FIRM, not just globally: the platform admin/system template
 * are found-or-created once, and each of the 4 firms is skipped individually
 * if a tenant with that company_name already exists. This is what lets
 * api/demo_reset.php delete just the 4 demo firms (never the platform admin
 * account or platform_settings — see that file's own docblock) and then call
 * seedDemoDataFull() again to recreate only what it just deleted, without
 * refusing to run because the platform admin is still there.
 *
 * All accounts share one password (DEMO_PASSWORD below) — this mirrors
 * tools/seed_demo_data.php's existing convention. Each firm's 'admin' handle
 * (the firm_admin) gets a real, randomly generated TOTP secret at seed time
 * — see api/lib/DemoAccess.php and api/demo_login.php: the public one-click
 * "try the demo" entry point authenticates using that real secret rather than
 * relying on platform_settings.demo_mode to bypass MFA platform-wide, which
 * would also strip MFA from real self-serve trial-signup tenants sharing the
 * same production instance (api/signup.php). Every other seeded account
 * (the other 3 roster roles per firm, and all 160 clients) has no MFA secret,
 * same as any other freshly created, unenrolled account — they're never
 * targeted by the public demo-login endpoint, so there's nothing to enroll
 * against for them.
 *
 * Every client's email domain is *.demo.horizonplan.in — api/demo_reset.php
 * identifies demo-seeded rows by that domain rather than a separate flag,
 * since every user this script creates (advisors and clients alike) already
 * carries it.
 *
 * Readiness-score note: PlanMath::readinessScoreForGoal()'s corpus-multiple
 * factor depends only on withdrawal_rate (100/withdrawal_rate, clamped to the
 * 20x-40x band), and survivalFraction() is scale-invariant in the corpus
 * amount (withdrawal is defined as a percentage of the corpus, so scaling
 * both proportionally changes nothing) — so the three TIER presets below
 * were hand-tuned once via `php -r` against the real PlanMath methods (see
 * the values in the TIERS constant's comment) and reused at any corpus scale
 * without re-deriving them per client. The two-bucket (liquid/locked)
 * variants were verified the same way, including that the equal-ish bucket
 * rates chosen don't push a tier across a score-band boundary.
 */

require_once __DIR__ . '/PlanMath.php';
require_once __DIR__ . '/Totp.php';
// For DEMO_PERSONAL_EMAIL — the seeded self-serve individual's fixed address.
// DemoAccess only pulls this file back in lazily inside a function body, so
// requiring it here at top level is safe (require_once handles the cycle).
require_once __DIR__ . '/DemoAccess.php';

const DEMO_PASSWORD = 'DemoPass@2026';
const PLATFORM_ADMIN_EMAIL = 'platform.admin@demo.horizonplan.in';
const PLATFORM_TENANT_NAME = 'HorizonPlan Platform (Demo)';

// docs/09 Piece 3's table, verbatim. slug is used to build each firm's
// account email domain (e.g. admin@nirvana.demo.horizonplan.in).
const FIRMS = [
    ['slug' => 'nirvana', 'name' => 'Nirvana Wealth Advisors',              'advisory_mode' => 'advisory',     'color' => '#0f766e'],
    ['slug' => 'artha',   'name' => 'Artha Financial Advisors',             'advisory_mode' => 'advisory',     'color' => '#4f46e5'],
    ['slug' => 'dhanvantri', 'name' => 'Dhanvantri MFD Services',           'advisory_mode' => 'distribution', 'color' => '#d97706'],
    ['slug' => 'lakshmi', 'name' => 'Lakshmi Mutual Fund Distributors',     'advisory_mode' => 'distribution', 'color' => '#059669'],
];

// One retirement-goal parameter preset per readiness band, hand-verified via
// `php -r` against PlanMath::readinessScoreForGoal() before being used here:
//   low  (corpus=8,000,000  wd=6.5 infl=6.0 dd=5.5 horizon=30)               => 33
//   mid  (corpus=8,000,000  wd=4.0 infl=6.0 dd=7.0 horizon=30)               => 68
//   high (corpus=20,000,000 wd=2.5 infl=6.0 dd=8.0 horizon=25)               => 100
// and the two-bucket (liquid/locked) variant of each, same corpus/wd/dd,
// confirmed to land in the same band:
//   low  liquid_frac=0.60 locked_rate=6.5                                    => 35
//   mid  liquid_frac=0.65 locked_rate=7.0                                    => 68
//   high liquid_frac=0.70 locked_rate=7.5                                    => 100
const TIERS = [
    'low'  => ['wd' => 6.5, 'infl' => 6.0, 'dd' => 5.5, 'horizon' => 30, 'liquid_frac' => 0.60, 'locked_rate' => 6.5],
    'mid'  => ['wd' => 4.0, 'infl' => 6.0, 'dd' => 7.0, 'horizon' => 30, 'liquid_frac' => 0.65, 'locked_rate' => 7.0],
    'high' => ['wd' => 2.5, 'infl' => 6.0, 'dd' => 8.0, 'horizon' => 25, 'liquid_frac' => 0.70, 'locked_rate' => 7.5],
];
// Cycled 1:2:1 across every projectable retirement goal — exactly the
// ~25% / ~50% / ~25% split docs/09 asks for (non-retirement and
// accumulation-only goals are never projectable, so they don't dilute this).
const TIER_CYCLE = ['low', 'mid', 'mid', 'high'];

// docs/09's age-bracket table (counts are "per firm", 40 total per firm x 4
// firms = 160). Clients are NOT individually assigned to one advisor in the
// schema — TenantScopedDb/base_plans have no per-advisor ownership column,
// only tenant-level scoping (docs/02 3.1) — so "10 clients per employee" is
// a demo-narrative counting convenience (10 x 4 employees = 40), not an
// enforced relationship; every advisor in the firm can see every client.
const AGE_BRACKETS = [
    ['key' => '25_30',    'count' => 8,  'age_min' => 25, 'age_max' => 30, 'profile' => 'accumulation_only',        'risk' => 'aggressive'],
    ['key' => '31_40',    'count' => 12, 'age_min' => 31, 'age_max' => 40, 'profile' => 'mixed',                    'risk' => 'moderate'],
    ['key' => '41_50',    'count' => 10, 'age_min' => 41, 'age_max' => 50, 'profile' => 'full_composition',         'risk' => 'moderate'],
    ['key' => '51_60',    'count' => 6,  'age_min' => 51, 'age_max' => 60, 'profile' => 'decumulation_conservative','risk' => 'conservative'],
    ['key' => '60_plus',  'count' => 4,  'age_min' => 61, 'age_max' => 75, 'profile' => 'pure_decumulation',        'risk' => 'conservative'],
];

// A firm-role hierarchy employee roster — 1 firm_admin, 1 sr_advisor,
// 2 jr_advisor per firm (docs/09 Piece 2's own table), 16 total across 4 firms.
const EMPLOYEE_ROSTER = [
    ['handle' => 'admin',   'firm_role' => 'firm_admin'],
    ['handle' => 'senior',  'firm_role' => 'sr_advisor'],
    ['handle' => 'junior1', 'firm_role' => 'jr_advisor'],
    ['handle' => 'junior2', 'firm_role' => 'jr_advisor'],
];

/**
 * Insert one demo user (advisor or client) with the shared DEMO_PASSWORD hash.
 * Raw INSERT, not TenantScopedDb — the seeder runs outside any request/session,
 * so there is no verified tenant to bind the helper to; tenant_id is passed
 * explicitly on every call instead.
 *
 * @return int the new user's id
 */
function makeUser(PDO $db, int $tenantId, string $email, string $role, ?string $firmRole = null, ?string $mfaSecret = null): int
{
    $stmt = $db->prepare(
        'INSERT INTO users (tenant_id, email, password_hash, role, firm_role, mfa_secret) VALUES (:t, :e, :h, :r, :fr, :mfa)'
    );
    $stmt->execute([
        ':t' => $tenantId, ':e' => $email,
        ':h' => password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT),
        ':r' => $role, ':fr' => $firmRole, ':mfa' => $mfaSecret,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Insert one demo base_plans goal. $fields is a column=>value map (the goal's
 * parameters); tenant_id/client_id are injected here so callers only pass the
 * planning fields. Raw INSERT for the same no-session reason as makeUser().
 *
 * @param array<string,mixed> $fields
 * @return int the new goal's id
 */
function makeGoal(PDO $db, int $tenantId, int $clientId, array $fields): int
{
    $fields['tenant_id'] = $tenantId;
    $fields['client_id'] = $clientId;

    // Backdate the plan so the demo has plan HISTORY, not just a plan.
    // base_plans.created_at is the anchor progress tracking measures elapsed
    // time from (see ProgressSnapshot.php's "plan anchor" note); left at
    // CURRENT_TIMESTAMP every demo goal would be zero years old, every
    // expected value would equal its starting corpus, and the whole
    // progress-over-time feature would render as a flat line in the demo
    // an evaluator is looking at.
    //
    // Deterministic, not random, so re-seeding reproduces the same demo:
    // 6–41 months old, spread by client id.
    if (!array_key_exists('created_at', $fields)) {
        $monthsOld = 6 + ($clientId % 36);
        $fields['created_at'] = date('Y-m-d H:i:s', strtotime("-{$monthsOld} months"));
    }
    $columns = array_keys($fields);
    $placeholders = array_map(fn($c) => ":$c", $columns);
    $stmt = $db->prepare(
        'INSERT INTO base_plans (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute(array_combine($placeholders, array_values($fields)));
    return (int) $db->lastInsertId();
}

/**
 * Insert one demo client_portfolio_items row (asset or liability). $bucket is
 * liquid|locked for an asset, null for a liability — same shape the real
 * client_portfolio_create.php endpoint enforces.
 */
function makePortfolioItem(PDO $db, int $tenantId, int $clientId, int $creatorId, string $kind, ?string $bucket, string $category, string $description, float $value): void
{
    $db->prepare(
        'INSERT INTO client_portfolio_items
            (tenant_id, client_id, item_kind, bucket, category, description, value, created_by_user_id)
         VALUES (:t, :c, :kind, :bucket, :cat, :desc, :val, :creator)'
    )->execute([
        ':t' => $tenantId, ':c' => $clientId, ':kind' => $kind, ':bucket' => $bucket,
        ':cat' => $category, ':desc' => $description, ':val' => $value, ':creator' => $creatorId,
    ]);
}

/**
 * Insert one demo cash_flow_items row (income or expense). $cadence is
 * monthly|annual — CashFlowSummary normalises annual to monthly on read.
 */
function makeCashFlowItem(PDO $db, int $tenantId, int $clientId, int $creatorId, string $kind, string $label, float $amount, string $cadence, string $category): void
{
    $db->prepare(
        'INSERT INTO cash_flow_items
            (tenant_id, client_id, kind, label, amount, cadence, category, created_by_user_id)
         VALUES (:t, :c, :kind, :label, :amount, :cadence, :cat, :creator)'
    )->execute([
        ':t' => $tenantId, ':c' => $clientId, ':kind' => $kind, ':label' => $label,
        ':amount' => $amount, ':cadence' => $cadence, ':cat' => $category, ':creator' => $creatorId,
    ]);
}

// docs/10 cash-flow module — a realistic income/expense statement per client so
// the demo actually SHOWS "salary + other income − living expenses − EMIs =
// monthly surplus", and the advisor-only surplus-vs-SIP comparison lands. The
// deliberate mix is the selling story: most working clients comfortably fund
// their plan's SIPs, but a ~25% `stretched` subset (high car-loan + lifestyle
// burden) runs a surplus BELOW their SIPs — exactly the "is this plan actually
// fundable from cash flow?" gap the module exists to surface. Retired clients
// show a pension/SWP-funded statement with a fast-inflating healthcare line and
// no SIP (funded trivially — the point there is a complete cash-flow picture,
// not a gap). Amounts are deterministic from age/profile so re-seeding is stable.
function seedClientCashFlow(PDO $db, int $tenantId, int $clientId, int $creatorId, int $age, string $profile, bool $stretched): void
{
    $working = in_array($profile, ['accumulation_only', 'mixed', 'full_composition'], true);

    if ($working) {
        $salary = 55000.0 + $age * 1800.0;
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Salary', $salary, 'monthly', 'salary');
        if ($profile !== 'accumulation_only') {
            // A two-month annual bonus — an annual line the module spreads ÷ 12.
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Annual bonus', $salary * 2, 'annual', 'salary');
        }
        if ($profile === 'full_composition') {
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Rental income', 25000.0, 'monthly', 'rental');
        }

        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Household expenses', 30000.0 + $age * 500.0, 'monthly', 'food');
        if ($age >= 35) {
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Home loan EMI', 35000.0, 'monthly', 'emi');
        }
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Insurance premiums', 60000.0, 'annual', 'insurance');
        if ($age >= 33 && $age <= 52) {
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', "Children's school fees", 180000.0, 'annual', 'education');
        }
        if ($stretched) {
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Car loan EMI', 45000.0, 'monthly', 'emi');
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Lifestyle & travel', 40000.0, 'monthly', 'lifestyle');
        }
    } else {
        // Retired / pre-retirement: income is pension + drawdown, not salary.
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Pension', 40000.0, 'monthly', 'pension');
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Systematic withdrawal (SWP)', 55000.0, 'monthly', 'investment_income');
        if ($profile === 'pure_decumulation') {
            makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'income', 'Rental income', 30000.0, 'monthly', 'rental');
        }
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Household expenses', 55000.0, 'monthly', 'food');
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Healthcare', 180000.0, 'annual', 'healthcare');
        makeCashFlowItem($db, $tenantId, $clientId, $creatorId, 'expense', 'Insurance premiums', 40000.0, 'annual', 'insurance');
    }
}

// One believable "family" surname per firm, for the demo household (docs/10
// P0-1) — so the combined household projection AND the combined household
// cash-flow roll-up both have real members to add up in the demo.
const FAMILY_SURNAMES = ['Sharma', 'Iyer', 'Reddy', 'Nair'];

// Same 4-question shape as tools/seed_demo_data.php, with per-firm text
// variation (docs/09: "different question sets per firm to show
// customization") — the rubric bands/labels are identical across firms
// (conservative/moderate/aggressive) but the exact score thresholds shift a
// little per firm index so the sets aren't byte-identical.
function makeRiskQuestionSet(PDO $db, int $tenantId, int $creatorId, int $approverId, int $firmIndex): int
{
    $variants = [
        'How would you react to a 20%% drop in your portfolio value?',
        "A sudden 20%% market correction hits your portfolio — what's your reaction?",
        'Your investments fall 20%% in a month. What do you do?',
        'If your portfolio dropped 20%% overnight, how would you respond?',
    ];
    $questions = [
        ['id' => 'q1', 'text' => $variants[$firmIndex % count($variants)], 'options' => [
            ['value' => 'sell_all', 'score' => 0], ['value' => 'reduce_some', 'score' => 1],
            ['value' => 'hold', 'score' => 2], ['value' => 'buy_more', 'score' => 3],
        ]],
        ['id' => 'q2', 'text' => 'What is your investment horizon for this money?', 'options' => [
            ['value' => 'lt_3y', 'score' => 0], ['value' => '3_7y', 'score' => 1],
            ['value' => '7_15y', 'score' => 2], ['value' => 'gt_15y', 'score' => 3],
        ]],
        ['id' => 'q3', 'text' => 'How important is capital preservation vs. growth to you?', 'options' => [
            ['value' => 'preservation', 'score' => 0], ['value' => 'balanced', 'score' => 1],
            ['value' => 'growth', 'score' => 2], ['value' => 'aggressive_growth', 'score' => 3],
        ]],
        ['id' => 'q4', 'text' => 'Have you invested in equity mutual funds before?', 'options' => [
            ['value' => 'never', 'score' => 0], ['value' => 'briefly', 'score' => 1],
            ['value' => 'regularly', 'score' => 2], ['value' => 'extensively', 'score' => 3],
        ]],
    ];
    // Thresholds shift by +/-1 per firm index (still non-overlapping, still
    // covering 0-12) — cosmetic variation, not a scoring behavior change.
    $shift = $firmIndex % 2 === 0 ? 0 : 1;
    $rubric = ['bands' => [
        ['name' => 'conservative', 'min' => 0, 'max' => 4 + $shift, 'suggested_return_pct' => 6.0],
        ['name' => 'moderate', 'min' => 5 + $shift, 'max' => 8 + $shift, 'suggested_return_pct' => 8.5],
        ['name' => 'aggressive', 'min' => 9 + $shift, 'max' => 12, 'suggested_return_pct' => 11.0],
    ]];

    $db->prepare(
        "INSERT INTO risk_question_sets
            (tenant_id, questions_json, scoring_rubric_json, status, approved_by_user_id, approved_at, created_by_user_id)
         VALUES (:t, :q, :r, 'approved', :approver, NOW(), :creator)"
    )->execute([
        ':t' => $tenantId, ':q' => json_encode($questions), ':r' => json_encode($rubric),
        ':approver' => $approverId, ':creator' => $creatorId,
    ]);
    return (int) $db->lastInsertId();
}

// Picks answers/score/band consistent with a risk hint ('aggressive' /
// 'moderate' / 'conservative') against the SAME rubric shape makeRiskQuestionSet()
// just wrote (thresholds shifted by firmIndex, same as above).
function captureRiskProfile(PDO $db, int $tenantId, int $clientId, int $questionSetId, int $creatorId, string $riskHint, int $firmIndex): void
{
    $shift = $firmIndex % 2 === 0 ? 0 : 1;
    $presets = [
        'aggressive'   => ['answers' => ['q1' => 'buy_more', 'q2' => 'gt_15y', 'q3' => 'aggressive_growth', 'q4' => 'extensively'], 'score' => 12, 'band' => 'aggressive'],
        'moderate'     => ['answers' => ['q1' => 'hold', 'q2' => '7_15y', 'q3' => 'balanced', 'q4' => 'regularly'], 'score' => 7, 'band' => (7 >= 5 + $shift && 7 <= 8 + $shift) ? 'moderate' : 'conservative'],
        'conservative' => ['answers' => ['q1' => 'reduce_some', 'q2' => '3_7y', 'q3' => 'preservation', 'q4' => 'briefly'], 'score' => 3, 'band' => 'conservative'],
    ];
    $p = $presets[$riskHint] ?? $presets['moderate'];

    $db->prepare(
        'INSERT INTO risk_profiles (tenant_id, client_id, question_set_id, score, band, answers_json, created_by_user_id)
         VALUES (:t, :c, :qs, :score, :band, :answers, :creator)'
    )->execute([
        ':t' => $tenantId, ':c' => $clientId, ':qs' => $questionSetId,
        ':score' => $p['score'], ':band' => $p['band'],
        ':answers' => json_encode($p['answers']), ':creator' => $creatorId,
    ]);
}

// Finds (or creates, first call only) the global "Moderate - Balanced
// Growth" system template — same find-or-create-by-name fix as
// tools/seed_templates.php / tools/seed_demo_data.php (tenant_id=0 silently
// reassigns under this project's sql_mode, so a real "HorizonPlan System"
// tenant is looked up by name instead).
function findOrCreateSystemTemplate(PDO $db): array
{
    $stmt = $db->query("SELECT id, return_assumption_pct, template_name, approval_status FROM template_strategies WHERE is_system_template = 1 LIMIT 1");
    $template = $stmt->fetch();
    if ($template) {
        return ['id' => (int) $template['id'], 'return_pct' => (float) $template['return_assumption_pct'], 'name' => $template['template_name'], 'approved' => $template['approval_status'] === 'approved'];
    }

    $sysTenantStmt = $db->prepare("SELECT id FROM tenants WHERE company_name = 'HorizonPlan System' LIMIT 1");
    $sysTenantStmt->execute();
    $sysTenant = $sysTenantStmt->fetch();
    $systemTenantId = $sysTenant ? (int) $sysTenant['id'] : null;
    if ($systemTenantId === null) {
        $db->exec("INSERT INTO tenants (company_name, advisory_mode) VALUES ('HorizonPlan System', 'advisory')");
        $systemTenantId = (int) $db->lastInsertId();
    }

    $db->prepare(
        "INSERT INTO template_strategies
            (tenant_id, creator_user_id, creator_org_id, template_name, description, market_code,
             allocation_json, return_assumption_pct, risk_profile_enum, is_system_template, is_published, published_at)
         VALUES
            (:tenant, NULL, NULL, 'Moderate - Balanced Growth (Age 45-55)',
             'Balanced equity-debt mix for mid-career professionals. Suitable for 10-20 year horizon.',
             'IN', :alloc, 8.00, 'moderate', 1, 1, NOW())"
    )->execute([
        ':tenant' => $systemTenantId,
        ':alloc'  => json_encode(['equity' => 40, 'debt' => 40, 'gold' => 15, 'cash' => 5]),
    ]);
    return ['id' => (int) $db->lastInsertId(), 'return_pct' => 8.00, 'name' => 'Moderate - Balanced Growth (Age 45-55)', 'approved' => false];
}

/**
 * Seeds whatever is missing: the platform admin + system template are
 * found-or-created (never duplicated), and each of the 4 FIRMS is created
 * only if no tenant with that company_name already exists — so calling this
 * again after api/demo_reset.php deletes just the 4 firms recreates exactly
 * those 4, leaving the platform admin account and its tenant untouched.
 *
 * Runs everything inside one transaction — either the whole missing set is
 * created, or none of it is, same non-destructive-on-failure shape as
 * tools/seed_demo_data.php.
 *
 * @return array{platform_admin_email: string, created_firms: string[], skipped_firms: string[], total_clients: int, total_goals: int}
 */
function seedDemoDataFull(PDO $db): array
{
    $db->beginTransaction();
    try {
        // ── Platform admin (find-or-create) ─────────────────────────────────
        $existingAdmin = $db->prepare('SELECT id, tenant_id FROM users WHERE email = :e LIMIT 1');
        $existingAdmin->execute([':e' => PLATFORM_ADMIN_EMAIL]);
        $adminRow = $existingAdmin->fetch();

        if ($adminRow) {
            $platformAdminId = (int) $adminRow['id'];
        } else {
            $db->prepare("INSERT INTO tenants (company_name, advisory_mode) VALUES (:name, 'distribution')")
               ->execute([':name' => PLATFORM_TENANT_NAME]);
            $platformTenantId = (int) $db->lastInsertId();
            $platformAdminId = makeUser($db, $platformTenantId, PLATFORM_ADMIN_EMAIL, 'super_admin');
        }

        $systemTemplate = findOrCreateSystemTemplate($db);
        if (!$systemTemplate['approved']) {
            $db->prepare(
                "UPDATE template_strategies SET approval_status = 'approved', approved_by_user_id = :uid, approved_at = NOW() WHERE id = :id"
            )->execute([':uid' => $platformAdminId, ':id' => $systemTemplate['id']]);
        }

        $projectableIndex = 0; // drives TIER_CYCLE across every projectable retirement goal, platform-wide
        $totalClients = 0;
        $totalGoals = 0;
        $createdFirms = [];
        $skippedFirms = [];

    foreach (FIRMS as $firmIndex => $firmDef) {
        // Per-firm idempotency — skip a firm that already has a tenant, so a
        // demo_reset.php run that only deleted SOME firms (or a re-run of
        // this script with no reset at all) never creates a duplicate.
        $firmExists = $db->prepare('SELECT id FROM tenants WHERE company_name = :name LIMIT 1');
        $firmExists->execute([':name' => $firmDef['name']]);
        if ($firmExists->fetch()) {
            $skippedFirms[] = $firmDef['name'];
            continue;
        }

        // ── Firm ────────────────────────────────────────────────────────────
        $db->prepare(
            "INSERT INTO tenants (company_name, advisory_mode, white_label_settings) VALUES (:name, :mode, :wl)"
        )->execute([
            ':name' => $firmDef['name'],
            ':mode' => $firmDef['advisory_mode'],
            ':wl'   => json_encode(['company_name' => $firmDef['name'], 'primary_color' => $firmDef['color']]),
        ]);
        $tenantId = (int) $db->lastInsertId();

        // ── Employees (docs/09 Piece 2 firm-role hierarchy) ─────────────────
        // The firm_admin ('admin' handle) gets a real TOTP secret so
        // api/demo_login.php can issue it a fully MFA-satisfied session
        // without relying on platform_settings.demo_mode to bypass MFA
        // platform-wide (see api/lib/DemoAccess.php's docblock for why that
        // distinction matters once real trial-signup tenants share this same
        // instance). The other three roster roles are never targeted by the
        // public one-click demo login, so they stay exactly as before
        // (no secret — same as any other freshly seeded, unenrolled account).
        $employees = [];
        foreach (EMPLOYEE_ROSTER as $emp) {
            $email = "{$emp['handle']}@{$firmDef['slug']}.demo.horizonplan.in";
            $mfaSecret = $emp['handle'] === 'admin' ? Totp::generateSecret() : null;
            $employees[] = ['id' => makeUser($db, $tenantId, $email, 'advisor', $emp['firm_role'], $mfaSecret), 'firm_role' => $emp['firm_role']];
        }
        $firmAdminId = $employees[0]['id'];
        $srAdvisorId = $employees[1]['id'];

        // ── Risk questionnaire (approved) ───────────────────────────────────
        $questionSetId = makeRiskQuestionSet($db, $tenantId, $srAdvisorId, $firmAdminId, $firmIndex);

        // ── Strategy templates ──────────────────────────────────────────────
        // Every firm applies the global system template to at least one goal.
        // Advisory-mode firms additionally get a firm-owned approved template
        // plus a draft customization, to show the firm-level authoring flow.
        $ownTemplateId = null;
        if ($firmDef['advisory_mode'] === 'advisory') {
            $db->prepare(
                "INSERT INTO template_strategies
                    (tenant_id, creator_user_id, creator_org_id, template_name, description, market_code,
                     allocation_json, return_assumption_pct, risk_profile_enum, is_system_template, is_published,
                     approval_status, approved_by_user_id, approved_at)
                 VALUES
                    (:tenant, :creator, :creator_org, :name, :desc, 'IN', :alloc, :ret, 'moderate', 0, 1,
                     'approved', :approver, NOW())"
            )->execute([
                // :tenant and :creator_org carry the same value (tenant_id) but
                // must be bound as distinct placeholders — MySQL's native
                // prepared statements (EMULATE_PREPARES => false) reject a
                // repeated named parameter, same class of issue documented in
                // security_gatekeeper.php's checkLoginRateLimit() comment.
                ':tenant' => $tenantId, ':creator' => $srAdvisorId, ':creator_org' => $tenantId,
                ':name' => "{$firmDef['name']} House View — Balanced",
                ':desc' => 'Firm-authored balanced allocation, approved for client use.',
                ':alloc' => json_encode(['equity' => 45, 'debt' => 35, 'gold' => 15, 'cash' => 5]),
                ':ret' => 8.50, ':approver' => $firmAdminId,
            ]);
            $ownTemplateId = (int) $db->lastInsertId();

            $db->prepare(
                "INSERT INTO template_customizations
                    (tenant_id, base_template_id, created_by_user_id, template_name, allocation_json,
                     return_assumption_pct, is_shareable, approval_status)
                 VALUES (:tenant, :base, :creator, :name, :alloc, :ret, 0, 'draft')"
            )->execute([
                ':tenant' => $tenantId, ':base' => $ownTemplateId, ':creator' => $srAdvisorId,
                ':name' => "{$firmDef['name']} House View — Growth Fork (draft)",
                ':alloc' => json_encode(['equity' => 60, 'debt' => 25, 'gold' => 10, 'cash' => 5]),
                ':ret' => 9.50,
            ]);
        }

        // ── Clients across age brackets ──────────────────────────────────
        $firstAppliedTemplateGoal = null;
        $subScenarioSeeded = false;
        // docs/10 P0-1 — collect one working-age client per working bracket into
        // a single demo "family" (a household spanning a 25–30, a 31–40, and a
        // 41–50 member), assigned after the loop.
        $familyMembers = [];

        foreach (AGE_BRACKETS as $bracket) {
            for ($i = 0; $i < $bracket['count']; $i++) {
                $totalClients++;
                $age = $bracket['age_min'] + ($i % max(1, $bracket['age_max'] - $bracket['age_min'] + 1));
                $clientEmail = "client{$totalClients}.{$bracket['key']}@{$firmDef['slug']}.demo.horizonplan.in";
                $clientId = makeUser($db, $tenantId, $clientEmail, 'client');

                // Portfolio ledger — every client gets a small realistic mix.
                makePortfolioItem($db, $tenantId, $clientId, $srAdvisorId, 'asset', 'liquid', 'mutual_fund', 'Equity mutual funds', 400000 + ($age * 15000));
                makePortfolioItem($db, $tenantId, $clientId, $srAdvisorId, 'asset', 'locked', 'ppf', 'PPF account', 200000 + ($age * 8000));
                if ($age >= 35) {
                    makePortfolioItem($db, $tenantId, $clientId, $srAdvisorId, 'liability', null, 'home_loan', 'Home loan outstanding', 800000);
                }

                // Risk profile — captured against the firm's approved set.
                captureRiskProfile($db, $tenantId, $clientId, $questionSetId, $srAdvisorId, $bracket['risk'], $firmIndex);

                // docs/10 cash-flow module — a full income/expense statement.
                // ~1 in 4 working clients is deliberately "stretched" (surplus
                // below their SIPs) so the fundability gap is demonstrable.
                $stretched = ($totalClients % 4 === 0);
                seedClientCashFlow($db, $tenantId, $clientId, $srAdvisorId, $age, $bracket['profile'], $stretched);

                // First client of each working bracket joins the demo household.
                if ($i === 0 && in_array($bracket['profile'], ['accumulation_only', 'mixed', 'full_composition'], true)) {
                    $familyMembers[] = $clientId;
                }

                $goalId = null;
                switch ($bracket['profile']) {
                    case 'accumulation_only':
                        // Early career — pure SIP build-up, no withdrawal/drawdown
                        // fields at all, so this goal is never projectable
                        // (goals_projection.php 400s, readiness_score is null —
                        // same convention as any other non-retirement goal).
                        $goalId = makeGoal($db, $tenantId, $clientId, [
                            'goal_type' => 'retirement', 'goal_label' => "Age {$age} — Retirement (accumulation)",
                            'initial_net_worth' => 150000 + ($age * 10000), 'inflation_rate' => 6.0,
                            'accumulation_return_rate' => 11.0, 'current_age' => $age, 'retirement_age' => 60,
                            'monthly_sip_amount' => 15000 + ($age * 300), 'sip_step_up_rate' => 10.0,
                            'projection_horizon_years' => (60 - $age) + 30,
                        ]);
                        break;

                    case 'mixed':
                        $tier = TIERS[TIER_CYCLE[$projectableIndex % 4]];
                        $projectableIndex++;
                        $corpus = 3000000 + ($age * 40000);
                        $goalId = makeGoal($db, $tenantId, $clientId, [
                            'goal_type' => 'retirement', 'goal_label' => "Age {$age} — Retirement (mid-career)",
                            'initial_net_worth' => $corpus, 'inflation_rate' => $tier['infl'],
                            'withdrawal_rate' => $tier['wd'], 'drawdown_return_rate' => $tier['dd'],
                            'accumulation_return_rate' => 10.0, 'current_age' => $age, 'retirement_age' => 60,
                            'monthly_sip_amount' => 20000, 'sip_step_up_rate' => 8.0,
                            'projection_horizon_years' => $tier['horizon'],
                        ]);
                        break;

                    case 'full_composition':
                        $tier = TIERS[TIER_CYCLE[$projectableIndex % 4]];
                        $projectableIndex++;
                        $corpus = 8000000 + ($age * 60000);
                        $liquid = round($corpus * $tier['liquid_frac'], 2);
                        $locked = round($corpus - $liquid, 2);
                        $goalId = makeGoal($db, $tenantId, $clientId, [
                            'goal_type' => 'retirement', 'goal_label' => "Age {$age} — Retirement (peak earning)",
                            'initial_net_worth' => $corpus, 'inflation_rate' => $tier['infl'],
                            'withdrawal_rate' => $tier['wd'], 'drawdown_return_rate' => $tier['dd'],
                            'accumulation_return_rate' => 9.0, 'current_age' => $age, 'retirement_age' => 60,
                            'monthly_sip_amount' => 15000, 'sip_step_up_rate' => 7.0,
                            'liquid_corpus_amount' => $liquid, 'locked_corpus_amount' => $locked,
                            'locked_return_rate' => $tier['locked_rate'],
                            'projection_horizon_years' => $tier['horizon'],
                        ]);
                        break;

                    case 'decumulation_conservative':
                        $tier = TIERS[TIER_CYCLE[$projectableIndex % 4]];
                        $projectableIndex++;
                        $corpus = 10000000 + ($age * 80000);
                        $goalId = makeGoal($db, $tenantId, $clientId, [
                            'goal_type' => 'retirement', 'goal_label' => "Age {$age} — Retirement (pre-retirement)",
                            'initial_net_worth' => $corpus, 'inflation_rate' => $tier['infl'],
                            'withdrawal_rate' => $tier['wd'], 'drawdown_return_rate' => $tier['dd'],
                            'projection_horizon_years' => $tier['horizon'],
                        ]);
                        break;

                    case 'pure_decumulation':
                        $tier = TIERS[TIER_CYCLE[$projectableIndex % 4]];
                        $projectableIndex++;
                        $corpus = 14000000 + ($age * 100000);
                        $goalId = makeGoal($db, $tenantId, $clientId, [
                            'goal_type' => 'retirement', 'goal_label' => "Age {$age} — Retirement (retired)",
                            'initial_net_worth' => $corpus, 'inflation_rate' => $tier['infl'],
                            'withdrawal_rate' => $tier['wd'], 'drawdown_return_rate' => $tier['dd'],
                            'projection_horizon_years' => $tier['horizon'],
                        ]);
                        break;
                }
                $totalGoals++;

                // A second, non-retirement goal for a subset of clients — just
                // enough to demonstrate multi-goal-per-client without doubling
                // the whole dataset's size.
                if ($i % 5 === 0) {
                    makeGoal($db, $tenantId, $clientId, [
                        'goal_type' => $age < 40 ? 'education' : 'home_purchase',
                        'goal_label' => $age < 40 ? "Child's education fund" : 'Second home',
                        'target_amount' => 2000000, 'target_date' => date('Y-m-d', strtotime('+8 years')),
                        'initial_net_worth' => 300000, 'inflation_rate' => 7.0,
                    ]);
                    $totalGoals++;
                }

                // Sub-scenarios — one client per firm gets a live + a frozen
                // variant, demonstrating both cascade behaviors (docs/09
                // asks for "some clients have sub-scenarios", not all 160).
                if (!$subScenarioSeeded && $bracket['profile'] === 'full_composition' && $goalId !== null) {
                    $db->prepare(
                        "INSERT INTO sub_scenarios (base_plan_id, tenant_id, custom_withdrawal_rate, custom_drawdown_return_rate, is_overridden)
                         VALUES (:bp, :t, 3.00, 7.50, 0)"
                    )->execute([':bp' => $goalId, ':t' => $tenantId]);
                    $db->prepare(
                        "INSERT INTO sub_scenarios (base_plan_id, tenant_id, custom_withdrawal_rate, custom_drawdown_return_rate, is_overridden)
                         VALUES (:bp, :t, 5.00, 6.50, 1)"
                    )->execute([':bp' => $goalId, ':t' => $tenantId]);
                    $subScenarioSeeded = true;
                }

                // Apply the global template to the firm's first full_composition
                // goal, the real used_in_plan path (docs/07 Bet 1's loop-closer),
                // satisfying "at least 1 goal per firm has a strategy template applied".
                if ($firstAppliedTemplateGoal === null && $bracket['profile'] === 'full_composition' && $goalId !== null) {
                    $db->prepare(
                        'UPDATE base_plans SET drawdown_return_rate = :rate, applied_template_id = :tid WHERE id = :goal'
                    )->execute([':rate' => $systemTemplate['return_pct'], ':tid' => $systemTemplate['id'], ':goal' => $goalId]);
                    $db->prepare(
                        "INSERT INTO template_audit_log (tenant_id, template_id, user_id, action, entity_details_json)
                         VALUES (:t, :tid, :uid, 'used_in_plan', :details)"
                    )->execute([
                        ':t' => $tenantId, ':tid' => $systemTemplate['id'], ':uid' => $srAdvisorId,
                        ':details' => json_encode(['goal_id' => $goalId, 'source_name' => $systemTemplate['name'], 'applied_drawdown_return_rate' => $systemTemplate['return_pct']]),
                    ]);
                    $firstAppliedTemplateGoal = $goalId;
                }
            }
        }

        // docs/10 P0-1 — group the collected members into one household so the
        // combined projection AND the combined cash-flow roll-up both have real
        // members to sum in the demo.
        if (count($familyMembers) >= 2) {
            $surname = FAMILY_SURNAMES[$firmIndex % count(FAMILY_SURNAMES)];
            $db->prepare('INSERT INTO households (tenant_id, name) VALUES (:t, :n)')
               ->execute([':t' => $tenantId, ':n' => "The {$surname} family"]);
            $householdId = (int) $db->lastInsertId();
            $assign = $db->prepare('UPDATE users SET household_id = :h WHERE id = :id AND tenant_id = :t');
            foreach ($familyMembers as $memberId) {
                $assign->execute([':h' => $householdId, ':id' => $memberId, ':t' => $tenantId]);
            }
        }

        $createdFirms[] = $firmDef['name'];
    }

    // --- the self-serve individual demo (sql/033) -------------------------
    // One seeded "tenant of one" so a visitor can try the consumer experience
    // — sign-up-free — exactly as a real individual would see it. Skipped if
    // it already exists, same idempotency posture as the firms above.
    $personalExists = $db->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
    $personalExists->execute([':e' => DEMO_PERSONAL_EMAIL]);
    if (!$personalExists->fetch()) {
        $db->prepare(
            "INSERT INTO tenants (company_name, kind, advisory_mode, signup_source)
             VALUES ('Meera (personal plan)', 'personal', 'self_directed', 'personal_signup')"
        )->execute();
        $personalTenantId = (int) $db->lastInsertId();

        // MFA-satisfied so the demo never dead-ends on an enrollment prompt,
        // same as the demo firm admins.
        $personalUserId = makeUser(
            $db, $personalTenantId, DEMO_PERSONAL_EMAIL, 'client', null, Totp::generateSecret()
        );

        // A plan mid-journey rather than a blank one: 12 years in, saving
        // monthly, so readiness, the countdown and the progress chart all have
        // something real to show the moment the visitor lands.
        makeGoal($db, $personalTenantId, $personalUserId, [
            'goal_type'                => 'retirement',
            'goal_label'               => 'Retiring at 60',
            'initial_net_worth'        => 4200000,
            'inflation_rate'           => 6,
            'withdrawal_rate'          => 3.5,
            'drawdown_return_rate'     => 9,
            'accumulation_return_rate' => 9,
            'current_age'              => 41,
            'retirement_age'           => 60,
            'monthly_sip_amount'       => 30000,
            'sip_step_up_rate'         => 5,
            'projection_horizon_years' => 30,
        ]);
        makeGoal($db, $personalTenantId, $personalUserId, [
            'goal_type'                => 'education',
            'goal_label'               => "Daughter's college",
            'initial_net_worth'        => 350000,
            'inflation_rate'           => 7,
            'target_amount'            => 2500000,
            'target_date'              => date('Y-m-d', strtotime('+9 years')),
            'projection_horizon_years' => 9,
        ]);

        makePortfolioItem($db, $personalTenantId, $personalUserId, $personalUserId, 'asset', 'liquid', 'mutual_fund', 'Equity mutual funds', 2600000);
        makePortfolioItem($db, $personalTenantId, $personalUserId, $personalUserId, 'asset', 'locked', 'epf', 'EPF balance', 1600000);
        makeCashFlowItem($db, $personalTenantId, $personalUserId, $personalUserId, 'income', 'Salary', 145000, 'monthly', 'salary');
        makeCashFlowItem($db, $personalTenantId, $personalUserId, $personalUserId, 'expense', 'Household expenses', 62000, 'monthly', 'food_groceries');
        makeCashFlowItem($db, $personalTenantId, $personalUserId, $personalUserId, 'expense', 'Home loan EMI', 38000, 'monthly', 'emi');
    }

        $db->commit();

        return [
            'platform_admin_email' => PLATFORM_ADMIN_EMAIL,
            'created_firms'        => $createdFirms,
            'skipped_firms'        => $skippedFirms,
            'total_clients'        => $totalClients,
            'total_goals'          => $totalGoals,
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
