<?php
declare(strict_types=1);

/**
 * Every endpoint that touches tenant-scoped data (base_plans, sub_scenarios,
 * users, change_log, template_strategies, template_customizations,
 * template_audit_log, risk_question_sets, risk_profiles,
 * client_portfolio_items) must go through this class instead of writing raw SQL
 * with a hand-typed "WHERE tenant_id = ..." clause. See docs/02 Section 3.1:
 * the whole point is that a forgotten WHERE clause in a future endpoint file
 * is a cross-tenant data leak, and centralizing this closes that off structurally
 * rather than relying on every future developer remembering to add it.
 *
 * Tenant ID is bound once, from the verified session, at construction time —
 * there is no method on this class that accepts a caller-supplied tenant_id,
 * so calling code has no path to bypass it, accidentally or otherwise.
 */
final class TenantScopedDb
{
    private PDO $db;
    private int $tenantId;

    /** @var string[] tables this class is allowed to touch — guards against typos silently querying an unscoped table */
    private const ALLOWED_TABLES = [
        'base_plans', 'sub_scenarios', 'change_log', 'users',
        'template_strategies', 'template_customizations', 'template_audit_log',
        'risk_question_sets', 'risk_profiles', 'client_portfolio_items',
        'plan_review_schedules', 'households', 'cash_flow_items',
        'goal_snapshots', 'client_net_worth_snapshots', 'client_protection',
        'client_context', 'client_dependants', 'product_events',
    ];

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    /**
     * Which tenant this instance is bound to.
     *
     * Read-only on purpose — there is no setter, and there must never be one.
     * The whole isolation guarantee rests on the tenant being fixed at
     * construction, so a helper that could be re-pointed mid-request would
     * undo it. Exposed so a caller that already holds a scoped instance can
     * ask a tenant-level question (e.g. tenantIsPersonal()) without carrying
     * the id separately and risking the two drifting apart.
     */
    public function tenantId(): int
    {
        return $this->tenantId;
    }

    private function assertAllowedTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException("TenantScopedDb: '$table' is not a tenant-scoped table this helper is allowed to touch.");
        }
    }

    /**
     * @param array<string,mixed> $conditions additional WHERE conditions, ANDed with the tenant scope
     * @return array<int,array<string,mixed>>
     */
    public function select(string $table, array $conditions = [], string $columns = '*'): array
    {
        $this->assertAllowedTable($table);

        $where = ['tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];

        foreach ($conditions as $column => $value) {
            $placeholder = ':cond_' . $column;
            $where[] = "$column = $placeholder";
            $params[$placeholder] = $value;
        }

        $sql = "SELECT $columns FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $data — tenant_id is injected automatically; if the
     *   caller passes a 'tenant_id' key, it is overwritten, not trusted.
     * @return int the inserted row's ID
     */
    public function insert(string $table, array $data): int
    {
        $this->assertAllowedTable($table);

        $data['tenant_id'] = $this->tenantId; // always overwrite, never trust caller input here

        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_combine($placeholders, array_values($data)));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data fields to update
     * @param array<string,mixed> $conditions additional WHERE conditions, ANDed with the tenant scope
     * @return int number of rows affected
     */
    public function update(string $table, array $data, array $conditions): int
    {
        $this->assertAllowedTable($table);

        if (empty($conditions)) {
            // Refuse to run a tenant-wide update with no row-level condition —
            // almost certainly a bug in the caller, not an intended bulk update.
            throw new InvalidArgumentException('TenantScopedDb::update requires at least one condition beyond tenant scope.');
        }

        unset($data['tenant_id']); // never allow moving a row to a different tenant via update

        $set = [];
        $params = [':tenant_id' => $this->tenantId];

        foreach ($data as $column => $value) {
            $placeholder = ':set_' . $column;
            $set[] = "$column = $placeholder";
            $params[$placeholder] = $value;
        }

        $where = ['tenant_id = :tenant_id'];
        foreach ($conditions as $column => $value) {
            $placeholder = ':cond_' . $column;
            $where[] = "$column = $placeholder";
            $params[$placeholder] = $value;
        }

        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $conditions
     */
    public function delete(string $table, array $conditions): int
    {
        $this->assertAllowedTable($table);

        if (empty($conditions)) {
            throw new InvalidArgumentException('TenantScopedDb::delete requires at least one condition beyond tenant scope.');
        }

        $where = ['tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];

        foreach ($conditions as $column => $value) {
            $placeholder = ':cond_' . $column;
            $where[] = "$column = $placeholder";
            $params[$placeholder] = $value;
        }

        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Section 3.3: every mutation to base_plans/sub_scenarios writes here.
     * Deliberately takes the changed-by user ID explicitly rather than pulling
     * it from global state, so it's always traceable to the verified session
     * that made the call.
     */
    public function logChange(
        string $entityType,
        int $entityId,
        string $fieldChanged,
        ?string $oldValue,
        ?string $newValue,
        int $changedByUserId
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO change_log
                (tenant_id, entity_type, entity_id, field_changed, old_value, new_value, changed_by_user_id)
             VALUES
                (:tenant_id, :entity_type, :entity_id, :field_changed, :old_value, :new_value, :changed_by_user_id)"
        );
        $stmt->execute([
            ':tenant_id'         => $this->tenantId,
            ':entity_type'       => $entityType,
            ':entity_id'         => $entityId,
            ':field_changed'     => $fieldChanged,
            ':old_value'         => $oldValue,
            ':new_value'         => $newValue,
            ':changed_by_user_id' => $changedByUserId,
        ]);
    }

    // --- Strategy Templates (Phase 1) -------------------------------------
    // The template system needs cross-tenant reads that don't fit this
    // class's normal contract — a "global" template is by definition visible
    // outside the tenant that owns its row, and forking needs to read a
    // template that may live in someone else's tenant to check whether it's
    // eligible to fork. The methods below are the deliberate, narrow
    // exceptions to tenant scoping in this class. Each is scoped to a single
    // table and documented at the point of use for why it's safe: read
    // exceptions return either aggregate counts only or rows already flagged
    // is_published = 1; the one write exception (approveGlobalTemplate) is
    // restricted to is_system_template = 1 rows and the caller must have
    // already verified the session is super_admin before calling it.

    /**
     * Fetch one template_strategies row by ID with NO tenant filter. Needed
     * because fork-eligibility checks must be able to see a template that
     * lives in a different tenant (a global SaaS template, or another
     * advisor's published template). Callers MUST check is_published /
     * tenant ownership themselves before trusting the row for anything
     * beyond that eligibility check — this method does not enforce it.
     */
    public function findTemplateStrategyById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM template_strategies WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Cross-tenant by design: every tenant must be able to see globally
     * published templates. Restricted to is_system_template = 1 AND
     * is_published = 1 — never returns a draft or a private advisor-created
     * template, so it can't be used to leak another tenant's unpublished work.
     *
     * @return array<int,array<string,mixed>>
     */
    public function selectGlobalPublishedTemplates(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM template_strategies WHERE is_system_template = 1 AND is_published = 1'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Aggregate-only cross-tenant read: returns a COUNT, never row data, so
     * it can't leak which other tenants used a global template — only how
     * many times, which is the point of a usage-count stat on a template
     * shared across every tenant.
     */
    public function countGlobalTemplateUsage(int $templateId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM template_audit_log WHERE template_id = :id AND action = 'used_in_plan'"
        );
        $stmt->execute([':id' => $templateId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Tenant-scoped usage count for the caller's own templates/customizations
     * — how many times THIS tenant has used a given template/customization
     * in a plan, per the template_audit_log 'used_in_plan' action.
     */
    public function countTemplateUsage(?int $templateId = null, ?int $customizationId = null): int
    {
        $where = ['tenant_id = :tenant_id', "action = 'used_in_plan'"];
        $params = [':tenant_id' => $this->tenantId];

        if ($templateId !== null) {
            $where[] = 'template_id = :template_id';
            $params[':template_id'] = $templateId;
        }
        if ($customizationId !== null) {
            $where[] = 'customization_id = :customization_id';
            $params[':customization_id'] = $customizationId;
        }

        $sql = 'SELECT COUNT(*) FROM template_audit_log WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * docs/07 Bet 1: approve a global (is_system_template=1) template. This
     * is the one deliberate WRITE exception to tenant scoping in this class —
     * a system template's row lives under whichever tenant its creating
     * super_admin belonged to, which is not necessarily the tenant of the
     * super_admin approving it later (there is no reserved "tenant 0"; see
     * docs/02 §3.1 for why every table still carries a real tenant_id).
     * Restricted with a WHERE is_system_template = 1 guard so it can never be
     * used to reach across into an advisor-owned (non-system) row — approving
     * those goes through the normal tenant-scoped update() instead. Callers
     * MUST verify the acting session's role === 'super_admin' before calling
     * this; it enforces the table-shape restriction, not the caller's role.
     */
    public function approveGlobalTemplate(int $templateId, int $approvedByUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE template_strategies
                SET approval_status = 'approved', approved_by_user_id = :uid, approved_at = NOW()
              WHERE id = :id AND is_system_template = 1"
        );
        $stmt->execute([':uid' => $approvedByUserId, ':id' => $templateId]);
    }
}
