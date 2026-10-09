<?php
/**
 * The Inspector labels a size word with its real size ("Medium · 16px"). Those sizes live in
 * ui/src/core/optionValues.json; this test pins every row to the stylesheet the renderer really emits, so a
 * label can never claim a size a page does not use.
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
    $studioOvUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Render\StudioStylesheet;

/** The declarations of one exact selector in the stylesheet, as prop => value, or null when there is no such rule. */
function sbov_rule(string $css, string $selector): ?array
{
    if (preg_match('~(?:^|\})' . preg_quote($selector, '~') . '\{([^}]*)\}~', $css, $m) !== 1) {
        return null;
    }
    $out = [];
    foreach (explode(';', $m[1]) as $decl) {
        if (str_contains($decl, ':')) {
            [$p, $v] = explode(':', $decl, 2);
            $out[trim($p)] = trim($v);
        }
    }
    return $out;
}

/** ".25rem" and "0.25rem" are the same size. */
function sbov_norm(string $v): string
{
    $v = strtolower(preg_replace('~\s+~', '', $v) ?? $v);
    return preg_replace('~(?<![\d.])\.(\d)~', '0.$1', $v) ?? $v;
}

unit('option values: every size the Inspector shows is the size the stylesheet emits', function (): void {
    $file = dirname(__DIR__, 2) . '/plugins/studio-builder/ui/src/core/optionValues.json';
    $data = json_decode((string) file_get_contents($file), true);
    assert_true(is_array($data) && isset($data['tables'], $data['fields']), 'the table is readable');
    $css = StudioStylesheet::css();
    $checked = 0;
    foreach ($data['tables'] as $name => $table) {
        foreach ($table['values'] as $value => $expected) {
            if (in_array($value, $table['noRule'] ?? [], true)) {
                continue; // a keyword with no rule of its own (e.g. full width = no limit)
            }
            $selector = isset($table['baseRule'][$value])
                ? $table['baseRule'][$value]
                : str_replace('{v}', (string) ($table['ruleName'][$value] ?? $value), $table['rule']);
            $decls = sbov_rule($css, $selector);
            assert_true($decls !== null, "{$name}.{$value}: the stylesheet has {$selector}");
            $prop = $table['prop'];
            assert_true(isset($decls[$prop]), "{$name}.{$value}: {$selector} sets {$prop}");
            $want = $table['alias'][$value] ?? $expected;
            assert_eq(sbov_norm($want), sbov_norm($decls[$prop]), "{$name}.{$value} ({$selector} {$prop})");
            $checked++;
        }
    }
    assert_true($checked >= 50, 'the check covered the table, not nothing');
});

unit('option values: gap and padding tables match the renderer for every spacing word', function (): void {
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/ui/src/core/optionValues.json'), true);
    foreach (['gap', 'padding'] as $name) {
        assert_eq(
            \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_SPACING_SCALE,
            array_keys($data['tables'][$name]['values']),
            "{$name} covers exactly the spacing scale, in order"
        );
    }
    assert_eq(\Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, array_keys($data['tables']['page_width']['values']), 'page widths');
});

if (!empty($studioOvUnitStandalone)) {
    exit(unit_summary());
}
