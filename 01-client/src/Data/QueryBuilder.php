<?php
/**
 * Slate — QueryBuilder (fluent, prepared).
 *
 * A small, safe SQL builder used by the base Repository. Every value is bound as
 * a positional parameter (never interpolated); identifiers are validated and
 * backtick-quoted. It builds SQL as pure data — toSelectSql()/bindings() have no
 * side effects and are unit-tested without a database — and executes terminals
 * (get/first/value/count/insert/update/delete) through the Data-layer Database
 * connection.
 *
 * It is deliberately NOT a full ORM: enough for tenant-scoped reads/writes over
 * one table (platform-foundation §8 marks Data internals below the Repository
 * contract as internal, free to grow).
 *
 * Layer: Data — MAY depend on Support/Contracts/Tenancy/Data only. It references
 * no service, kernel, or presentation code.
 */

declare(strict_types=1);

namespace Slate\Data;

final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var string[] selected columns (already validated) */
    private array $columns = ['*'];

    /** @var array<int, array{sql:string, bindings:array}> WHERE fragments */
    private array $wheres = [];

    /** @var array<int, string> ORDER BY fragments */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(private readonly string $table)
    {
        $this->assertIdentifier($table);
    }

    public static function table(string $table): self
    {
        return new self($table);
    }

    // ── Fluent builders ───────────────────────────────────────

    public function select(string ...$columns): self
    {
        $cols = $columns === [] ? ['*'] : $columns;
        foreach ($cols as $c) {
            if ($c !== '*') {
                $this->assertIdentifier($c);
            }
        }
        $this->columns = $cols;
        return $this;
    }

    /**
     * where('status', 'paid')            → status = ?
     * where('paid_at', '>=', $timestamp) → paid_at >= ?
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $this->assertIdentifier($column);
        if (func_num_args() === 2) {
            $operator = '=';
            $value    = $operatorOrValue;
        } else {
            $operator = strtoupper((string) $operatorOrValue);
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new \InvalidArgumentException("Unsupported SQL operator '{$operator}'.");
            }
        }
        $this->wheres[] = ['sql' => $this->quote($column) . ' ' . $operator . ' ?', 'bindings' => [$value]];
        return $this;
    }

    /** where col IN (…). An empty set matches nothing (1 = 0), never everything. */
    public function whereIn(string $column, array $values): self
    {
        $this->assertIdentifier($column);
        if ($values === []) {
            $this->wheres[] = ['sql' => '1 = 0', 'bindings' => []];
            return $this;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = ['sql' => $this->quote($column) . ' IN (' . $placeholders . ')', 'bindings' => array_values($values)];
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = ['sql' => $this->quote($column) . ' IS NULL', 'bindings' => []];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = ['sql' => $this->quote($column) . ' IS NOT NULL', 'bindings' => []];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertIdentifier($column);
        $dir = strtoupper($direction);
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            throw new \InvalidArgumentException("ORDER BY direction must be ASC or DESC, got '{$direction}'.");
        }
        $this->orders[] = $this->quote($column) . ' ' . $dir;
        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('LIMIT must be >= 0.');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('OFFSET must be >= 0.');
        }
        $this->offset = $offset;
        return $this;
    }

    // ── SQL generation (pure — no side effects) ───────────────

    public function toSelectSql(): string
    {
        $cols = implode(', ', array_map(
            fn (string $c) => $c === '*' ? '*' : $this->quote($c),
            $this->columns
        ));
        return 'SELECT ' . $cols . ' FROM ' . $this->quote($this->table)
            . $this->whereClause() . $this->orderClause() . $this->limitClause();
    }

    public function toCountSql(): string
    {
        return 'SELECT COUNT(*) FROM ' . $this->quote($this->table) . $this->whereClause();
    }

    public function toInsertSql(array $data): string
    {
        $this->assertNotEmpty($data, 'insert');
        $cols = implode(', ', array_map(fn ($k) => $this->quote((string) $k), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        return 'INSERT INTO ' . $this->quote($this->table) . ' (' . $cols . ') VALUES (' . $placeholders . ')';
    }

    public function toUpdateSql(array $data): string
    {
        $this->assertNotEmpty($data, 'update');
        $set = implode(', ', array_map(fn ($k) => $this->quote((string) $k) . ' = ?', array_keys($data)));
        return 'UPDATE ' . $this->quote($this->table) . ' SET ' . $set . $this->whereClause();
    }

    public function toDeleteSql(): string
    {
        return 'DELETE FROM ' . $this->quote($this->table) . $this->whereClause();
    }

    /** Bindings for the WHERE clause, in order (shared by select/count/delete/update). */
    public function whereBindings(): array
    {
        $out = [];
        foreach ($this->wheres as $w) {
            foreach ($w['bindings'] as $b) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /** Full binding list for an UPDATE: SET values first, then WHERE bindings. */
    public function updateBindings(array $data): array
    {
        return array_merge(array_values($data), $this->whereBindings());
    }

    // ── Terminals (execute via the Data-layer connection) ─────

    public function get(): array
    {
        return Database::rows($this->toSelectSql(), $this->whereBindings());
    }

    public function first(): ?array
    {
        $row = Database::row($this->copyWithLimit(1)->toSelectSql(), $this->whereBindings());
        return $row;
    }

    public function value(string $column)
    {
        $clone = $this->copyWithLimit(1);
        $clone->select($column);
        return Database::value($clone->toSelectSql(), $clone->whereBindings());
    }

    public function count(): int
    {
        return (int) Database::value($this->toCountSql(), $this->whereBindings());
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function insert(array $data): int
    {
        Database::query($this->toInsertSql($data), array_values($data));
        return (int) Database::get()->lastInsertId();
    }

    public function update(array $data): int
    {
        return Database::query($this->toUpdateSql($data), $this->updateBindings($data))->rowCount();
    }

    public function delete(): int
    {
        return Database::query($this->toDeleteSql(), $this->whereBindings())->rowCount();
    }

    // ── Internals ─────────────────────────────────────────────

    private function whereClause(): string
    {
        if ($this->wheres === []) {
            return '';
        }
        return ' WHERE ' . implode(' AND ', array_map(fn ($w) => $w['sql'], $this->wheres));
    }

    private function orderClause(): string
    {
        return $this->orders === [] ? '' : ' ORDER BY ' . implode(', ', $this->orders);
    }

    private function limitClause(): string
    {
        $sql = '';
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /** A shallow clone with LIMIT applied — used by first()/value() without mutating $this. */
    private function copyWithLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    private function quote(string $identifier): string
    {
        // Supports an optional single `alias.column` qualifier.
        return implode('.', array_map(
            fn (string $part) => '`' . $part . '`',
            explode('.', $identifier)
        ));
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier '{$identifier}'.");
        }
    }

    private function assertNotEmpty(array $data, string $op): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException("Cannot build an {$op} with no columns.");
        }
    }
}
