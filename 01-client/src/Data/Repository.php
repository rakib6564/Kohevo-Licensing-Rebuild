<?php
/**
 * Slate — base Repository (automatic tenant scoping).
 *
 * Where invariant #2 (tenant isolation) becomes STRUCTURAL rather than remembered
 * (repository-service-pattern §2, multi-tenancy §2). A repository owns one
 * entity's queries; every read and write it issues is scoped to the current
 * tenant automatically, so an author writing $this->all() *cannot* forget the
 * `AND tenant_id = ?` predicate — they never write it.
 *
 * Crossing tenants is possible only through crossTenant(): a single, greppable,
 * audited escape hatch. `grep crossTenant` yields the complete list of every
 * place isolation is deliberately lifted.
 *
 * Layer: Data — depends on Data (QueryBuilder), Tenancy (TenantContext),
 * Support only. It does NOT depend on any Service (that would violate §3), so the
 * cross-tenant audit is delivered through an INJECTED sink callable rather than a
 * hard reference to the AuditLog service. The composition root (or a module's
 * provider) wires the sink to AuditLog; unset, crossTenant() still lifts scope
 * and stays greppable but logs nothing.
 */

declare(strict_types=1);

namespace Slate\Data;

use Slate\Tenancy\TenantContext;

abstract class Repository
{
    /** The table this repository owns. Subclasses MUST set it. */
    protected string $table;

    /** The tenant-scoping column (override only for legacy tables that differ). */
    protected string $tenantColumn = 'tenant_id';

    /** Optional Entity subclass FQCN to hydrate rows into; null = raw arrays. */
    protected ?string $entity = null;

    /**
     * @param TenantContext $tenants the current-tenant source of truth
     * @param null|callable(string $event, string $context): void $auditSink
     *        invoked by crossTenant() to record the escape; null = no audit
     */
    public function __construct(
        protected readonly TenantContext $tenants,
        private $auditSink = null,
    ) {}

    // ── Reads (tenant-scoped) ─────────────────────────────────

    /** Find one row by primary key within the current tenant. */
    public function find(int $id): array|Entity|null
    {
        $row = $this->query()->where('id', $id)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** All rows for the current tenant. */
    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->query()->get());
    }

    /** Count rows for the current tenant. */
    public function count(): int
    {
        return $this->query()->count();
    }

    // ── Writes (tenant-scoped) ────────────────────────────────

    /** Insert a row, stamping the current tenant_id when scoped and not supplied. */
    public function insert(array $data): int
    {
        return QueryBuilder::table($this->table)->insert($this->withTenantStamp($data));
    }

    /** Update one row by primary key within the current tenant. */
    public function update(int $id, array $data): int
    {
        return $this->query()->where('id', $id)->update($data);
    }

    /** Delete one row by primary key within the current tenant. */
    public function delete(int $id): int
    {
        return $this->query()->where('id', $id)->delete();
    }

    // ── The one, audited way to escape tenant scope ───────────

    /**
     * Run $fn with tenant scoping lifted — the ONLY sanctioned way to read/write
     * across tenants (platform admin rollups, billing). Records an audit entry via
     * the injected sink (if any) and is greppable by name. Returns $fn()'s value.
     */
    public function crossTenant(callable $fn): mixed
    {
        if ($this->auditSink !== null) {
            ($this->auditSink)('data.cross_tenant', static::class);
        }
        return $this->tenants->withoutScope($fn);
    }

    // ── Internals ─────────────────────────────────────────────

    /**
     * A query builder for this table, tenant-scoped unless scoping is currently
     * suspended (i.e. inside a crossTenant block).
     */
    protected function query(): QueryBuilder
    {
        $qb = QueryBuilder::table($this->table);
        if ($this->tenants->isScoped()) {
            $qb->where($this->tenantColumn, $this->tenants->id());
        }
        return $qb;
    }

    /** Stamp tenant_id into insert data when scoped and not already present. */
    protected function withTenantStamp(array $data): array
    {
        if ($this->tenants->isScoped() && !array_key_exists($this->tenantColumn, $data)) {
            $data[$this->tenantColumn] = $this->tenants->id();
        }
        return $data;
    }

    /** Wrap a raw row into the configured Entity, or return it unchanged. */
    protected function hydrate(array $row): array|Entity
    {
        if ($this->entity !== null) {
            /** @var class-string<Entity> $cls */
            $cls = $this->entity;
            return $cls::fromRow($row);
        }
        return $row;
    }
}
