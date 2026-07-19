<?php
declare(strict_types=1);

/**
 * Every endpoint that touches tenant-scoped data (base_plans, sub_scenarios,
 * users, change_log) must go through this class instead of writing raw SQL
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
    private const ALLOWED_TABLES = ['base_plans', 'sub_scenarios', 'change_log', 'users'];

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
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
}
