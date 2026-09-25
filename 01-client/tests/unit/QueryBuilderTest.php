<?php
/**
 * Unit tests for Slate\Data\QueryBuilder — pure SQL/binding generation only
 * (terminals that hit the DB are covered by integration checks + the C2 POC).
 */

declare(strict_types=1);

use Slate\Data\QueryBuilder;

unit('bare select is SELECT * FROM `table`', function () {
    $qb = QueryBuilder::table('shop_orders');
    assert_eq('SELECT * FROM `shop_orders`', $qb->toSelectSql());
    assert_eq([], $qb->whereBindings());
});

unit('select specific columns quotes each', function () {
    $qb = QueryBuilder::table('users')->select('id', 'email');
    assert_eq('SELECT `id`, `email` FROM `users`', $qb->toSelectSql());
});

unit('where 2-arg defaults to equals and binds the value', function () {
    $qb = QueryBuilder::table('users')->where('status', 'active');
    assert_eq('SELECT * FROM `users` WHERE `status` = ?', $qb->toSelectSql());
    assert_eq(['active'], $qb->whereBindings());
});

unit('where 3-arg uses the operator', function () {
    $qb = QueryBuilder::table('shop_orders')->where('paid_at', '>=', '2026-01-01');
    assert_eq('SELECT * FROM `shop_orders` WHERE `paid_at` >= ?', $qb->toSelectSql());
    assert_eq(['2026-01-01'], $qb->whereBindings());
});

unit('multiple wheres AND together, bindings in order', function () {
    $qb = QueryBuilder::table('shop_orders')
        ->where('tenant_id', 1)
        ->where('status', 'paid')
        ->where('total_minor', '>', 0);
    assert_eq(
        'SELECT * FROM `shop_orders` WHERE `tenant_id` = ? AND `status` = ? AND `total_minor` > ?',
        $qb->toSelectSql()
    );
    assert_eq([1, 'paid', 0], $qb->whereBindings());
});

unit('rejects an unsupported operator', function () {
    assert_throws(\InvalidArgumentException::class,
        fn () => QueryBuilder::table('t')->where('a', 'DROP', 'x'));
});

unit('whereIn expands placeholders and binds values', function () {
    $qb = QueryBuilder::table('users')->whereIn('id', [4, 5, 6]);
    assert_eq('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $qb->toSelectSql());
    assert_eq([4, 5, 6], $qb->whereBindings());
});

unit('whereIn with an empty set matches nothing (1 = 0), never everything', function () {
    $qb = QueryBuilder::table('users')->whereIn('id', []);
    assert_eq('SELECT * FROM `users` WHERE 1 = 0', $qb->toSelectSql());
    assert_eq([], $qb->whereBindings());
});

unit('whereNull / whereNotNull add no bindings', function () {
    $qb = QueryBuilder::table('t')->whereNull('deleted_at')->whereNotNull('email');
    assert_eq('SELECT * FROM `t` WHERE `deleted_at` IS NULL AND `email` IS NOT NULL', $qb->toSelectSql());
    assert_eq([], $qb->whereBindings());
});

unit('orderBy / limit / offset', function () {
    $qb = QueryBuilder::table('t')->where('a', 1)->orderBy('created_at', 'desc')->limit(10)->offset(20);
    assert_eq('SELECT * FROM `t` WHERE `a` = ? ORDER BY `created_at` DESC LIMIT 10 OFFSET 20', $qb->toSelectSql());
});

unit('rejects a bad ORDER BY direction', function () {
    assert_throws(\InvalidArgumentException::class,
        fn () => QueryBuilder::table('t')->orderBy('a', 'sideways'));
});

unit('rejects negative limit / offset', function () {
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->limit(-1));
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->offset(-1));
});

unit('toCountSql keeps the where clause', function () {
    $qb = QueryBuilder::table('t')->where('a', 1)->orderBy('a')->limit(5);
    assert_eq('SELECT COUNT(*) FROM `t` WHERE `a` = ?', $qb->toCountSql());
    assert_eq([1], $qb->whereBindings());
});

unit('toInsertSql builds columns + placeholders', function () {
    $qb = QueryBuilder::table('users');
    assert_eq('INSERT INTO `users` (`email`, `name`) VALUES (?, ?)',
        $qb->toInsertSql(['email' => 'a@b.c', 'name' => 'A']));
});

unit('toUpdateSql builds SET + where; updateBindings orders values then where', function () {
    $qb = QueryBuilder::table('users')->where('id', 7);
    assert_eq('UPDATE `users` SET `name` = ?, `status` = ? WHERE `id` = ?',
        $qb->toUpdateSql(['name' => 'A', 'status' => 'active']));
    assert_eq(['A', 'active', 7], $qb->updateBindings(['name' => 'A', 'status' => 'active']));
});

unit('toDeleteSql includes the where clause', function () {
    $qb = QueryBuilder::table('users')->where('id', 7);
    assert_eq('DELETE FROM `users` WHERE `id` = ?', $qb->toDeleteSql());
    assert_eq([7], $qb->whereBindings());
});

unit('empty insert / update are rejected', function () {
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->toInsertSql([]));
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->toUpdateSql([]));
});

unit('invalid identifiers are rejected (injection defense)', function () {
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('users; DROP TABLE x'));
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->where('a b', 1));
    assert_throws(\InvalidArgumentException::class, fn () => QueryBuilder::table('t')->select('a`b'));
});

unit('qualified alias.column identifiers are allowed and quoted per segment', function () {
    $qb = QueryBuilder::table('t')->where('t.id', 1);
    assert_eq('SELECT * FROM `t` WHERE `t`.`id` = ?', $qb->toSelectSql());
});

unit('builder methods do not mutate across first()/value() clones', function () {
    // toSelectSql should be stable; value()/first() must not leave LIMIT/columns behind.
    $qb = QueryBuilder::table('t')->where('a', 1);
    $before = $qb->toSelectSql();
    // simulate what value() does internally via a clone — original stays clean
    $clone = clone $qb;
    $clone->select('x')->limit(1);
    assert_eq($before, $qb->toSelectSql(), 'original builder unchanged by clone mutation');
});
