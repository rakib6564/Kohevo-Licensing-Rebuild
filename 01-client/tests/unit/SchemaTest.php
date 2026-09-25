<?php
/**
 * Unit tests for the Schema builders (Column / Table / Schema) — pure SQL
 * compilation, no database. A recording executor captures emitted statements.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;
use Slate\Data\Schema\Column;

/** A Schema whose executor records statements into $out. */
function _rec(array &$out): Schema
{
    return new Schema(function (string $sql) use (&$out) { $out[] = $sql; });
}

// ── Column ────────────────────────────────────────────────
unit('Column: NOT NULL by default', function () {
    assert_eq('`name` VARCHAR(120) NOT NULL', (new Column('name', 'VARCHAR(120)'))->compile());
});

unit('Column: nullable / unsigned / default / auto_increment / after', function () {
    $c = (new Column('id', 'BIGINT'))->unsigned()->autoIncrement();
    assert_eq('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $c->compile());

    $s = (new Column('status', 'VARCHAR(32)'))->default('pending');
    assert_eq("`status` VARCHAR(32) NOT NULL DEFAULT 'pending'", $s->compile());

    $n = (new Column('note', 'TEXT'))->nullable();
    assert_eq('`note` TEXT NULL', $n->compile());

    $b = (new Column('active', 'TINYINT(1)'))->default(true);
    assert_eq('`active` TINYINT(1) NOT NULL DEFAULT 1', $b->compile());

    $a = (new Column('total_minor', 'BIGINT'))->default(0)->after('total');
    assert_eq('`total_minor` BIGINT NOT NULL DEFAULT 0 AFTER `total`', $a->compile());
});

unit('Column: useCurrent / defaultRaw are not quoted', function () {
    assert_eq('`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        (new Column('created_at', 'DATETIME'))->useCurrent()->compile());
});

unit('Column: string default is escaped', function () {
    assert_eq("`x` VARCHAR(10) NOT NULL DEFAULT 'a''b'",
        (new Column('x', 'VARCHAR(10)'))->default("a'b")->compile());
});

// ── Table: CREATE ─────────────────────────────────────────
unit('Table: create compiles a full CREATE TABLE', function () {
    $out = [];
    _rec($out)->create('shop_orders', function (Table $t) {
        $t->id();
        $t->int('tenant_id')->unsigned()->default(1);
        $t->string('status', 32)->default('pending');
        $t->bigInt('total_minor');
        $t->datetime('created_at')->useCurrent();
        $t->index('tenant_id');
        $t->unique('status', 'uniq_status');
    });
    assert_eq(1, count($out));
    $sql = $out[0];
    assert_true(str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS `shop_orders` ('), 'create prefix');
    assert_true(str_contains($sql, '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'), 'id column');
    assert_true(str_contains($sql, 'PRIMARY KEY (`id`)'), 'primary key from id()');
    assert_true(str_contains($sql, '`total_minor` BIGINT NOT NULL'), 'money as bigint minor units');
    assert_true(str_contains($sql, 'KEY `idx_tenant_id` (`tenant_id`)'), 'index');
    assert_true(str_contains($sql, 'UNIQUE KEY `uniq_status` (`status`)'), 'named unique');
    assert_true(str_contains($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'), 'table opts');
});

unit('Table: composite primary key', function () {
    $out = [];
    _rec($out)->create('role_permissions', function (Table $t) {
        $t->int('role_id')->unsigned();
        $t->string('perm_key', 64);
        $t->primary(['role_id', 'perm_key']);
    });
    assert_true(str_contains($out[0], 'PRIMARY KEY (`role_id`, `perm_key`)'));
});

unit('Table: enum column', function () {
    $out = [];
    _rec($out)->create('t', function (Table $t) {
        $t->enum('status', ['active', 'suspended', 'deleted'])->default('active');
    });
    assert_true(str_contains($out[0], "`status` ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active'"));
});

// ── Table: ALTER ──────────────────────────────────────────
unit('Table: alter add + drop columns compiles to one ALTER', function () {
    $out = [];
    _rec($out)->table('shop_orders', function (Table $t) {
        $t->bigInt('total_minor')->after('total');
        $t->drop('total');
    });
    assert_eq(1, count($out));
    $sql = $out[0];
    assert_true(str_starts_with($sql, 'ALTER TABLE `shop_orders`'), 'alter prefix');
    assert_true(str_contains($sql, 'ADD COLUMN `total_minor` BIGINT NOT NULL AFTER `total`'), 'add column');
    assert_true(str_contains($sql, 'DROP COLUMN `total`'), 'drop column');
});

unit('Table: empty ALTER is rejected', function () {
    assert_throws(\LogicException::class, function () {
        $out = [];
        _rec($out)->table('t', function (Table $t) { /* nothing */ });
    });
});

// ── Schema: drop / rename / raw ───────────────────────────
unit('Schema: drop / dropIfExists / rename', function () {
    $out = [];
    $s = _rec($out);
    $s->drop('a');
    $s->dropIfExists('b');
    $s->rename('c', 'd');
    assert_eq(['DROP TABLE `a`', 'DROP TABLE IF EXISTS `b`', 'RENAME TABLE `c` TO `d`'], $out);
});

unit('Schema: raw splits multi-statement SQL and strips line comments', function () {
    $out = [];
    _rec($out)->raw("-- a comment\nCREATE TABLE x (id INT);\nINSERT INTO x VALUES (1);\n");
    assert_eq(2, count($out));
    assert_true(str_contains($out[0], 'CREATE TABLE x (id INT)'));
    assert_true(str_contains($out[1], 'INSERT INTO x VALUES (1)'));
});

unit('Schema: raw ignores trailing empty statement', function () {
    $out = [];
    _rec($out)->raw("SELECT 1;");
    assert_eq(1, count($out));
    assert_eq('SELECT 1', $out[0]);
});
