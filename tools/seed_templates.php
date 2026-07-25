<?php
declare(strict_types=1);

/**
 * Bootstrap script: seed global strategy templates after Phase 1 migrations.
 * Run once on Hostinger via SSH or CLI after running sql/010-012 migrations:
 *   php tools/seed_templates.php
 *
 * Creates 5 realistic Indian retirement strategy templates with proper
 * allocation, return assumptions, and risk profiles for testing + demo.
 */

require_once __DIR__ . '/../api/db_config.php';
require_once __DIR__ . '/../api/lib/TenantScopedDb.php';

$db = getPdo();

// A system template's tenant_id only has to satisfy the FK constraint on
// template_strategies — visibility to every tenant comes from
// is_system_template=1/is_published=1 (see TenantScopedDb::selectGlobalPublishedTemplates(),
// which has no tenant_id filter at all), not from which tenant_id the row
// happens to carry. So this just needs *some* real tenant row to reference,
// found or created by name rather than assumed to be a magic ID 0 — tenants.id
// is AUTO_INCREMENT, and MySQL treats an explicit 0 there as "assign the next
// value" (not "use literal 0") unless NO_AUTO_VALUE_ON_ZERO is in sql_mode, so
// a hardcoded `VALUES (0, ...)` silently creates a different-numbered tenant
// and every later `tenant_id = 0` reference then fails its FK check.
$tenantCheck = $db->prepare("SELECT id FROM tenants WHERE company_name = 'HorizonPlan System' LIMIT 1");
$tenantCheck->execute();
$systemTenant = $tenantCheck->fetch();
if ($systemTenant) {
    $systemTenantId = (int) $systemTenant['id'];
} else {
    $db->exec("INSERT INTO tenants (company_name, advisory_mode) VALUES ('HorizonPlan System', 'advisory')");
    $systemTenantId = (int) $db->lastInsertId();
    echo "[✓] Created system tenant (id {$systemTenantId})\n";
}

// Seed data: realistic Indian retirement strategy templates
$templates = [
    [
        'template_name'       => 'Conservative – Capital Preservation (Age 55+)',
        'description'         => 'Low-risk portfolio for near-retirees and retirees. Prioritizes capital preservation with modest growth. Suitable for 5–10 year horizon.',
        'allocation_json'     => json_encode(['debt' => 60, 'gold' => 20, 'cash' => 20, 'equity' => 0]),
        'return_assumption'   => 5.5,
        'risk_profile'        => 'conservative',
        'market_code'         => 'IN',
    ],
    [
        'template_name'       => 'Moderate – Balanced Growth (Age 45–55)',
        'description'         => 'Balanced equity–debt mix for mid-career professionals. Balances growth and stability. Suitable for 10–20 year horizon.',
        'allocation_json'     => json_encode(['equity' => 40, 'debt' => 40, 'gold' => 15, 'cash' => 5]),
        'return_assumption'   => 8.0,
        'risk_profile'        => 'moderate',
        'market_code'         => 'IN',
    ],
    [
        'template_name'       => 'Balanced – Moderate Growth (Age 35–45)',
        'description'         => 'Higher equity exposure for younger professionals with 20+ year horizon. Captures long-term growth while diversifying risk.',
        'allocation_json'     => json_encode(['equity' => 60, 'debt' => 25, 'gold' => 10, 'cash' => 5]),
        'return_assumption'   => 9.5,
        'risk_profile'        => 'balanced',
        'market_code'         => 'IN',
    ],
    [
        'template_name'       => 'Aggressive – Growth Focused (Age 25–35)',
        'description'         => 'Equity-heavy for young professionals with 25+ year horizon to retirement. Emphasises capital appreciation and long-term compounding.',
        'allocation_json'     => json_encode(['equity' => 75, 'debt' => 15, 'gold' => 8, 'cash' => 2]),
        'return_assumption'   => 11.0,
        'risk_profile'        => 'aggressive',
        'market_code'         => 'IN',
    ],
    [
        'template_name'       => 'Ultra-Aggressive – Maximum Growth (Age 20–30)',
        'description'         => 'All-in equity for early-career professionals with 30+ year horizon. Maximum long-term capital appreciation potential.',
        'allocation_json'     => json_encode(['equity' => 90, 'debt' => 7, 'gold' => 2, 'cash' => 1]),
        'return_assumption'   => 12.5,
        'risk_profile'        => 'aggressive',
        'market_code'         => 'IN',
    ],
];

// Create templates as system (super_admin tenant 0, creator_user_id NULL, is_system_template=1, is_published=1)
$created = 0;
foreach ($templates as $t) {
    $stmt = $db->prepare(
        "INSERT INTO template_strategies
            (tenant_id, creator_user_id, creator_org_id, template_name, description, market_code,
             allocation_json, return_assumption_pct, risk_profile_enum, is_system_template, is_published, published_at)
         VALUES
            (:tenant_id, NULL, NULL, :name, :desc, :market, :alloc, :return, :risk, 1, 1, NOW())"
    );
    $stmt->execute([
        ':tenant_id' => $systemTenantId,
        ':name'      => $t['template_name'],
        ':desc'      => $t['description'],
        ':market'    => $t['market_code'],
        ':alloc'     => $t['allocation_json'],
        ':return'    => $t['return_assumption'],
        ':risk'      => $t['risk_profile'],
    ]);
    $created++;
    echo "[✓] Created: {$t['template_name']}\n";
}

echo "\n✔ Seeded $created global strategy templates.\n";
echo "   Advisors can now see these in the Global Library tab and fork/customize them.\n";
