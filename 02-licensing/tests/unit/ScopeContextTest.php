<?php

declare(strict_types=1);

use Slate\Tenancy\ScopeContext;

unit('ScopeContext derives a stable tenant site environment key', function () {
    $scope = new ScopeContext(7, 'site-main', 'staging');
    assert_eq('7:site-main:staging', $scope->key());
    assert_true($scope->sameAs(new ScopeContext(7, 'site-main', 'staging')));
    assert_false($scope->sameAs(new ScopeContext(7, 'site-main', 'production')));
});

unit('ScopeContext rejects invalid tenant, site, and environment values', function () {
    foreach ([
        fn () => new ScopeContext(0, 'site-main', 'staging'),
        fn () => new ScopeContext(1, 'bad site', 'staging'),
        fn () => new ScopeContext(1, 'site-main', 'preview'),
    ] as $factory) {
        try {
            $factory();
            throw new RuntimeException('expected validation exception');
        } catch (InvalidArgumentException $e) {
            // expected
        }
    }
});
