<?php
/**
 * Unit tests for Slate\Tenancy\TenantContext (isolated — no config.php/DB).
 *
 * These run with the legacy helpers NOT loaded, so they exercise the isolated
 * fallback path and the $GLOBALS['SLATE_TENANT_OVERRIDE'] bridge directly.
 */

declare(strict_types=1);

use Slate\Tenancy\ScopeContext;
use Slate\Tenancy\TenantContext;

// Ensure a clean global between assertions.
function _tc_reset(): void { unset($GLOBALS['SLATE_TENANT_OVERRIDE']); }

unit('id() defaults to tenant 1 with no override', function () {
    _tc_reset();
    assert_eq(1, (new TenantContext())->id());
});

unit('id() reads the global override', function () {
    _tc_reset();
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = 7;
    assert_eq(7, (new TenantContext())->id());
    _tc_reset();
});

unit('runAs switches the tenant for the callback and returns its value', function () {
    _tc_reset();
    $ctx = new TenantContext();
    assert_eq(1, $ctx->id());
    $seen = $ctx->runAs(42, function () use ($ctx) {
        return $ctx->id();
    });
    assert_eq(42, $seen, 'id() inside runAs must be the switched tenant');
    assert_eq(1, $ctx->id(), 'tenant restored after runAs');
});

unit('runAs restores the previous tenant even on exception', function () {
    _tc_reset();
    $ctx = new TenantContext();
    $ctx->runAs(9, function () { /* baseline */ });
    try {
        $ctx->runAs(99, function () { throw new \RuntimeException('boom'); });
    } catch (\RuntimeException $e) {
        // expected
    }
    assert_eq(1, $ctx->id(), 'tenant restored after a throwing runAs');
});

unit('runAs nests and restores the outer tenant', function () {
    _tc_reset();
    $ctx = new TenantContext();
    $inner = null; $betweenOuter = null;
    $ctx->runAs(2, function () use ($ctx, &$inner, &$betweenOuter) {
        $inner = $ctx->runAs(3, fn () => $ctx->id());
        $betweenOuter = $ctx->id();
    });
    assert_eq(3, $inner);
    assert_eq(2, $betweenOuter, 'outer tenant restored after nested runAs');
    assert_eq(1, $ctx->id());
    _tc_reset();
});

unit('scoping is on by default', function () {
    assert_true((new TenantContext())->isScoped());
});

unit('withoutScope suspends scoping for the callback and returns its value', function () {
    $ctx = new TenantContext();
    $inside = $ctx->withoutScope(function () use ($ctx) {
        return $ctx->isScoped();
    });
    assert_false($inside, 'scoping suspended inside withoutScope');
    assert_true($ctx->isScoped(), 'scoping restored after withoutScope');
});

unit('scope() binds current tenant to explicit site and environment', function () {
    _tc_reset();
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = 7;
    $scope = (new TenantContext())->scope('site-main', 'staging');
    assert_true($scope instanceof ScopeContext);
    assert_eq('7:site-main:staging', $scope->key());
    _tc_reset();
});

unit('scope() rejects invalid environment instead of defaulting it', function () {
    try {
        (new TenantContext())->scope('site-main', 'preview');
        throw new RuntimeException('expected validation exception');
    } catch (InvalidArgumentException $e) {
        // expected
    }
});

unit('withoutScope is re-entrant and restores on exception', function () {
    $ctx = new TenantContext();
    $ctx->withoutScope(function () use ($ctx) {
        assert_false($ctx->isScoped());
        $ctx->withoutScope(function () use ($ctx) {
            assert_false($ctx->isScoped());
        });
        assert_false($ctx->isScoped(), 'still suspended after inner block closes');
    });
    assert_true($ctx->isScoped());

    try {
        $ctx->withoutScope(function () { throw new \RuntimeException('x'); });
    } catch (\RuntimeException $e) {
        // expected
    }
    assert_true($ctx->isScoped(), 'scoping restored after a throwing withoutScope');
});
