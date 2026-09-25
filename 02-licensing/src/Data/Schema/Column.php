<?php
/**
 * Slate — Schema Column definition.
 *
 * A fluent, immutable-ish column spec used by Table to build DDL. Migration
 * authors don't construct it directly — they call Table helpers (string(), int(),
 * datetime(), …) which return a Column for further modifier chaining.
 *
 * Compiles to a MySQL column definition fragment. Pure (no DB): the migration
 * runner strings these together; unit tests assert the compiled SQL.
 *
 * Layer: Data. Depends on nothing outside Support.
 */

declare(strict_types=1);

namespace Slate\Data\Schema;

final class Column
{
    private bool $nullable = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private bool $defaultIsRaw = false;
    private ?string $after = null;
    private ?string $comment = null;

    public function __construct(
        public readonly string $name,
        private readonly string $type,
    ) {}

    public function nullable(bool $nullable = true): self { $this->nullable = $nullable; return $this; }
    public function unsigned(bool $unsigned = true): self { $this->unsigned = $unsigned; return $this; }
    public function autoIncrement(bool $on = true): self { $this->autoIncrement = $on; return $this; }
    public function after(string $column): self { $this->after = $column; return $this; }
    public function comment(string $comment): self { $this->comment = $comment; return $this; }

    /** A literal default (string is quoted, bool → 0/1, null → NULL). */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        $this->defaultIsRaw = false;
        return $this;
    }

    /** A raw SQL default expression, e.g. CURRENT_TIMESTAMP (not quoted). */
    public function defaultRaw(string $expression): self
    {
        $this->hasDefault = true;
        $this->default = $expression;
        $this->defaultIsRaw = true;
        return $this;
    }

    /** Shorthand for DEFAULT CURRENT_TIMESTAMP. */
    public function useCurrent(): self
    {
        return $this->defaultRaw('CURRENT_TIMESTAMP');
    }

    public function isAutoIncrement(): bool { return $this->autoIncrement; }

    /** Compile to a `name` TYPE … column definition fragment. */
    public function compile(): string
    {
        $sql = '`' . $this->name . '` ' . $this->type;
        if ($this->unsigned) {
            $sql .= ' UNSIGNED';
        }
        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';
        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->renderDefault();
        }
        if ($this->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }
        if ($this->comment !== null) {
            $sql .= " COMMENT '" . str_replace("'", "''", $this->comment) . "'";
        }
        if ($this->after !== null) {
            $sql .= ' AFTER `' . $this->after . '`';
        }
        return $sql;
    }

    private function renderDefault(): string
    {
        if ($this->defaultIsRaw) {
            return (string) $this->default;
        }
        if ($this->default === null) {
            return 'NULL';
        }
        if (is_bool($this->default)) {
            return $this->default ? '1' : '0';
        }
        if (is_int($this->default) || is_float($this->default)) {
            return (string) $this->default;
        }
        return "'" . str_replace("'", "''", (string) $this->default) . "'";
    }
}
