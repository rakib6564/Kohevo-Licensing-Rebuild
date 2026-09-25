<?php
/**
 * Unit tests for DocumentSchemaV2.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchemaV2;

unit('DocumentSchemaV2: legacy flat array converts to section + blocks with v2 envelope', function () {
    $legacy = [
        ['type' => 'heading', 'props' => ['text' => 'Hello World', 'level' => 1]],
        ['type' => 'paragraph', 'props' => ['text' => 'Welcome to Slate.']],
    ];

    $v2 = DocumentSchemaV2::normalize($legacy, ['title' => 'Sample Page', 'slug' => 'sample']);

    assert_eq(2, $v2['version']);
    assert_eq('Sample Page', $v2['header']['title']);
    assert_eq('sample', $v2['header']['slug']);
    assert_eq(1, count($v2['sections']));

    $sec = $v2['sections'][0];
    assert_eq('sec-1', $sec['id']);
    assert_eq(2, count($sec['blocks']));
    assert_eq('heading', $sec['blocks'][0]['type']);
    assert_eq('Hello World', $sec['blocks'][0]['props']['text']);
    assert_eq('all', $sec['blocks'][0]['visibility']['device']);
});

unit('DocumentSchemaV2: v1 envelope normalizes to v2 structure', function () {
    $v1 = [
        'schema'   => 1,
        'type'     => 'page',
        'template' => 'wide',
        'sections' => [
            [
                'id'     => 's1',
                'layout' => ['cols' => 2],
                'blocks' => [
                    ['type' => 'hero', 'props' => ['headline' => 'Hero Banner']],
                ],
            ],
        ],
        'seo' => ['title' => 'SEO Title'],
    ];

    $v2 = DocumentSchemaV2::normalize($v1);
    assert_eq(2, $v2['version']);
    assert_eq('wide', $v2['header']['template']);
    assert_eq('SEO Title', $v2['header']['seo']['title']);
    assert_eq(1, count($v2['sections']));
    assert_eq('s1', $v2['sections'][0]['id']);
    assert_eq(2, $v2['sections'][0]['layout']['cols']);
    assert_eq('hero', $v2['sections'][0]['blocks'][0]['type']);
});

unit('DocumentSchemaV2: preserves nested children, bindings, and visibility in v2 tree', function () {
    $tree = [
        'version' => 2,
        'header'  => ['title' => 'Nested Tree', 'status' => 'published'],
        'sections' => [
            [
                'id'    => 'sec-hero',
                'name'  => 'Hero Section',
                'props' => ['background' => '#0f172a'],
                'blocks' => [
                    [
                        'id'         => 'blk-col',
                        'type'       => 'columns',
                        'bindings'   => ['items' => 'posts.latest'],
                        'visibility' => ['device' => 'desktop'],
                        'children'   => [
                            ['id' => 'blk-col-1', 'type' => 'card', 'props' => ['title' => 'Card 1']],
                            ['id' => 'blk-col-2', 'type' => 'card', 'props' => ['title' => 'Card 2']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $v2 = DocumentSchemaV2::normalize($tree);
    assert_eq(2, $v2['version']);
    assert_eq('#0f172a', $v2['sections'][0]['props']['background']);
    $col = $v2['sections'][0]['blocks'][0];
    assert_eq('blk-col', $col['id']);
    assert_eq('desktop', $col['visibility']['device']);
    assert_eq('posts.latest', $col['bindings']['items']);
    assert_eq(2, count($col['children']));
    assert_eq('blk-col-1', $col['children'][0]['id']);
    assert_eq('Card 1', $col['children'][0]['props']['title']);
});

unit('DocumentSchemaV2: toV1Envelope and toFlatBlocks export accurately', function () {
    $v2 = [
        'version' => 2,
        'header'  => ['type' => 'post', 'template' => 'single', 'seo' => ['title' => 'Post SEO']],
        'sections' => [
            [
                'id'     => 'sec-a',
                'layout' => ['cols' => 1],
                'blocks' => [
                    ['type' => 'heading', 'props' => ['text' => 'Title']],
                    [
                        'type'     => 'columns',
                        'props'    => ['count' => 2],
                        'children' => [
                            ['type' => 'card', 'props' => ['title' => 'Card 1']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $v1 = DocumentSchemaV2::toV1Envelope($v2);
    assert_eq(1, $v1['schema']);
    assert_eq('post', $v1['type']);
    assert_eq('single', $v1['template']);
    assert_eq(1, count($v1['sections']));
    assert_eq(2, count($v1['sections'][0]['blocks']));

    $flat = DocumentSchemaV2::toFlatBlocks($v2);
    assert_eq(3, count($flat)); // heading + columns + card
    assert_eq('heading', $flat[0]['type']);
    assert_eq('columns', $flat[1]['type']);
    assert_eq('card', $flat[2]['type']);
});
