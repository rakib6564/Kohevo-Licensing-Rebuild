<?php
/**
 * Kohevo Studio — content type URL route prefixes: what counts as a prefix, and how a request path splits into a prefix
 * and a page slug. Pure: no database.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageRoutes;

unit('page routes: a route prefix is one to three slug-shaped segments, empty or / for none', function (): void {
    assert_eq('events', PageRoutes::normalizePrefix('/events'));
    assert_eq('events', PageRoutes::normalizePrefix('events/'));
    assert_eq('produit', PageRoutes::normalizePrefix(' /Produit/ '), 'trimmed and lower-cased');
    assert_eq('fr/evenements/archives', PageRoutes::normalizePrefix('/fr/evenements/archives'));
    assert_eq('', PageRoutes::normalizePrefix(''), 'empty is no prefix');
    assert_eq('', PageRoutes::normalizePrefix('/'), 'a bare slash is no prefix');
    foreach (['/a/b/c/d', '/events?x=1', '/évènements', '/my events', '/-events', '/events//x', '/category', '/tag/x', '/author', '/search', '../x'] as $bad) {
        assert_eq(null, PageRoutes::normalizePrefix($bad), "not a prefix: {$bad}");
    }
    assert_eq(null, PageRoutes::normalizePrefix(['/events']), 'only a string');
});

unit('page routes: a path of two to four segments splits into a prefix and a slug', function (): void {
    assert_eq(['prefix' => 'events', 'slug' => 'chaque-pas-compte'], PageRoutes::split('/events/chaque-pas-compte/'));
    assert_eq(['prefix' => 'produit', 'slug' => 't-shirt'], PageRoutes::split('produit/t-shirt?utm=x'));
    assert_eq(['prefix' => 'a/b/c', 'slug' => 'd'], PageRoutes::split('/a/b/c/d'));
    foreach (['/', '/contact/', '/a/b/c/d/e', '/events/Bad Slug', '/category/news', '/events/', '/events//x'] as $path) {
        assert_eq(null, PageRoutes::split($path), "no split: {$path}");
    }
});
