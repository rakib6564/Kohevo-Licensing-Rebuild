<?php
/**
 * Unit tests for Slate\Data\Entity.
 */

declare(strict_types=1);

use Slate\Data\Entity;

final class _EntSampleEntity extends Entity
{
    public function name(): string { return (string) $this->get('name'); }
}

unit('Entity::fromRow + accessors', function () {
    $e = Entity::fromRow(['id' => 5, 'name' => 'Ada']);
    assert_eq(5, $e->id());
    assert_eq('Ada', $e->get('name'));
    assert_true($e->has('name'));
    assert_false($e->has('missing'));
    assert_eq('fallback', $e->get('missing', 'fallback'));
});

unit('Entity::id() is null when absent, int-cast when present', function () {
    assert_eq(null, Entity::fromRow(['name' => 'x'])->id());
    assert_eq(9, Entity::fromRow(['id' => '9'])->id());
});

unit('Entity::toArray returns the underlying row', function () {
    $row = ['id' => 1, 'a' => 'b'];
    assert_eq($row, Entity::fromRow($row)->toArray());
});

unit('subclass fromRow returns the subclass with typed accessors', function () {
    $e = _EntSampleEntity::fromRow(['id' => 2, 'name' => 'Grace']);
    assert_true($e instanceof _EntSampleEntity);
    assert_eq('Grace', $e->name());
});
