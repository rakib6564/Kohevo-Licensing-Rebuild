<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3c style-surface parity.
 *
 * Autoloader only, no database.
 *
 * The editor refuses a value before it is saved by checking `core/styleSurface.mjs`; the server refuses it again
 * in `StyleSurface::issues`. Both read the same cases (`ui/tests/fixtures/style-surface.json`), so the two
 * cannot drift: a value the editor offers is a value the server keeps, and the block does not go "unavailable".
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\StyleSurface;

unit('style surface parity: every shared case is accepted or refused as the editor expects', function (): void {
    $file = dirname(__DIR__, 2) . '/plugins/studio-builder/ui/tests/fixtures/style-surface.json';
    $cases = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['cases'];
    assert_true(count($cases) > 50, 'the fixture has cases');
    foreach ($cases as $case) {
        $parts = explode('.', (string) $case['path']);
        $style = [];
        $node = &$style;
        foreach ($parts as $i => $part) {
            $node[$part] = $i === count($parts) - 1 ? $case['value'] : [];
            $node = &$node[$part];
        }
        unset($node);
        if ($parts[0] === 'shadow') {
            // A shadow object must carry x, y and a colour; the case only varies one field of it.
            $style['shadow'] += ['x' => '0', 'y' => '0', 'color' => '#000000'];
        }
        $errors = StyleSurface::issues($style, 'style');
        assert_eq(
            $case['ok'],
            $errors === [],
            $case['path'] . ' = ' . json_encode($case['value']) . ($errors === [] ? '' : ' -> ' . $errors[0]['message'])
        );
    }
});
