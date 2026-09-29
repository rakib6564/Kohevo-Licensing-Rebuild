<?php
/**
 * Unit tests for Central ModuleCatalog registration of Kohevo Studio (`studio-builder`).
 */

declare(strict_types=1);

if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/src/autoload.php';
    require_once __DIR__ . '/harness.php';
    $studioCatalogStandalone = true;
}

require_once dirname(__DIR__, 2) . '/plugins/licensing/ModuleCatalog.php';

unit('central ModuleCatalog (Phase 1): registers studio-builder as an available V1 commercial module', function (): void {
    $all = ModuleCatalog::all();
    assert_true(isset($all['studio-builder']), 'ModuleCatalog::all() must include studio-builder');

    $studio = $all['studio-builder'];
    assert_eq('studio-builder', $studio['module_key']);
    assert_eq('Kohevo Studio', $studio['display_name']);
    assert_eq('available', $studio['status']);
    assert_true($studio['commercial']);
    assert_true($studio['v1_available']);
    assert_eq('studio-builder', $studio['plugin_identifier']);
    assert_eq([], $studio['dependencies']);

    assert_true(
        in_array('studio-builder', ModuleCatalog::v1CommercialKeys(), true),
        'ModuleCatalog::v1CommercialKeys() must include studio-builder'
    );

    $validated = ModuleCatalog::validateCommercialSelection(['forms', 'studio-builder']);
    assert_eq(['forms', 'studio-builder'], $validated);

    assert_eq([], ModuleCatalog::resolveDependencies(['studio-builder']));
});

if (!empty($studioCatalogStandalone)) {
    exit(unit_summary());
}
