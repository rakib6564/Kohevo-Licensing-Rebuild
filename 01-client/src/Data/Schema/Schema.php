<?php
/**
 * Slate — Schema (migration-facing DDL API).
 *
 * The object a Migration's up()/down() receives. It turns fluent table
 * definitions into SQL statements and hands each to an injected executor. In the
 * migration runner the executor runs the statement against the connection; in
 * unit tests it records the statement, so schema building is verified with no DB.
 *
 *   public function up(Schema $s): void {
 *       $s->create('shop_orders', fn (Table $t) => …);
 *       $s->table('shop_orders', fn (Table $t) => $t->bigInt('total_minor'));
 *       $s->raw('UPDATE shop_orders SET total_minor = ROUND(total * 100)');
 *       $s->dropIfExists('legacy_orders');
 *       if ($s->hasTable('contentbuilder_posts')) { … }
 *   }
 *
 * hasTable() needs to answer from the real database, which the SQL-only
 * $executor can't do (it's fire-and-forget — see run()). The migration
 * runner injects a second, optional $tableExists prober for this; unit
 * tests that only record compiled SQL (no DB) never call hasTable() and
 * so never need to supply one — see SchemaTest's _rec() helper.
 *
 * Layer: Data.
 */

declare(strict_types=1);

namespace Slate\Data\Schema;

final class Schema
{
    /**
     * @param callable(string $sql): void $executor runs (or records) each statement
     * @param (callable(string $table): bool)|null $tableExists returns whether $table
     *        exists in the current database; omitted in the SQL-recording unit-test mode
     */
    public function __construct(private $executor, private $tableExists = null) {}

    /** Whether $table exists in the current database. */
    public function hasTable(string $table): bool
    {
        if ($this->tableExists === null) {
            throw new \LogicException(
                'Schema::hasTable() needs a live database — this Schema was built '
                . 'without a $tableExists prober (e.g. a unit-test recording Schema).'
            );
        }
        return ($this->tableExists)($table);
    }

    /** Create a new table. */
    public function create(string $table, callable $define): void
    {
        $t = new Table($table, Table::MODE_CREATE);
        $define($t);
        $this->runAll($t->compile());
    }

    /** Alter an existing table (add/drop columns, add keys). */
    public function table(string $table, callable $define): void
    {
        $t = new Table($table, Table::MODE_ALTER);
        $define($t);
        $this->runAll($t->compile());
    }

    public function drop(string $table): void
    {
        $this->run('DROP TABLE `' . $table . '`');
    }

    public function dropIfExists(string $table): void
    {
        $this->run('DROP TABLE IF EXISTS `' . $table . '`');
    }

    public function rename(string $from, string $to): void
    {
        $this->run('RENAME TABLE `' . $from . '` TO `' . $to . '`');
    }

    /**
     * Run raw SQL. Accepts a multi-statement string (e.g. a whole schema.sql):
     * line comments are stripped and it is split on ';' — the same naive split
     * the shell has always used for plugin SQL (fine for DDL without procedures).
     */
    public function raw(string $sql): void
    {
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
            if ($stmt !== '') {
                $this->run($stmt);
            }
        }
    }

    /** @param string[] $statements */
    private function runAll(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->run($sql);
        }
    }

    private function run(string $sql): void
    {
        ($this->executor)($sql);
    }
}
