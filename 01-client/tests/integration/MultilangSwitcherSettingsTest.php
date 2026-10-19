<?php
/**
 * S-13: the language switcher's colours are written into the widget's inline
 * `style`, and its style and position into class names. Saving checks them
 * (hex colours, fixed lists), and the widget prints only allowed values, so a
 * value stored before the check cannot add CSS or attributes.
 */

declare(strict_types=1);

if (!class_exists('MLT_SwitcherSettings')) {
    require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/SwitcherSettings.php';
}

unit('multilang switcher: saving keeps hex colours and listed options, and names every refused field', function (): void {
    [$values, $errors] = MLT_SwitcherSettings::clean([
        'switcher_enabled' => '1', 'switcher_style' => 'flags', 'switcher_position' => 'top-left',
        'switcher_bg' => '#ABCDEF', 'switcher_fg' => '#fff', 'switcher_accent' => ' #111111 ',
    ]);
    assert_eq([], $errors);
    assert_eq(['switcher_enabled' => '1', 'switcher_style' => 'flags', 'switcher_position' => 'top-left', 'switcher_bg' => '#abcdef', 'switcher_fg' => '#fff', 'switcher_accent' => '#111111'], $values);

    [$values, $errors] = MLT_SwitcherSettings::clean(['switcher_bg' => '#111827']);
    assert_eq(['switcher_bg' => '#111827'], $values, 'a field that is not posted is left alone');

    foreach (['red', '#12', '#1234', '#11182g', 'red;background:url(https://evil.example/x)', '#fff;position:fixed', '#fff" onmouseover="alert(1)', 'expression(alert(1))', 'var(--x)'] as $bad) {
        [, $errors] = MLT_SwitcherSettings::clean(['switcher_fg' => $bad]);
        assert_eq(['switcher_fg'], $errors, 'colour refused: ' . $bad);
    }
    [, $errors] = MLT_SwitcherSettings::clean(['switcher_style' => 'dropdown x" onclick="a', 'switcher_position' => 'center', 'switcher_enabled' => 'yes', 'switcher_accent' => ['#fff']]);
    assert_eq(['switcher_enabled', 'switcher_style', 'switcher_position', 'switcher_accent'], $errors, 'style, position, enabled and a non-scalar are refused');
});

unit('multilang switcher: a value stored before the check is printed as the default', function (): void {
    assert_eq('#111827', MLT_SwitcherSettings::printable('switcher_bg', 'red;background:url(x)'));
    assert_eq('#ffffff', MLT_SwitcherSettings::printable('switcher_fg', null));
    assert_eq('#123', MLT_SwitcherSettings::printable('switcher_accent', '#123'), 'a good colour is kept');
    assert_eq('dropdown', MLT_SwitcherSettings::printable('switcher_style', 'x" onclick="a'));
    assert_eq('bottom-right', MLT_SwitcherSettings::printable('switcher_position', 'nowhere'));
    assert_eq('header', MLT_SwitcherSettings::printable('switcher_position', 'header'));
});

unit('multilang switcher: the API refuses before saving, and the widget prints only checked values', function (): void {
    $root = dirname(__DIR__, 2) . '/plugins/multilang-translate';
    $api = (string) file_get_contents($root . '/admin/api.php');
    assert_true((bool) preg_match('/\[\$values, \$errors\] = MLT_SwitcherSettings::clean\(\$_POST\);\s*if \(\$errors !== \[\]\) \{\s*mlt_json\(.*?, 422\);\s*\}\s*foreach \(\$values as/s', $api), 'a refused field stops the save with a 422');
    assert_false(str_contains($api, '$val = (string)$_POST[$f];'), 'no raw posted value is stored');
    $render = (string) file_get_contents($root . '/assets/switcher-render.php');
    foreach (['switcher_style', 'switcher_position', 'switcher_bg', 'switcher_fg', 'switcher_accent'] as $field) {
        assert_true(str_contains($render, "MLT_SwitcherSettings::printable('{$field}', \$this->setting('{$field}'"), "{$field} is printed only after the check");
    }
    assert_true(str_contains((string) file_get_contents($root . '/MultilangTranslate.php'), "require_once __DIR__ . '/includes/SwitcherSettings.php';"), 'the plugin loads the class');
});
