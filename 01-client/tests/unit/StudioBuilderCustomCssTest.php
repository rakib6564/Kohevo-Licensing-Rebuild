<?php
/**
 * Unit tests for preparing the site stylesheet an administrator types in the builder
 * (StudioCodePolicy::prepareCustomCss). The reduction rules themselves are covered by the Phase 1 suite.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioCssUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;

unit('custom css: clean input is stored as typed, minus surrounding whitespace, and reported unchanged', function (): void {
    $r = StudioCodePolicy::prepareCustomCss("  .hero { color: #123; }\n.btn:hover{opacity:.8}\n");
    assert_eq(".hero { color: #123; }\n.btn:hover{opacity:.8}", $r['css']);
    assert_eq(strlen($r['css']), $r['bytes']);
    assert_false($r['changed']);
    $empty = StudioCodePolicy::prepareCustomCss('');
    assert_eq(['css' => '', 'bytes' => 0, 'changed' => false], $empty);
});

unit('custom css: unsafe parts are removed and the author is told something changed', function (): void {
    foreach ([
        'a{background:url(javascript:alert(1))}' => 'javascript:',
        '@import url(https://evil.test/x.css); a{color:red}' => '@import',
        'a{width:expression(alert(1))}' => 'expression(',
        'a{color:red}</style><script>alert(1)</script>' => '<script',
        'a{-moz-binding:url(x)}' => '-moz-binding:',
    ] as $input => $gone) {
        $r = StudioCodePolicy::prepareCustomCss($input);
        assert_true($r['changed'], "changed: {$input}");
        assert_false(stripos($r['css'], $gone) !== false, "{$gone} removed from: {$input}");
    }
});

unit('custom css: a stylesheet over the ceiling is refused, never cut in half', function (): void {
    $big = str_repeat('a{color:red}', (int) (StudioCodePolicy::MAX_CUSTOM_CSS_BYTES / 12) + 10);
    assert_true(strlen($big) > StudioCodePolicy::MAX_CUSTOM_CSS_BYTES);
    assert_throws(\InvalidArgumentException::class, static fn () => StudioCodePolicy::prepareCustomCss($big));
    $exact = str_repeat('a', StudioCodePolicy::MAX_CUSTOM_CSS_BYTES);
    assert_eq(StudioCodePolicy::MAX_CUSTOM_CSS_BYTES, StudioCodePolicy::prepareCustomCss($exact)['bytes']);
});

if (!empty($studioCssUnitStandalone)) {
    exit(unit_summary());
}
