<?php
/** Phase 2 DocumentValidator contract tests. */
declare(strict_types=1);

use Slate\Presentation\DocumentSchema;
use Slate\Presentation\DocumentValidator;

$registry = [
    'hero' => [
        'fields' => [
            ['key' => 'heading', 'type' => 'text', 'required' => true, 'maxLength' => 40, 'responsive' => false],
            ['key' => 'align', 'type' => 'select', 'options' => ['start', 'center'], 'responsive' => true],
            ['key' => 'media', 'type' => 'media'],
            ['key' => 'score', 'type' => 'number', 'min' => 0, 'max' => 100, 'integer' => true],
            ['key' => 'enabled', 'type' => 'boolean'],
            ['key' => 'items', 'type' => 'repeater', 'maxItems' => 2, 'item' => [
                ['key' => 'label', 'type' => 'text', 'required' => true, 'maxLength' => 20],
            ]],
        ],
        'defaults' => ['align' => 'start'],
    ],
    'global_ref' => [
        'fields' => [
            ['key' => '$ref', 'type' => 'text', 'required' => true, 'maxLength' => 128],
        ],
        'defaults' => [],
    ],
    'container' => [
        'capabilities' => ['nested' => true],
        'nestedKeys' => ['children'],
        'fields' => [
            ['key' => 'children', 'type' => 'blocks'],
        ],
        'defaults' => [],
    ],
];

$fixture = static function (string $name): mixed {
    $path = __DIR__ . '/../fixtures/phase2/' . $name;
    return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
};

$fixtureValid = $fixture('valid-global-layout.json');
$fixtureMinimal = $fixture('minimal-document.json');
$fixtureLegacy = $fixture('legacy-flat-layout.json');
$fixtureInvalid = $fixture('invalid-editor-state.json');

$r = DocumentValidator::validate($fixtureValid, $registry);
assert_true($r['valid'], 'standalone valid schema-1 fixture passes');
assert_eq('global-header', $r['document']['sections'][0]['savedAs'], 'fixture global alias survives validation');

$r = DocumentValidator::validate($fixtureMinimal, $registry);
assert_true($r['valid'], 'standalone minimal schema-1 fixture passes');
assert_eq(1, $r['document']['schema'], 'minimal fixture remains schema 1');

$r = DocumentValidator::validate($fixtureLegacy, $registry, ['type' => 'page']);
assert_true($r['valid'], 'standalone legacy fixture upconverts');
assert_eq('s1', $r['document']['sections'][0]['id'], 'legacy fixture receives stable section id');

$r = DocumentValidator::validate($fixtureInvalid, $registry);
assert_false($r['valid'], 'standalone invalid fixture is rejected');
assert_true(in_array('unknown_property', array_column($r['errors'], 'code'), true), 'fixture transient state is rejected');
assert_true(in_array('alias', array_column($r['errors'], 'code'), true), 'fixture unsafe alias is rejected');

$valid = [
    'schema' => 1,
    'type' => 'page',
    'template' => '',
    'sections' => [[
        'id' => 's1',
        'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'],
        'savedAs' => 'global-header',
        'blocks' => [[
            'type' => 'hero',
            'props' => [
                'heading' => 'Nutrition made practical',
                'align' => ['base' => 'start', 'md' => 'center'],
                'media' => ['key' => 'hero.primary', 'alt' => 'Food', 'focal' => [0.5, 0.4]],
            ],
            'style' => ['visible' => ['base' => true, 'md' => true]],
        ], [
            'type' => 'global_ref',
            'props' => ['$ref' => 'global-header'],
        ]],
    ]],
    'seo' => [],
];

$badCoercion = $valid;
$badCoercion['sections'][0]['blocks'][0]['props']['score'] = '0';
$badCoercion['sections'][0]['blocks'][0]['props']['enabled'] = 'false';
$r = DocumentValidator::validate($badCoercion, $registry);
assert_false($r['valid'], 'string numbers and booleans are not coerced');

$badBounds = $valid;
$badBounds['sections'][0]['blocks'][0]['props']['score'] = 101;
$r = DocumentValidator::validate($badBounds, $registry);
assert_false($r['valid'], 'numeric bounds are enforced');

$badRepeater = $valid;
$badRepeater['sections'][0]['blocks'][0]['props']['items'] = [['label' => 'a'], ['label' => 'b'], ['label' => 'c']];
$r = DocumentValidator::validate($badRepeater, $registry);
assert_false($r['valid'], 'array limits are enforced');

$badRepeaterItem = $valid;
$badRepeaterItem['sections'][0]['blocks'][0]['props']['items'] = [['wrong' => 'x']];
$r = DocumentValidator::validate($badRepeaterItem, $registry);
assert_false($r['valid'], 'nested repeater fields are strictly declared');

$badMediaAlt = $valid;
unset($badMediaAlt['sections'][0]['blocks'][0]['props']['media']['alt']);
$r = DocumentValidator::validate($badMediaAlt, $registry);
assert_false($r['valid'], 'media alt text is required');

$badFocalType = $valid;
$badFocalType['sections'][0]['blocks'][0]['props']['media']['focal'] = ['0.5', 0.4];
$r = DocumentValidator::validate($badFocalType, $registry);
assert_false($r['valid'], 'media focal values are not coerced');

$legacyUnknown = $valid;
$legacyUnknown['sections'][0]['blocks'] = [['type' => 'retired_block', 'props' => ['legacy' => true]]];
$r = DocumentValidator::validate($legacyUnknown, $registry, ['allow_unknown_existing' => true, 'existing' => $legacyUnknown]);
assert_true($r['valid'], 'unchanged unknown legacy block can be preserved');
assert_eq('unknown_block_preserved', $r['warnings'][0]['code'], 'unknown preservation is reported as a warning');

$newUnknown = DocumentValidator::validate($legacyUnknown, $registry, ['allow_unknown_existing' => true, 'existing' => $valid]);
assert_false($newUnknown['valid'], 'new unknown block is rejected even in compatibility mode');

$r = DocumentValidator::validate($valid, $registry);
assert_true($r['valid'], 'valid structured document passes');
assert_eq([], $r['errors'], 'valid document has no errors');
assert_eq('start', $r['document']['sections'][0]['blocks'][0]['props']['align']['base'], 'responsive value preserved');
assert_eq('global-header', $r['document']['sections'][0]['savedAs'], 'global alias preserved');

$legacy = DocumentValidator::validate([
    ['type' => 'hero', 'props' => ['heading' => 'Legacy']],
], $registry, ['type' => 'page']);
assert_true($legacy['valid'], 'legacy flat layout is accepted');
assert_eq(1, count($legacy['document']['sections']), 'legacy layout becomes one section');
assert_eq('s1', $legacy['document']['sections'][0]['id'], 'legacy section id is deterministic');

$badUnknown = $valid;
$badUnknown['sections'][0]['blocks'][0]['props']['onClick'] = 'alert(1)';
$r = DocumentValidator::validate($badUnknown, $registry);
assert_false($r['valid'], 'undeclared prop is rejected');
assert_true(in_array('unknown_property', array_column($r['errors'], 'code'), true), 'undeclared prop error code');

$badTransient = $valid;
$badTransient['sections'][0]['blocks'][0]['nodeId'] = 'craft-node-1';
$r = DocumentValidator::validate($badTransient, $registry);
assert_false($r['valid'], 'transient editor property is rejected');

$badUrl = $valid;
$badUrl['sections'][0]['blocks'][0]['props']['media']['key'] = '../secret';
$r = DocumentValidator::validate($badUrl, $registry);
assert_false($r['valid'], 'invalid media key is rejected');

$badResponsive = $valid;
$badResponsive['sections'][0]['blocks'][0]['props']['align'] = ['xxl' => 'center'];
$r = DocumentValidator::validate($badResponsive, $registry);
assert_false($r['valid'], 'unknown responsive breakpoint is rejected');

$badGlobal = $valid;
$badGlobal['sections'][0]['blocks'][1]['props']['$ref'] = '../footer';
$r = DocumentValidator::validate($badGlobal, $registry);
assert_false($r['valid'], 'unsafe global block alias is rejected');

$badNested = $valid;
$badNested['sections'][0]['blocks'] = [[
    'type' => 'container',
    'props' => ['children' => [['type' => 'unknown', 'props' => []]]],
]];
$r = DocumentValidator::validate($badNested, $registry);
assert_false($r['valid'], 'unknown nested block is rejected');

$badHtml = $valid;
$badHtml['sections'][0]['blocks'][0]['props']['heading'] = str_repeat('x', 41);
$r = DocumentValidator::validate($badHtml, $registry);
assert_false($r['valid'], 'field length limit is enforced');

$badScheme = $valid;
$badScheme['sections'][0]['blocks'][0]['props']['media']['alt'] = "ok\0bad";
$r = DocumentValidator::validate($badScheme, $registry);
assert_false($r['valid'], 'control characters are rejected');

$badJson = DocumentValidator::validate('{"schema":', $registry);
assert_false($badJson['valid'], 'malformed JSON is rejected');
assert_eq('invalid_json', $badJson['errors'][0]['code'], 'malformed JSON error code');

$normalizedTwice = DocumentSchema::normalize($r['document'] ?? $valid, 'page');
$normalizedAgain = DocumentSchema::normalize($normalizedTwice, 'page');
assert_eq($normalizedTwice, $normalizedAgain, 'canonical normalization remains idempotent');

// Regression: the editor's Style panel (admin/editor.php's writeStyle()) writes
// textAlign/bgColor/bgImage/paddingTop/etc — validateStyle() previously only
// allowed visible/align/className, so saving any block with a style customization
// applied through the editor's UI failed outright with unknown_property errors.
$styled = $valid;
$styled['sections'][0]['blocks'][0]['style'] = [
    'textAlign' => 'center', 'bgColor' => '#ff0000', 'textColor' => '#00ff00',
    'bgImage' => '/uploads/x.jpg', 'bgOverlay' => 'rgba(0,0,0,0.5)', 'bgOpacity' => 80,
    'paddingTop' => 10, 'paddingBottom' => 10, 'paddingLeft' => 0, 'paddingRight' => 0,
    'marginTop' => 5, 'marginBottom' => 5, 'borderRadius' => 4, 'maxWidth' => '600px',
    'customClass' => 'my-class', 'hideDesktop' => false, 'hideTablet' => true, 'hideMobile' => false,
    'cssId' => 'my-anchor', 'zIndex' => 5,
    'responsive' => ['tablet' => ['textAlign' => 'left'], 'mobile' => ['hideMobile' => true]],
];
$r = DocumentValidator::validate($styled, $registry);
assert_true($r['valid'], 'the editor Style panel\'s full field set must validate: ' . json_encode($r['errors']));

$badStyle = $valid;
$badStyle['sections'][0]['blocks'][0]['style'] = ['bgImage' => 'javascript:alert(1)', 'customClass' => '<script>', 'bgOpacity' => 500];
$r = DocumentValidator::validate($badStyle, $registry);
assert_false($r['valid'], 'unsafe/out-of-range style values are rejected');
assert_true(in_array('unsafe_url', array_column($r['errors'], 'code'), true), 'a javascript: bgImage is rejected');
assert_true(in_array('class_name', array_column($r['errors'], 'code'), true), 'an invalid customClass is rejected');
assert_true(in_array('range', array_column($r['errors'], 'code'), true), 'an out-of-range bgOpacity is rejected');

$badAdvanced = $valid;
$badAdvanced['sections'][0]['blocks'][0]['style'] = ['cssId' => '1-starts-with-a-digit', 'zIndex' => 100000];
$r = DocumentValidator::validate($badAdvanced, $registry);
assert_false($r['valid'], 'an invalid CSS ID and an out-of-range z-index are both rejected');
assert_true(in_array('pattern', array_column($r['errors'], 'code'), true), 'a CSS ID starting with a digit is rejected');
assert_true(in_array('range', array_column($r['errors'], 'code'), true), 'an out-of-range zIndex is rejected');

$badResponsive = $valid;
$badResponsive['sections'][0]['blocks'][0]['style'] = ['responsive' => ['desktop' => ['textAlign' => 'center']]];
$r = DocumentValidator::validate($badResponsive, $registry);
assert_false($r['valid'], 'responsive style overrides only support tablet/mobile, not desktop');
