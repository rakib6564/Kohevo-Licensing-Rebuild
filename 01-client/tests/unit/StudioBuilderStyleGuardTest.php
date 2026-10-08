<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3a typed style values.
 *
 * Autoloader only, no database.
 *
 * Every free-form style value is checked against a closed grammar
 * (`StyleValueGuard`) when it is saved AND when it is rendered, so a value
 * stored before the rule existed can never reach a page. The tables below pin
 * what each field accepts and the hostile shapes it must refuse.
 *
 * Reuses `sbp5s_render()` / `sbp5s_validate()` from StudioBuilderPhase5StylesTest
 * (loaded first, alphabetically).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\StyleValueGuard;

/** The shapes every field must refuse, whatever it is. */
function sbsg_hostile(): array
{
    return [
        'url(https://evil.test/x.png)',
        'URL(x)',
        '@import url(x)',
        'image-set(x 1x)',
        'var(--x)',
        'env(safe-area-inset-top)',
        'attr(data-x)',
        'expression(alert(1))',
        'javascript:alert(1)',
        'red;}</style><script>alert(1)</script>',
        '1px/*x*/',
        '1px\\',
        '1px !important',
        "1px\n2px",
        str_repeat('1', 400),
    ];
}

// ── The guard itself ──────────────────────────────────────────────────────

unit('style guard: lengths', function (): void {
    foreach (['0', '12px', '1.5rem', '-4px', '.5em', '100%', 'auto', 'calc(100% - 2rem)', 'clamp(1rem, 4vw, 3rem)', 'min(100%, 60rem)', 12, 0.5] as $ok) {
        assert_true(StyleValueGuard::isLength($ok), 'should accept length: ' . var_export($ok, true));
    }
    foreach (['12 px', 'px', 'big', 'calc(url(x))', 'calc(1px + var(--a))', 'rotate(3deg)', '1e9'] as $bad) {
        assert_false(StyleValueGuard::isLength($bad), "should refuse length: {$bad}");
    }
    foreach (sbsg_hostile() as $bad) {
        assert_false(StyleValueGuard::isLength($bad), "length must refuse: {$bad}");
    }
    assert_false(StyleValueGuard::isLength(NAN), 'NaN is not a length');
    assert_false(StyleValueGuard::isLength(INF), 'INF is not a length');
    assert_false(StyleValueGuard::isLength(10 ** 9), 'an absurd number is not a length');
});

unit('style guard: length lists (padding, radius)', function (): void {
    foreach (['8px', '8px 4px', '1rem 2rem 1rem 2rem', 'space.4'] as $ok) {
        assert_true(StyleValueGuard::isLengthOrToken($ok), "should accept: {$ok}");
    }
    foreach (['1px 2px 3px 4px 5px', '1px url(x)', 'red'] as $bad) {
        assert_false(StyleValueGuard::isLengthOrToken($bad), "should refuse: {$bad}");
    }
});

unit('style guard: colours', function (): void {
    foreach (['#fff', '#ffff', '#e8734a', '#e8734a80', 'rgb(0,0,0)', 'rgba(0, 0, 0, .15)', 'hsl(210, 40%, 50%)', 'transparent', 'currentcolor', 'text.primary', 'surface.dark'] as $ok) {
        assert_true(StyleValueGuard::isColor($ok), "should accept colour: {$ok}");
    }
    foreach (['#ff', '#ggg', 'rgb(url(x))', 'rgb(0,0,0', 'red blue', 'color-mix(in srgb, red, blue)', 'light-dark(red, blue)'] as $bad) {
        assert_false(StyleValueGuard::isColor($bad), "should refuse colour: {$bad}");
    }
    foreach (sbsg_hostile() as $bad) {
        assert_false(StyleValueGuard::isColor($bad), "colour must refuse: {$bad}");
    }
});

unit('style guard: gradients', function (): void {
    foreach (['linear-gradient(135deg, #e8734a, #8a3d24)', 'radial-gradient(circle, rgba(0,0,0,.2) 0%, #fff 100%)', 'conic-gradient(red, blue)'] as $ok) {
        assert_true(StyleValueGuard::isGradient($ok), "should accept gradient: {$ok}");
    }
    foreach (['linear-gradient(url(x), red)', 'linear-gradient(red, blue); color: red', 'repeating-linear-gradient(red, blue)', 'red', 'linear-gradient(red, blue', 'linear-gradient(var(--a), blue)'] as $bad) {
        assert_false(StyleValueGuard::isGradient($bad), "should refuse gradient: {$bad}");
    }
    foreach (sbsg_hostile() as $bad) {
        assert_false(StyleValueGuard::isGradient($bad), "gradient must refuse: {$bad}");
    }
});

unit('style guard: shadows', function (): void {
    foreach (['0 10px 25px rgba(0,0,0,.15)', '0 1px 2px rgba(0,0,0,.2)', 'inset 0 0 0 1px #fff', '0 0 4px red, 0 0 8px blue'] as $ok) {
        assert_true(StyleValueGuard::isShadow($ok), "should accept shadow: {$ok}");
    }
    foreach (['0 0 0 url(x)', '0 0 0 red,', '1px 1px red, 2px 2px red, 3px 3px red, 4px 4px red, 5px 5px red', 'drop-shadow(0 0 1px red)'] as $bad) {
        assert_false(StyleValueGuard::isShadow($bad), "should refuse shadow: {$bad}");
    }
    foreach (sbsg_hostile() as $bad) {
        assert_false(StyleValueGuard::isShadow($bad), "shadow must refuse: {$bad}");
    }
});

unit('style guard: font stacks', function (): void {
    foreach (['Inter, sans-serif', "'Open Sans', Arial, sans-serif", '"Playfair Display", serif', 'font.heading'] as $ok) {
        assert_true(StyleValueGuard::isFontFamily($ok), "should accept font: {$ok}");
    }
    foreach (['Inter; color: red', 'Inter, url(x)', "Inter\\", 'a{b}', ''] as $bad) {
        assert_false(StyleValueGuard::isFontFamily($bad), "should refuse font: {$bad}");
    }
});

// ── Save time: the validator uses the guard ───────────────────────────────

unit('style guard: the validator refuses a hostile value in every free-form field', function (): void {
    $bad = 'url(https://evil.test/x.png)';
    $cases = [
        'typography.size'           => ['typography' => ['size' => $bad]],
        'typography.line_height'    => ['typography' => ['line_height' => $bad]],
        'typography.letter_spacing' => ['typography' => ['letter_spacing' => $bad]],
        'typography.color'          => ['typography' => ['color' => $bad]],
        'typography.font_family'    => ['typography' => ['font_family' => $bad]],
        'color'                     => ['color' => $bad],
        'background (string)'       => ['background' => $bad],
        'background.color'          => ['background' => ['color' => $bad]],
        'background.gradient'       => ['background' => ['gradient' => $bad]],
        'spacing'                   => ['spacing' => ['top' => $bad]],
        'border.width'              => ['border' => ['width' => $bad]],
        'border.color'              => ['border' => ['color' => $bad]],
        'border.radius'             => ['border' => ['radius' => $bad]],
        'shadow'                    => ['shadow' => $bad],
        'dimensions.width'          => ['dimensions' => ['width' => $bad]],
        'dimensions.max_width'      => ['dimensions' => ['max_width' => '@import url(x)']],
    ];
    foreach ($cases as $label => $style) {
        assert_false(sbp5s_validate($style)->isValid(), "validator must refuse a url() in {$label}");
    }
});

unit('style guard: the values the inspector writes still validate', function (): void {
    $result = sbp5s_validate([
        'typography' => ['size' => '1.5rem', 'line_height' => '1.5', 'letter_spacing' => '-0.01em', 'font_family' => 'Inter, sans-serif', 'color' => '#e8734a', 'weight' => '600'],
        'background' => ['color' => '#0b0c0f', 'gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'],
        'border'     => ['style' => 'solid', 'width' => '2px', 'color' => '#2b3358', 'radius' => '1.25rem'],
        'shadow'     => '0 10px 25px rgba(0,0,0,.15)',
        'dimensions' => ['width' => '100%', 'min_height' => '20rem', 'max_width' => '60rem'],
        'spacing'    => ['top' => '1rem', 'bottom' => '1rem 2rem'],
        'opacity'    => 0.9,
        'z_index'    => 10,
    ]);
    assert_true($result->isValid(), 'a full inspector style must validate: ' . json_encode($result->errors ?? []));
});

// ── Render time: a stored hostile value is dropped, not emitted ───────────

unit('style guard: the renderer drops a hostile stored value', function (): void {
    // These bypass the validator on purpose — they model a document stored
    // before the rule existed.
    $cases = [
        ['typography' => ['size' => 'url(https://evil.test/a)']],
        ['typography' => ['font_family' => 'Inter; background:url(x)']],
        ['typography' => ['line_height' => 'var(--x)']],
        ['color' => '@import url(x)'],
        ['background' => ['gradient' => 'linear-gradient(url(x), red)']],
        ['background' => ['color' => 'url(x)']],
        ['background' => 'url(x) no-repeat'],
        ['border' => ['width' => 'url(x)', 'color' => 'url(x)', 'radius' => 'url(x)']],
        ['shadow' => '0 0 0 url(x)'],
        ['dimensions' => ['width' => 'url(x)', 'height' => '1px;position:fixed']],
        ['opacity' => 'url(x)'],
        ['z_index' => '9999999'],
    ];
    foreach ($cases as $style) {
        $html = sbp5s_render($style);
        foreach (['url(', '@import', 'var(', 'position:fixed', 'evil.test'] as $needle) {
            assert_false(str_contains($html, $needle), "must not emit '{$needle}' for " . json_encode($style) . ': ' . $html);
        }
    }
});

unit('style guard: one bad value does not drop its good neighbours', function (): void {
    $html = sbp5s_render([
        'typography' => ['size' => '2rem', 'font_family' => 'Inter; x:y'],
        'dimensions' => ['width' => '100%', 'height' => 'url(x)'],
    ]);
    assert_true(str_contains($html, 'font-size:2rem'), 'good size kept: ' . $html);
    assert_true(str_contains($html, 'width:100%'), 'good width kept: ' . $html);
    assert_false(str_contains($html, 'font-family'), 'bad font dropped: ' . $html);
    assert_false(str_contains($html, 'height'), 'bad height dropped: ' . $html);
});

// ── The audit helper agrees with the validator ────────────────────────────

unit('style guard: styleIssues names exactly the refused values', function (): void {
    assert_eq([], StyleValueGuard::styleIssues([
        'typography' => ['size' => '1.5rem', 'font_family' => 'Inter, sans-serif', 'color' => '#e8734a'],
        'background' => ['gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'],
        'border'     => ['radius' => 'lg', 'width' => '2px'],
        'shadow'     => 'lg',
        'dimensions' => ['width' => '100%'],
    ]), 'a clean style has no issues');

    $issues = StyleValueGuard::styleIssues([
        'typography' => ['size' => '2rem', 'font_family' => 'url(x)'],
        'background' => ['color' => 'url(x)'],
        'shadow'     => '0 0 0 url(x)',
        'dimensions' => ['width' => '100%', 'height' => '@import x'],
    ]);
    assert_eq(['typography.font_family', 'background.color', 'shadow', 'dimensions.height'], array_keys($issues), 'only the refused fields are listed');
});

// ── Parity with the editor (ui/src/core/styleValues.mjs) ──────────────────

unit('style guard: agrees with every case in the shared UI fixture', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/style-values.json'), true);
    assert_true(is_array($fixture) && count($fixture['cases']) > 80, 'fixture loads');
    $methods = [
        'length' => 'isLength', 'lengthList' => 'isLengthList', 'lengthOrToken' => 'isLengthOrToken',
        'lineHeight' => 'isLineHeight', 'letterSpacing' => 'isLetterSpacing', 'borderWidth' => 'isBorderWidth',
        'color' => 'isColor', 'gradient' => 'isGradient', 'shadow' => 'isShadow', 'fontFamily' => 'isFontFamily',
    ];
    foreach ($fixture['cases'] as $case) {
        $method = $methods[$case['kind']] ?? null;
        assert_true($method !== null, 'unknown kind ' . $case['kind']);
        assert_eq($case['ok'], StyleValueGuard::$method($case['value']), $case['kind'] . ' ' . json_encode($case['value']));
    }
});
