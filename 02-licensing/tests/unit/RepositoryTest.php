<?php
/**
 * Unit tests for Slate\Data\Repository — tenant scoping is verified through the
 * pure query() builder SQL and the crossTenant audit-sink spy (no DB needed).
 */

declare(strict_types=1);

use Slate\Data\Repository;
use Slate\Data\Entity;
use Slate\Data\QueryBuilder;
use Slate\Tenancy\TenantContext;

final class _RepoWidgetEntity extends Entity {}

/** Test double exposing the protected scoping internals. */
final class _WidgetRepo extends Repository
{
    protected string $table = 'widgets';
    protected ?string $entity = _RepoWidgetEntity::class;

    public function q(): QueryBuilder { return $this->query(); }
    public function stamp(array $d): array { return $this->withTenantStamp($d); }
    public function hy(array $row): array|Entity { return $this->hydrate($row); }
}

/** Same, but without an entity — hydrate() should return raw arrays. */
final class _RawRepo extends Repository
{
    protected string $table = 'things';
    public function hy(array $row): array|Entity { return $this->hydrate($row); }
}

function _repo_reset(): void { unset($GLOBALS['SLATE_TENANT_OVERRIDE']); }

unit('scoped query() injects the tenant predicate with the current tenant', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    $qb = $repo->q();
    assert_eq('SELECT * FROM `widgets` WHERE `tenant_id` = ?', $qb->toSelectSql());
    assert_eq([1], $qb->whereBindings());
});

unit('query() uses the active tenant from an override', function () {
    _repo_reset();
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = 5;
    $repo = new _WidgetRepo(new TenantContext());
    assert_eq([5], $repo->q()->whereBindings());
    _repo_reset();
});

unit('find()/update()/delete() compose id on top of the tenant predicate', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    // This mirrors what find/update/delete build internally.
    assert_eq(
        'SELECT * FROM `widgets` WHERE `tenant_id` = ? AND `id` = ?',
        $repo->q()->where('id', 7)->toSelectSql()
    );
});

unit('insert stamps tenant_id when scoped and absent', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    assert_eq(['name' => 'x', 'tenant_id' => 1], $repo->stamp(['name' => 'x']));
});

unit('insert does not override an explicitly provided tenant_id', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    assert_eq(['name' => 'x', 'tenant_id' => 9], $repo->stamp(['name' => 'x', 'tenant_id' => 9]));
});

unit('crossTenant lifts the predicate inside, restores it after', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    $insideSql = null;
    $repo->crossTenant(function () use ($repo, &$insideSql) {
        $insideSql = $repo->q()->toSelectSql();
    });
    assert_eq('SELECT * FROM `widgets`', $insideSql, 'no tenant predicate inside crossTenant');
    assert_eq('SELECT * FROM `widgets` WHERE `tenant_id` = ?', $repo->q()->toSelectSql(), 'restored after');
});

unit('crossTenant does not stamp tenant_id on inserts inside the block', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext());
    $stamped = null;
    $repo->crossTenant(function () use ($repo, &$stamped) {
        $stamped = $repo->stamp(['name' => 'x']);
    });
    assert_eq(['name' => 'x'], $stamped, 'unscoped insert must not auto-stamp tenant_id');
});

unit('crossTenant calls the injected audit sink with the concrete repo class', function () {
    _repo_reset();
    $log = [];
    $repo = new _WidgetRepo(new TenantContext(), function (string $event, string $ctx) use (&$log) {
        $log[] = [$event, $ctx];
    });
    $repo->crossTenant(fn () => null);
    assert_eq([['data.cross_tenant', _WidgetRepo::class]], $log);
});

unit('crossTenant returns the callback value and works with no sink', function () {
    _repo_reset();
    $repo = new _WidgetRepo(new TenantContext()); // no audit sink
    assert_eq('result', $repo->crossTenant(fn () => 'result'));
});

unit('hydrate wraps into the configured Entity, or returns raw arrays', function () {
    _repo_reset();
    $withEntity = new _WidgetRepo(new TenantContext());
    $e = $withEntity->hy(['id' => 3, 'name' => 'x']);
    assert_true($e instanceof _RepoWidgetEntity);
    assert_eq(3, $e->id());

    $raw = new _RawRepo(new TenantContext());
    assert_eq(['id' => 3], $raw->hy(['id' => 3]));
});
