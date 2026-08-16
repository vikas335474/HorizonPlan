<?php
declare(strict_types=1);

/**
 * Demo data bootstrap — CLI ONLY.
 *
 * Spins up one realistic, fully-populated advisory firm so the product can be
 * demoed to a prospective advisor/distributor without hand-building data
 * through the UI first. Touches every feature shipped so far: multi-goal
 * planning, accumulation + decumulation, corpus composition (liquid/locked),
 * sub-scenarios (including one overridden/frozen), the client portfolio
 * ledger, an approved risk questionnaire with two captured profiles, and one
 * approved strategy template actually applied to a goal (the real
 * `used_in_plan` audit path, not a fabricated count).
 *
 * Idempotent: refuses to run twice against the same database — if the demo
 * tenant already exists, it prints the existing sign-in details and exits
 * rather than creating a duplicate firm.
 *
 *   php tools/seed_demo_data.php
 *
 * All figures are illustrative/hand-picked for a coherent demo narrative —
 * not sourced from `market_history` or intended as real financial guidance
 * (same posture as tools/seed_templates.php's assumption sets).
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

const DEMO_COMPANY_NAME = 'Nirvana Wealth Advisors (Demo)';
const DEMO_PASSWORD = 'DemoPass@2026';

try {
    $db = getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$existing = $db->prepare('SELECT id FROM tenants WHERE company_name = :name LIMIT 1');
$existing->execute([':name' => DEMO_COMPANY_NAME]);
if ($row = $existing->fetch()) {
    echo "Demo firm already exists (tenant id {$row['id']}) — not re-seeding.\n";
    echo "Sign in as an advisor with any *.advisor@demo.horizonplan.in / " . DEMO_PASSWORD . "\n";
    exit(0);
}

$db->beginTransaction();
try {
    // ── Firm ────────────────────────────────────────────────────────────────
    $db->prepare(
        "INSERT INTO tenants (company_name, advisory_mode, white_label_settings)
         VALUES (:name, 'distribution', :wl)"
    )->execute([
        ':name' => DEMO_COMPANY_NAME,
        ':wl'   => json_encode(['company_name' => 'Nirvana Wealth', 'primary_color' => '#0f766e']),
    ]);
    $tenantId = (int) $db->lastInsertId();
    echo "[✓] Created demo firm (tenant id {$tenantId})\n";

    function makeUser(PDO $db, int $tenantId, string $email, string $role): int
    {
        $stmt = $db->prepare(
            'INSERT INTO users (tenant_id, email, password_hash, role) VALUES (:t, :e, :h, :r)'
        );
        $stmt->execute([
            ':t' => $tenantId,
            ':e' => $email,
            ':h' => password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT),
            ':r' => $role,
        ]);
        return (int) $db->lastInsertId();
    }

    // ── Advisors ────────────────────────────────────────────────────────────
    $advisor1 = makeUser($db, $tenantId, 'meera.advisor@demo.horizonplan.in', 'advisor');
    $advisor2 = makeUser($db, $tenantId, 'arjun.advisor@demo.horizonplan.in', 'advisor');
    echo "[✓] Created 2 advisors\n";

    // ── Clients ─────────────────────────────────────────────────────────────
    $rohan  = makeUser($db, $tenantId, 'rohan.client@demo.horizonplan.in', 'client');
    $priya  = makeUser($db, $tenantId, 'priya.client@demo.horizonplan.in', 'client');
    $sanjay = makeUser($db, $tenantId, 'sanjay.client@demo.horizonplan.in', 'client');
    $anita  = makeUser($db, $tenantId, 'anita.client@demo.horizonplan.in', 'client');
    $karan  = makeUser($db, $tenantId, 'karan.client@demo.horizonplan.in', 'client');
    echo "[✓] Created 5 clients\n";

    function makeGoal(PDO $db, int $tenantId, int $clientId, array $fields): int
    {
        $fields['tenant_id'] = $tenantId;
        $fields['client_id'] = $clientId;
        $columns = array_keys($fields);
        $placeholders = array_map(fn($c) => ":$c", $columns);
        $stmt = $db->prepare(
            'INSERT INTO base_plans (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute(array_combine($placeholders, array_values($fields)));
        return (int) $db->lastInsertId();
    }

    // Rohan — the flagship demo goal: full accumulation + decumulation +
    // liquid/locked corpus composition, still 16 years from retirement.
    $rohanRetirement = makeGoal($db, $tenantId, $rohan, [
        'goal_type'                => 'retirement',
        'goal_label'                => "Rohan's Retirement",
        'initial_net_worth'        => 9000000.00,
        'inflation_rate'           => 6.00,
        'withdrawal_rate'          => 3.50,
        'drawdown_return_rate'     => 7.50,
        'accumulation_return_rate' => 10.00,
        'current_age'              => 42,
        'retirement_age'           => 58,
        'monthly_sip_amount'       => 45000.00,
        'sip_step_up_rate'         => 8.00,
        'liquid_corpus_amount'     => 6000000.00,
        'locked_corpus_amount'     => 3000000.00,
        'locked_return_rate'       => 7.00,
        'projection_horizon_years' => 35,
    ]);

    // Same client, a second goal — demonstrates multi-goal per client.
    makeGoal($db, $tenantId, $rohan, [
        'goal_type'          => 'education',
        'goal_label'          => "Daughter's Education",
        'target_amount'      => 2500000.00,
        'target_date'        => '2032-06-01',
        'initial_net_worth' => 500000.00,
        'inflation_rate'     => 8.00,
    ]);

    // Priya — already retired: decumulation-only, single-bucket corpus.
    // Demonstrates that a goal never needs accumulation/corpus-composition
    // fields to work.
    makeGoal($db, $tenantId, $priya, [
        'goal_type'                => 'retirement',
        'goal_label'                => "Priya's Retirement",
        'initial_net_worth'        => 12000000.00,
        'inflation_rate'           => 6.00,
        'withdrawal_rate'          => 3.50,
        'drawdown_return_rate'     => 6.50,
        'projection_horizon_years' => 30,
    ]);

    // Sanjay — young, long accumulation runway.
    makeGoal($db, $tenantId, $sanjay, [
        'goal_type'                => 'retirement',
        'goal_label'                => "Sanjay's Retirement",
        'initial_net_worth'        => 800000.00,
        'inflation_rate'           => 6.00,
        'withdrawal_rate'          => 3.50,
        'drawdown_return_rate'     => 7.50,
        'accumulation_return_rate' => 11.00,
        'current_age'              => 29,
        'retirement_age'           => 60,
        'monthly_sip_amount'       => 25000.00,
        'sip_step_up_rate'         => 10.00,
        'projection_horizon_years' => 40,
    ]);

    // Anita — a non-retirement goal type, no withdrawal/drawdown fields at all.
    makeGoal($db, $tenantId, $anita, [
        'goal_type'          => 'home_purchase',
        'goal_label'          => "Anita's Home Purchase",
        'target_amount'      => 6000000.00,
        'target_date'        => '2029-01-01',
        'initial_net_worth' => 1500000.00,
        'inflation_rate'     => 6.00,
    ]);

    // Karan — created plain for now; a strategy template gets applied to this
    // goal further down, exercising the real goals_apply_template.php path.
    $karanRetirement = makeGoal($db, $tenantId, $karan, [
        'goal_type'                => 'retirement',
        'goal_label'                => "Karan's Retirement",
        'initial_net_worth'        => 5000000.00,
        'inflation_rate'           => 6.00,
        'withdrawal_rate'          => 3.50,
        'drawdown_return_rate'     => 6.00,
        'projection_horizon_years' => 30,
    ]);
    echo "[✓] Created 6 goals across 5 clients\n";

    // ── Sub-scenarios (what-if variants) ────────────────────────────────────
    // One live (non-overridden) variant — still tracks Rohan's parent goal via
    // the Global Inheritance Engine cascade — and one frozen/overridden
    // variant, so the demo can show both cascade behaviors live.
    $db->prepare(
        "INSERT INTO sub_scenarios (base_plan_id, tenant_id, custom_withdrawal_rate, custom_drawdown_return_rate, is_overridden)
         VALUES (:bp, :t, 3.00, 7.50, 0)"
    )->execute([':bp' => $rohanRetirement, ':t' => $tenantId]);

    $db->prepare(
        "INSERT INTO sub_scenarios (base_plan_id, tenant_id, custom_withdrawal_rate, custom_drawdown_return_rate, is_overridden)
         VALUES (:bp, :t, 4.50, 7.00, 1)"
    )->execute([':bp' => $rohanRetirement, ':t' => $tenantId]);
    echo "[✓] Created 2 sub-scenarios on Rohan's retirement goal (one live, one frozen)\n";

    // ── Client portfolio ledger (Rohan) ─────────────────────────────────────
    $portfolioItems = [
        ['asset', 'liquid', 'mutual_fund', 'Equity MF — Axis/Parag Parikh', 3500000.00],
        ['asset', 'liquid', 'stocks', 'Direct equity holdings', 1200000.00],
        ['asset', 'locked', 'ppf', 'PPF account', 1800000.00],
        ['asset', 'locked', 'epf', 'EPF balance', 1200000.00],
        ['liability', null, 'home_loan', 'Home loan outstanding', 900000.00],
    ];
    $portfolioStmt = $db->prepare(
        'INSERT INTO client_portfolio_items
            (tenant_id, client_id, item_kind, bucket, category, description, value, created_by_user_id)
         VALUES (:t, :c, :kind, :bucket, :cat, :desc, :val, :creator)'
    );
    foreach ($portfolioItems as [$kind, $bucket, $cat, $desc, $val]) {
        $portfolioStmt->execute([
            ':t' => $tenantId, ':c' => $rohan, ':kind' => $kind, ':bucket' => $bucket,
            ':cat' => $cat, ':desc' => $desc, ':val' => $val, ':creator' => $advisor1,
        ]);
    }
    echo "[✓] Seeded Rohan's portfolio ledger (" . count($portfolioItems) . " items)\n";

    // ── Risk questionnaire (approved) + two captured profiles ──────────────
    $questions = [
        ['id' => 'q1', 'text' => 'How would you react to a 20% drop in your portfolio value?', 'options' => [
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
    $rubric = [
        'bands' => [
            ['name' => 'conservative', 'min' => 0, 'max' => 4, 'suggested_return_pct' => 6.0],
            ['name' => 'moderate', 'min' => 5, 'max' => 8, 'suggested_return_pct' => 8.5],
            ['name' => 'aggressive', 'min' => 9, 'max' => 12, 'suggested_return_pct' => 11.0],
        ],
    ];
    $db->prepare(
        "INSERT INTO risk_question_sets
            (tenant_id, questions_json, scoring_rubric_json, status, approved_by_user_id, approved_at, created_by_user_id)
         VALUES (:t, :q, :r, 'approved', :approver, NOW(), :creator)"
    )->execute([
        ':t' => $tenantId, ':q' => json_encode($questions), ':r' => json_encode($rubric),
        ':approver' => $advisor1, ':creator' => $advisor1,
    ]);
    $questionSetId = (int) $db->lastInsertId();

    $profileStmt = $db->prepare(
        'INSERT INTO risk_profiles (tenant_id, client_id, question_set_id, score, band, answers_json, created_by_user_id)
         VALUES (:t, :c, :qs, :score, :band, :answers, :creator)'
    );
    // Rohan: hold(2) + 7_15y(2) + balanced(1) + regularly(2) = 7 → moderate
    $profileStmt->execute([
        ':t' => $tenantId, ':c' => $rohan, ':qs' => $questionSetId, ':score' => 7, ':band' => 'moderate',
        ':answers' => json_encode(['q1' => 'hold', 'q2' => '7_15y', 'q3' => 'balanced', 'q4' => 'regularly']),
        ':creator' => $advisor1,
    ]);
    // Priya: reduce_some(1) + 3_7y(1) + preservation(0) + briefly(1) = 3 → conservative
    $profileStmt->execute([
        ':t' => $tenantId, ':c' => $priya, ':qs' => $questionSetId, ':score' => 3, ':band' => 'conservative',
        ':answers' => json_encode(['q1' => 'reduce_some', 'q2' => '3_7y', 'q3' => 'preservation', 'q4' => 'briefly']),
        ':creator' => $advisor1,
    ]);
    echo "[✓] Seeded an approved risk questionnaire + 2 captured client profiles\n";

    // ── Strategy template, approved and actually applied to a goal ─────────
    // A system template's tenant_id only needs to satisfy the FK constraint —
    // visibility is via is_system_template/is_published, not tenant_id (see
    // TenantScopedDb::selectGlobalPublishedTemplates()) — so find-or-create the
    // "HorizonPlan System" tenant by name, same fix and same reasoning as
    // tools/seed_templates.php (a hardcoded `tenant_id = 0` silently fails: 0
    // in an AUTO_INCREMENT column means "assign the next id", not literal 0,
    // under this project's sql_mode).
    $templateStmt = $db->prepare('SELECT id, return_assumption_pct, template_name FROM template_strategies WHERE is_system_template = 1 LIMIT 1');
    $templateStmt->execute();
    $template = $templateStmt->fetch();

    if (!$template) {
        $sysTenantStmt = $db->prepare("SELECT id FROM tenants WHERE company_name = 'HorizonPlan System' LIMIT 1");
        $sysTenantStmt->execute();
        $sysTenant = $sysTenantStmt->fetch();
        if ($sysTenant) {
            $systemTenantId = (int) $sysTenant['id'];
        } else {
            $db->exec("INSERT INTO tenants (company_name, advisory_mode) VALUES ('HorizonPlan System', 'advisory')");
            $systemTenantId = (int) $db->lastInsertId();
        }

        // No global templates yet (tools/seed_templates.php hasn't been run) —
        // seed the one this demo needs directly, same shape as that script.
        $db->prepare(
            "INSERT INTO template_strategies
                (tenant_id, creator_user_id, creator_org_id, template_name, description, market_code,
                 allocation_json, return_assumption_pct, risk_profile_enum, is_system_template, is_published, published_at)
             VALUES
                (:tenant, NULL, NULL, 'Moderate – Balanced Growth (Age 45–55)',
                 'Balanced equity-debt mix for mid-career professionals. Suitable for 10-20 year horizon.',
                 'IN', :alloc, 8.00, 'moderate', 1, 1, NOW())"
        )->execute([
            ':tenant' => $systemTenantId,
            ':alloc'  => json_encode(['equity' => 40, 'debt' => 40, 'gold' => 15, 'cash' => 5]),
        ]);
        $templateId = (int) $db->lastInsertId();
        $templateReturn = 8.00;
        $templateName = 'Moderate – Balanced Growth (Age 45–55)';
    } else {
        $templateId = (int) $template['id'];
        $templateReturn = (float) $template['return_assumption_pct'];
        $templateName = $template['template_name'];
    }

    // Approve it (mirrors TenantScopedDb::approveGlobalTemplate — a super_admin
    // action in the app; done directly here since this is a seed script, not
    // an HTTP request with a real super_admin session).
    $superAdminStmt = $db->query("SELECT id FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1");
    $superAdmin = $superAdminStmt->fetch();
    $db->prepare(
        "UPDATE template_strategies SET approval_status = 'approved', approved_by_user_id = :uid, approved_at = NOW() WHERE id = :id"
    )->execute([':uid' => $superAdmin ? (int) $superAdmin['id'] : null, ':id' => $templateId]);

    // Apply it to Karan's goal — mirrors exactly what api/goals_apply_template.php
    // does (drawdown_return_rate + applied_template_id + the used_in_plan audit
    // row), so templates_list.php's usage_count reflects a real application.
    $db->prepare(
        'UPDATE base_plans SET drawdown_return_rate = :rate, applied_template_id = :tid WHERE id = :goal'
    )->execute([':rate' => $templateReturn, ':tid' => $templateId, ':goal' => $karanRetirement]);

    $db->prepare(
        "INSERT INTO template_audit_log (tenant_id, template_id, user_id, action, entity_details_json)
         VALUES (:t, :tid, :uid, 'used_in_plan', :details)"
    )->execute([
        ':t' => $tenantId, ':tid' => $templateId, ':uid' => $advisor2,
        ':details' => json_encode(['goal_id' => $karanRetirement, 'source_name' => $templateName, 'applied_drawdown_return_rate' => $templateReturn]),
    ]);
    echo "[✓] Approved '{$templateName}' and applied it to Karan's retirement goal\n";

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\n✔ Demo firm ready: " . DEMO_COMPANY_NAME . "\n";
echo "  Advisors  : meera.advisor@demo.horizonplan.in / arjun.advisor@demo.horizonplan.in\n";
echo "  Clients   : rohan / priya / sanjay / anita / karan .client@demo.horizonplan.in\n";
echo "  Password  : " . DEMO_PASSWORD . " (all demo accounts)\n";
echo "  Sign in as an advisor to walk through goals, scenarios, portfolio, risk profiler, and templates.\n";
