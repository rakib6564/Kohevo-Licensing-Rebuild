<?php
/**
 * Slate — Schema Table builder.
 *
 * Collects columns, keys, and (in ALTER mode) drop operations, then compiles to
 * one CREATE TABLE or one ALTER TABLE statement. Migration authors receive a
 * Table inside Schema::create()/table() and describe the change fluently:
 *
 *   $s->create('shop_orders', function (Table $t) {
 *       $t->id();
 *       $t->int('tenant_id')->unsigned()->default(1);
 *       $t->string('status', 32)->default('pending');
 *       $t->bigInt('total_minor');                 // Money = integer minor units
 *       $t->datetime('created_at')->useCurrent();
 *       $t->index('tenant_id');
 *   });
 *
 * Pure (no DB): unit tests assert the compiled SQL. Money columns are integer
 * minor units (bigInt) per ADR-0011 — never DECIMAL.
 *
 * Layer: Data.
 */

declare(strict_types=1);

namespace Slate\Data\Schema;

final class Table
{
    public const MODE_CREATE = 'create';
    public const MODE_ALTER  = 'alter';

    /** @var Column[] columns to add (create: all columns; alter: ADD COLUMN) */
    private array $columns = [];

    /** @var array<int,array{type:string,columns:string[],name:?string}> keys */
    private array $keys = [];

    /** @var string[] columns to DROP (alter mode) */
    private array $dropColumns = [];

    public function __construct(
        public readonly string $name,
        private readonly string $mode = self::MODE_CREATE,
        private readonly string $engine = 'InnoDB',
        private readonly string $charset = 'utf8mb4',
        private readonly string $collation = 'utf8mb4_unicode_ci',
    ) {}

    // ── Column helpers ────────────────────────────────────────

    /** Auto-incrementing unsigned BIGINT primary key (default name 'id'). */
    public function id(string $name = 'id'): Column
    {
        $col = $this->addColumn($name, 'BIGINT')->unsigned()->autoIncrement();
        $this->primary($name);
        return $col;
    }

    public function bigInt(string $name): Column   { return $this->addColumn($name, 'BIGINT'); }
    public function int(string $name): Column      { return $this->addColumn($name, 'INT'); }
    public function tinyInt(string $name): Column  { return $this->addColumn($name, 'TINYINT'); }
    public function boolean(string $name): Column  { return $this->addColumn($name, 'TINYINT(1)'); }

    public function string(string $name, int $length = 255): Column
    {
        return $this->addColumn($name, "VARCHAR($length)");
    }

    public function char(string $name, int $length): Column
    {
        return $this->addColumn($name, "CHAR($length)");
    }

    public function text(string $name): Column       { return $this->addColumn($name, 'TEXT'); }
    public function mediumText(string $name): Column { return $this->addColumn($name, 'MEDIUMTEXT'); }
    public function longText(string $name): Column   { return $this->addColumn($name, 'LONGTEXT'); }
    public function json(string $name): Column       { return $this->addColumn($name, 'JSON'); }
    public function date(string $name): Column       { return $this->addColumn($name, 'DATE'); }
    public function datetime(string $name): Column   { return $this->addColumn($name, 'DATETIME'); }
    public function timestamp(string $name): Column  { return $this->addColumn($name, 'TIMESTAMP'); }

    /** DECIMAL is permitted for non-money quantities only (money = bigInt minor units). */
    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->addColumn($name, "DECIMAL($precision,$scale)");
    }

    public function enum(string $name, array $values): Column
    {
        $list = implode(', ', array_map(fn ($v) => "'" . str_replace("'", "''", (string) $v) . "'", $values));
        return $this->addColumn($name, "ENUM($list)");
    }

    // ── Keys ──────────────────────────────────────────────────

    public function primary(string|array $columns): self
    {
        $this->keys[] = ['type' => 'PRIMARY KEY', 'columns' => (array) $columns, 'name' => null];
        return $this;
    }

    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->keys[] = ['type' => 'UNIQUE KEY', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    public function index(string|array $columns, ?string $name = null): self
    {
        $this->keys[] = ['type' => 'KEY', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    // ── Alter operations ──────────────────────────────────────

    public function drop(string $column): self
    {
        $this->dropColumns[] = $column;
        return $this;
    }

    // ── Compilation ───────────────────────────────────────────

    /** @return string[] one statement (CREATE or ALTER) */
    public function compile(): array
    {
        return $this->mode === self::MODE_CREATE ? [$this->compileCreate()] : [$this->compileAlter()];
    }

    private function compileCreate(): string
    {
        $lines = array_map(fn (Column $c) => $c->compile(), $this->columns);
        foreach ($this->keys as $k) {
            $lines[] = $this->compileKey($k);
        }
        return 'CREATE TABLE IF NOT EXISTS `' . $this->name . "` (\n    "
            . implode(",\n    ", $lines)
            . "\n) ENGINE={$this->engine} DEFAULT CHARSET={$this->charset} COLLATE={$this->collation}";
    }

    private function compileAlter(): string
    {
        $clauses = [];
        foreach ($this->columns as $c) {
            $clauses[] = 'ADD COLUMN ' . $c->compile();
        }
        foreach ($this->keys as $k) {
            $clauses[] = 'ADD ' . $this->compileKey($k);
        }
        foreach ($this->dropColumns as $col) {
            $clauses[] = 'DROP COLUMN `' . $col . '`';
        }
        if ($clauses === []) {
            throw new \LogicException("ALTER TABLE `{$this->name}` has no operations.");
        }
        return 'ALTER TABLE `' . $this->name . "`\n    " . implode(",\n    ", $clauses);
    }

    /** @param array{type:string,columns:string[],name:?string} $key */
    private function compileKey(array $key): string
    {
        $cols = implode(', ', array_map(fn ($c) => '`' . $c . '`', $key['columns']));
        if ($key['type'] === 'PRIMARY KEY') {
            return "PRIMARY KEY ($cols)";
        }
        $name = $key['name'] ?? ($this->keyPrefix($key['type']) . implode('_', $key['columns']));
        return $key['type'] . ' `' . $name . "` ($cols)";
    }

    private function keyPrefix(string $type): string
    {
        return $type === 'UNIQUE KEY' ? 'uniq_' : 'idx_';
    }

    private function addColumn(string $name, string $type): Column
    {
        $col = new Column($name, $type);
        $this->columns[] = $col;
        return $col;
    }
}
