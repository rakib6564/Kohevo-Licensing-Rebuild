<?php
/**
 * Kohevo Studio (studio-builder) — Tenant-Isolated Base Repository.
 *
 * Extends Slate\Data\Repository with strict fail-closed tenant isolation:
 * - Only operates on `studiobuilder_*` tables.
 * - Requires a positive `tenant_id` whenever `TenantContext::isScoped()` is true.
 * - Forces `tenant_id = $this->tenants->id()` on `insert()` in scoped mode so
 *   callers can never spoof a foreign `tenant_id`.
 * - Requires an explicit positive `tenant_id` on `insert()` even in unscoped mode,
 *   aligning with the database-level `tenant_id INT UNSIGNED NOT NULL` constraint.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

use Slate\Data\QueryBuilder;
use Slate\Data\Repository;
use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Tenancy\TenantContext;

abstract class StudioRepository extends Repository
{
    public function __construct(?TenantContext $tenants = null)
    {
        if (!str_starts_with($this->table, StudioSchemaManager::TABLE_PREFIX)) {
            throw new \InvalidArgumentException(
                static::class . " must bind to a '" . StudioSchemaManager::TABLE_PREFIX . "*' table; got '{$this->table}'."
            );
        }

        parent::__construct($tenants);
    }

    /**
     * Expose the bound table name for inspection and testing.
     */
    public function tableName(): string
    {
        return $this->table;
    }

    protected function query(): QueryBuilder
    {
        $this->assertValidTenantScope();
        return parent::query();
    }

    public function find(int|string $id): ?array
    {
        $this->assertValidTenantScope();
        return parent::find($id);
    }

    public function all(array $where = [], ?string $orderBy = null, ?int $limit = null): array
    {
        $qb = $this->query();
        foreach ($where as $col => $val) {
            if ($val === null) {
                $qb->whereNull((string) $col);
            } else {
                $qb->where((string) $col, $val);
            }
        }
        if ($orderBy !== null && trim($orderBy) !== '') {
            $parts = preg_split('/\s+/', trim($orderBy), 2);
            $col   = $parts[0] ?? 'id';
            $dir   = $parts[1] ?? 'ASC';
            $qb->orderBy($col, $dir);
        }
        if ($limit !== null) {
            $qb->limit($limit);
        }
        return array_map([$this, 'hydrate'], $qb->get());
    }

    public function count(array $where = []): int
    {
        $qb = $this->query();
        foreach ($where as $col => $val) {
            if ($val === null) {
                $qb->whereNull((string) $col);
            } else {
                $qb->where((string) $col, $val);
            }
        }
        return $qb->count();
    }

    public function insert(array $data): int
    {
        $this->assertValidTenantScope();

        if ($this->tenants->isScoped()) {
            // Force active tenant_id in scoped mode to prevent cross-tenant spoofing.
            $data['tenant_id'] = $this->tenants->id();
        } else {
            $explicitTenantId = $data['tenant_id'] ?? null;
            if (!is_int($explicitTenantId) || $explicitTenantId <= 0) {
                throw new \InvalidArgumentException(
                    static::class . '::insert() in unscoped mode requires an explicit positive integer tenant_id.'
                );
            }
        }

        return parent::insert($data);
    }

    public function update(int|string $id, array $data): int
    {
        $this->assertValidTenantScope();
        // Never allow updating tenant_id on an existing Studio record.
        unset($data['tenant_id']);
        return parent::update($id, $data);
    }

    public function delete(int|string $id): int
    {
        $this->assertValidTenantScope();
        return parent::delete($id);
    }

    /**
     * Fail closed if scoped mode has a non-positive tenant ID.
     */
    protected function assertValidTenantScope(): void
    {
        if ($this->tenants->isScoped() && $this->tenants->id() <= 0) {
            throw new \RuntimeException(
                static::class . ' requires a valid positive tenant_id in scoped mode.'
            );
        }
    }
}
