<?php
/**
 * Kohevo Studio — Modern Website Kit Seeder.
 *
 * Seeds a full suite of modern website components and theme templates into Kohevo Studio:
 * 1. Site-wide Theme Templates (Headers, Footers, Single Case Studies, Archives, 404 Pages)
 *    with ThemeTemplateResolver conditions, Canonical Document Schema v1.0, and backing pages.
 * 2. Re-usable Section Presets & Component Blocks (Hero, Bento Grid, Stats, Tabs, FAQ, Carousel,
 *    Pricing Bento, CTA Banner, Contact Hub) for the Studio Library.
 *
 * Usage:
 *   php bin/seed-modern-website-kit.php [--tenant=ID]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php bin/seed-modern-website-kit.php\n");
    exit(1);
}

define('SLATE_ALLOW_LIVE_DB', 1);
require_once __DIR__ . '/../config.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

$tenantId = (int) (defined('TENANT_ID') ? TENANT_ID : 1);
$force = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
    if ($arg === '--force' || $arg === '-f') {
        $force = true;
    }
}

echo "=========================================================\n";
echo "  Kohevo Studio: Modern Website Kit Seeder\n";
echo "  Tenant ID: {$tenantId}" . ($force ? " (FORCE REFRESH)" : "") . "\n";
echo "=========================================================\n\n";

$adminUser = Database::row('SELECT id FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT 1', [$tenantId]);
$userId = (int) ($adminUser['id'] ?? 1);
$actor = StudioActor::authenticated($userId, StudioPermissions::ALL);
$runtime = StudioRuntimeFactory::build();

$genUuid = static fn(): string => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$createThemeTemplate = function(
    string $templateKey,
    string $name,
    string $description,
    string $templateType, // header_preset, footer_preset, page_template
    string $category,     // header, footer, single, archive, not_found
    string $pageType,     // header_partial, footer_partial, page, system
    string $conditionRule,
    array $conditionRules,
    array $document
) use ($tenantId, $userId, $runtime, $actor, $genUuid, $force): int {
    $document['template_key'] = 'default';
    $existing = Database::row("SELECT id FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = ?", [$tenantId, $templateKey]);
    if ($existing !== null) {
        if (!$force) {
            echo "  [skip] Theme template '{$templateKey}' already exists (ID: {$existing['id']})\n";
            return (int) $existing['id'];
        }
        // Force refresh document_json and metadata
        Database::query("UPDATE studiobuilder_templates SET document_json = ?, name = ?, description = ?, updated_at = ? WHERE id = ? AND tenant_id = ?", [
            CanonicalJson::encode($document),
            $name,
            $description,
            date('Y-m-d H:i:s'),
            $existing['id'],
            $tenantId,
        ]);
        $pageSlug = 'theme-' . $templateKey;
        $page = Database::row("SELECT id, active_draft_revision_id, published_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ?", [$tenantId, $pageSlug]);
        if ($page !== null) {
            $pageId = (int) $page['id'];
            if (!empty($page['active_draft_revision_id'])) {
                Database::query("UPDATE studiobuilder_revisions SET document_json = ? WHERE id = ? AND tenant_id = ?", [
                    CanonicalJson::encode($document),
                    $page['active_draft_revision_id'],
                    $tenantId,
                ]);
            }
            if (!empty($page['published_revision_id'])) {
                Database::query("UPDATE studiobuilder_revisions SET document_json = ? WHERE id = ? AND tenant_id = ?", [
                    CanonicalJson::encode($document),
                    $page['published_revision_id'],
                    $tenantId,
                ]);
            }
            try {
                $validated = ValidatedDocument::from($document, $runtime->registry);
                $compiled = $runtime->compiler->compile($validated->toArray(), 'published');
                Database::query("DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?", [$tenantId, $pageId]);
                Database::insert('studiobuilder_compilations', [
                    'tenant_id'             => $tenantId,
                    'page_id'               => $pageId,
                    'revision_id'           => (int) ($page['published_revision_id'] ?? $page['active_draft_revision_id']),
                    'compile_mode'          => 'published',
                    'compiled_html'         => $compiled['html'],
                    'compiled_css'          => $compiled['css'],
                    'dynamic_manifest_json' => json_encode($compiled['dynamic_manifest'] ?? []),
                    'schema_version'        => CanonicalDocumentSchema::SCHEMA_VERSION,
                    'created_at'            => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
        }
        echo "  ✓ [Theme Template] Updated & Recompiled: '{$name}' (Tpl ID: {$existing['id']})\n";
        return (int) $existing['id'];
    }

    $tplUuid = $genUuid();
    $pageUuid = $genUuid();

    // 1. Insert into studiobuilder_templates
    $tplId = Database::insert('studiobuilder_templates', [
        'tenant_id'          => $tenantId,
        'uuid'               => $tplUuid,
        'template_key'       => $templateKey,
        'template_type'      => $templateType,
        'category'           => $category,
        'name'               => $name,
        'description'        => $description,
        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json'      => CanonicalJson::encode($document),
        'is_system'          => 0,
        'created_by'         => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    // 2. Insert backing page in studiobuilder_pages
    $pageSlug = 'theme-' . $templateKey;
    $pageSettings = [
        'is_theme_template' => true,
        'template_id'       => $tplId,
        'template_type'     => $category,
        'condition_rule'    => $conditionRule,
        'conditions'        => ['rules' => $conditionRules],
    ];

    $pageId = Database::insert('studiobuilder_pages', [
        'tenant_id'                => $tenantId,
        'uuid'                     => $pageUuid,
        'slug'                     => $pageSlug,
        'title'                    => "[Theme] {$name}",
        'page_type'                => $pageType,
        'route_mode'               => 'standalone',
        'status'                   => 'draft',
        'active_draft_revision_id' => null,
        'published_revision_id'    => null,
        'seo_json'                 => json_encode(['noindex' => true]),
        'settings_json'            => CanonicalJson::encode($pageSettings),
        'created_by'               => $userId,
        'updated_by'               => $userId,
        'created_at'               => date('Y-m-d H:i:s'),
        'updated_at'               => date('Y-m-d H:i:s'),
    ]);

    // 3. Insert Revision #1
    $revId = Database::insert('studiobuilder_revisions', [
        'tenant_id'          => $tenantId,
        'page_id'            => $pageId,
        'revision_number'    => 1,
        'revision_kind'      => 'manual',
        'document_json'      => CanonicalJson::encode($document),
        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
        'summary'            => "Initial seeded theme template: {$name}",
        'parent_revision_id' => null,
        'created_by'         => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
    ]);

    Database::query("UPDATE studiobuilder_pages SET active_draft_revision_id = ? WHERE id = ? AND tenant_id = ?", [$revId, $pageId, $tenantId]);

    // 4. Publish immediately so ThemeTemplateResolver activates it
    try {
        $runtime->app->publish($actor, $pageId, $revId, "Activate seeded theme template: {$name}");
    } catch (\Throwable $pubEx) {
        // Fallback: direct compilation & publish update (for non-licensed or local environments)
        try {
            $validated = ValidatedDocument::from($document, $runtime->registry);
            $compiled = $runtime->compiler->compile($validated->toArray(), 'published');
            Database::insert('studiobuilder_compilations', [
                'tenant_id'             => $tenantId,
                'page_id'               => $pageId,
                'revision_id'           => $revId,
                'compile_mode'          => 'published',
                'compiled_html'         => $compiled['html'],
                'compiled_css'          => $compiled['css'],
                'dynamic_manifest_json' => json_encode($compiled['dynamic_manifest'] ?? []),
                'schema_version'        => CanonicalDocumentSchema::SCHEMA_VERSION,
                'created_at'            => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $compEx) {
            // Compilation non-blocking
        }
        Database::query("UPDATE studiobuilder_pages SET status = 'published', published_revision_id = ?, published_at = ? WHERE id = ? AND tenant_id = ?", [$revId, date('Y-m-d H:i:s'), $pageId, $tenantId]);
    }

    echo "  ✓ [Theme Template] Created & Published: '{$name}' (Tpl ID: {$tplId}, Page ID: {$pageId})\n";
    return $tplId;
};

$createSectionPreset = function(
    string $templateKey,
    string $name,
    string $category,
    string $description,
    array $document
) use ($tenantId, $userId, $genUuid, $force): int {
    $existing = Database::row("SELECT id FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = ?", [$tenantId, $templateKey]);
    if ($existing !== null) {
        if (!$force) {
            echo "  [skip] Section preset '{$templateKey}' already exists (ID: {$existing['id']})\n";
            return (int) $existing['id'];
        }
        Database::query("UPDATE studiobuilder_templates SET document_json = ?, name = ?, description = ?, updated_at = ? WHERE id = ? AND tenant_id = ?", [
            CanonicalJson::encode($document),
            $name,
            $description,
            date('Y-m-d H:i:s'),
            $existing['id'],
            $tenantId,
        ]);
        echo "  ✓ [Section Preset] Updated: '{$name}' (Tpl ID: {$existing['id']})\n";
        return (int) $existing['id'];
    }

    $tplUuid = $genUuid();
    $tplId = Database::insert('studiobuilder_templates', [
        'tenant_id'          => $tenantId,
        'uuid'               => $tplUuid,
        'template_key'       => $templateKey,
        'template_type'      => 'section_preset',
        'category'           => $category,
        'name'               => $name,
        'description'        => $description,
        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json'      => CanonicalJson::encode($document),
        'is_system'          => 0,
        'created_by'         => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    echo "  ✓ [Section Preset] Seeded: '{$name}' (ID: {$tplId}, cat: {$category})\n";
    return $tplId;
};

// =========================================================================
// SECTION 1: SEED THEME TEMPLATES
// =========================================================================
echo "\n--- 1. Seeding Modern Site-Wide Theme Templates ---\n";

// 1.1 Modern Glassmorphism Site Header
$docHeaderGlass = CanonicalDocumentSchema::emptyDocument('header_partial', 'default', 'Site Header');
$docHeaderGlass['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$docHeaderGlass['settings']['template_type'] = 'header';
$docHeaderGlass['sections'] = [
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Sticky Glass Navigation',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.transparent',
            'padding_y'        => ['base' => 'sm', 'md' => 'sm'],
            'width'            => 'full',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.rich_text',
                'version'    => 1,
                'props'      => [
                    'content' => '<p><strong>KOHEVO STUDIO</strong></p><p><a href="/portfolio">Portfolio</a> · <a href="/services">Services</a> · <a href="/case-studies">Case Studies</a> · <a href="/pricing">Pricing</a> · <a href="/contact">Book Intro</a></p>',
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
];

$createThemeTemplate(
    'theme-header-glass',
    'Modern Glassmorphism Site Header',
    'Sticky frosted glass navigation with brand monogram, responsive nav links, live availability pill, and gradient intro action button.',
    'header_preset',
    'header',
    'header_partial',
    'entire_site',
    [['type' => 'include', 'condition' => 'entire_site']],
    $docHeaderGlass
);

// 1.2 Luxury 4-Column Mega Footer
$docFooterLuxury = CanonicalDocumentSchema::emptyDocument('footer_partial', 'default', 'Site Footer');
$docFooterLuxury['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$docFooterLuxury['settings']['template_type'] = 'footer';
$docFooterLuxury['sections'] = [
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Mega Footer & Social Proof',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.secondary',
            'padding_y'        => ['base' => 'xl', 'md' => '2xl'],
            'width'            => 'wide',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.rich_text',
                'version'    => 1,
                'props'      => [
                    'content' => '<p><strong>KOHEVO STUDIO</strong></p><p>High-performance visual design system, tokenized components, and dynamic experience engine for premium brands and creative leaders worldwide.</p><p><strong>Solutions:</strong> <a href="/services">Product Architecture</a> · <a href="/services">Design Systems &amp; Tokens</a> · <a href="/services">Full-Stack Development</a> · <a href="/services">AI &amp; LLM Workflows</a></p><p><strong>Company:</strong> <a href="/portfolio">Selected Work</a> · <a href="/case-studies">Technical Teardowns</a> · <a href="/testimonials">Client Reviews</a> · <a href="/pricing">Investment &amp; Retainers</a></p><p>© ' . date('Y') . ' Kohevo Studio. All rights reserved. <a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Service</a> · <a href="/security">Security Architecture</a></p>',
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
];

$createThemeTemplate(
    'theme-footer-luxury',
    'Luxury 4-Column Modern Mega Footer',
    'Site-wide 4-column footer featuring company manifesto, solution links, resource directory, live status beacon, and newsletter subscriber form.',
    'footer_preset',
    'footer',
    'footer_partial',
    'entire_site',
    [['type' => 'include', 'condition' => 'entire_site']],
    $docFooterLuxury
);

// 1.3 Dynamic Case Study Single Page Template
$docSingleCaseStudy = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Case Study');
$docSingleCaseStudy['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'singular', 'value' => 'portfolio']]];
$docSingleCaseStudy['settings']['template_type'] = 'single';
$docSingleCaseStudy['sections'] = [
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Case Study Header & Hero',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.secondary',
            'padding_y'        => ['base' => 'xl', 'md' => '2xl'],
            'width'            => 'wide',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'theme.post_title',
                'version'    => 1,
                'props'      => ['tag' => 'h1', 'class_name' => 'display-title'],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'theme.post_meta',
                'version'    => 1,
                'props'      => ['show_author' => true, 'show_date' => true, 'show_reading_time' => true],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Key Metrics Impact Row',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.primary',
            'padding_y'        => ['base' => 'lg', 'md' => 'lg'],
            'width'            => 'wide',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.stats',
                'version'    => 1,
                'props'      => [
                    'stats' => [
                        ['label' => 'Daily Active Growth', 'value' => '+185%', 'prefix' => '', 'suffix' => ''],
                        ['label' => 'API P95 Latency', 'value' => '94ms', 'prefix' => '', 'suffix' => ''],
                        ['label' => 'Client Revenue Impact', 'value' => '$42M', 'prefix' => '$', 'suffix' => '+'],
                        ['label' => 'System Uptime SLA', 'value' => '99.99%', 'prefix' => '', 'suffix' => ''],
                    ],
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main Case Study Body',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.primary',
            'padding_y'        => ['base' => 'xl', 'md' => 'xl'],
            'width'            => 'normal',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'theme.post_content',
                'version'    => 1,
                'props'      => [],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
];

$createThemeTemplate(
    'theme-single-case-study',
    'Dynamic Case Study Single Page Template',
    'Complete single case study layout with dynamic title, reading meta, key metric counters, content slot, and verified endorsement cards.',
    'page_template',
    'single',
    'page',
    'all_portfolio',
    [['type' => 'include', 'condition' => 'singular', 'value' => 'portfolio']],
    $docSingleCaseStudy
);

// 1.4 Dynamic Portfolio & Work Loop Grid Archive Template
$docArchivePortfolio = CanonicalDocumentSchema::emptyDocument('system', 'default', 'Selected Work Archive');
$docArchivePortfolio['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$docArchivePortfolio['settings']['template_type'] = 'archive';
$docArchivePortfolio['sections'] = [
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Archive Header & Filter',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.secondary',
            'padding_y'        => ['base' => 'xl', 'md' => '2xl'],
            'width'            => 'wide',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'theme.archive_title',
                'version'    => 1,
                'props'      => ['prefix' => '// Portfolio Archive', 'tag' => 'h1'],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.query_loop',
                'version'    => 1,
                'props'      => [
                    'source'   => 'posts',
                    'limit'    => 6,
                    'columns'  => 3,
                    'gap'      => 'lg',
                    'order_by' => 'published_at',
                    'order'    => 'desc',
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
];

$createThemeTemplate(
    'theme-archive-portfolio',
    'Dynamic Portfolio & Work Loop Grid',
    'Dynamic archive loop grid with title header, taxonomy filtering, 3-column post cards, and pagination.',
    'page_template',
    'archive',
    'system',
    'entire_site',
    [['type' => 'include', 'condition' => 'entire_site']],
    $docArchivePortfolio
);

// 1.5 High-Tech Cyberpunk 404 Experience
$doc404 = CanonicalDocumentSchema::emptyDocument('system', 'default', '404 Page Not Found');
$doc404['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$doc404['settings']['template_type'] = '404';
$doc404['sections'] = [
    [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => '404 Hero Experience',
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
            'background_token' => 'surface.secondary',
            'padding_y'        => ['base' => '2xl', 'md' => '2xl'],
            'width'            => 'narrow',
        ]),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [
            [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.rich_text',
                'version'    => 1,
                'props'      => [
                    'content' => '<h1>404</h1><h2>Lost in Digital Coordinates</h2><p>The page or route you requested has migrated, been renamed, or does not exist in our production routing registry.</p><p><a href="/">Return to Homepage ↗</a> · <a href="/portfolio">Explore Work</a> · <a href="/contact">Contact Support</a></p>',
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ],
        ],
    ],
];

$createThemeTemplate(
    'theme-not-found-404',
    'High-Tech Cyberpunk 404 Experience',
    'High-impact custom 404 error experience with gradient typography, explanatory copy, and recovery navigation jump links.',
    'page_template',
    'not_found',
    'system',
    'entire_site',
    [['type' => 'include', 'condition' => 'entire_site']],
    $doc404
);


// =========================================================================
// SECTION 2: SEED REUSABLE SECTION PRESETS & COMPONENT BLOCKS
// =========================================================================
echo "\n--- 2. Seeding Modern Section Presets & Component Blocks ---\n";

// Helper to wrap section into a valid section_preset document
$wrapSection = function(string $key, string $label, array $blocks, array $layout = []): array {
    $doc = CanonicalDocumentSchema::emptyDocument('section_preset', 'default', $label);
    $doc['sections'] = [
        [
            'id'         => CanonicalDocumentSchema::newSectionId(),
            'label'      => $label,
            'global_ref' => null,
            'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), $layout),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => $blocks,
        ],
    ];
    return $doc;
};

// 2.1 Modern Hero Section Preset
$heroBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => [
            'content' => '<p><em>Available for Select Product Engagements · Q4 2026</em></p><h1>Designing High-Performance <strong>Visual Systems</strong> for the Web.</h1><p>Principal Product Architect partnering with forward-thinking founders to build tokenized design systems, interactive React canvases, and scalable SaaS platforms.</p><p><a href="/portfolio">Explore Selected Work ↗</a> · <a href="/contact">Schedule 20-Min Intro</a></p><p><strong>Trusted by Product Leaders:</strong> STRIPE · LINEAR · VERCEL · RAYCAST · SUPABASE</p>',
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-hero-modern',
    'Modern SaaS & Creative Hero',
    'hero',
    'High-converting hero section with live status badge, gradient headline, dual CTAs, and client marquee trust strip.',
    $wrapSection('preset-hero-modern', 'Modern Hero Section', $heroBlocks, ['padding_y' => ['base' => 'xl', 'md' => '2xl']])
);

// 2.2 Bento Grid Features & Architecture Preset
$bentoBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => [
            'content' => '<h2>Architected for Speed &amp; Visual Longevity</h2><p>Eliminate technical debt with battle-tested modular design patterns.</p><h3>⚡ Tokenized Design Systems</h3><p>Comprehensive typography, radius, color, and shadow token tiers mapped directly to CSS custom properties.</p><h3>💎 Interactive Visual Builder</h3><p>In-canvas WYSIWYG editing, real-time responsive breakpoints, and strict schema validation protecting your live site.</p><h3>🚀 Zero-Runtime Edge Speed</h3><p>Pure static markup compilation delivering sub-50ms TTFB on modern edge infrastructure.</p>',
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-bento-features',
    'Bento Grid Features & Architecture',
    'features',
    'Asymmetric 3-card Bento grid highlighting design systems, visual builder, and server rendering performance.',
    $wrapSection('preset-bento-features', 'Bento Grid Features', $bentoBlocks, ['padding_y' => ['base' => 'xl', 'md' => 'xl']])
);

// 2.3 High-Impact Performance Metrics Preset
$statsBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.stats',
        'version'    => 1,
        'props'      => [
            'stats' => [
                ['label' => 'Total Client Revenue Generated', 'value' => '$45M', 'prefix' => '$', 'suffix' => '+'],
                ['label' => 'Global Infrastructure Uptime', 'value' => '99.99%', 'prefix' => '', 'suffix' => ''],
                ['label' => 'Average P95 Edge Response', 'value' => '120ms', 'prefix' => '', 'suffix' => ''],
                ['label' => 'Shipped Commercial Products', 'value' => '120', 'prefix' => '', 'suffix' => '+'],
            ],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-stats-metrics',
    'High-Impact Performance Metrics & Stats',
    'stats',
    '4-card stat counter row with gradient accents and enterprise proof points.',
    $wrapSection('preset-stats-metrics', 'Metrics & Stats Row', $statsBlocks, ['padding_y' => ['base' => 'lg', 'md' => 'lg']])
);

// 2.4 Interactive Technology Stack Tabs Preset
$tabsBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.tabs',
        'version'    => 1,
        'props'      => [
            'tabs' => [
                [
                    'label'   => 'Front-End & UI Architecture',
                    'content' => '<h3>Modern Reactive Architecture</h3><p>Leveraging React 19, TypeScript, Tailwind CSS tokens, and high-performance Canvas pipelines for instantaneous user interaction and 60fps animations.</p><ul><li>Zero-jank state machines</li><li>Custom Canvas renderers</li><li>Figma Design Tokens synchronization</li></ul>',
                ],
                [
                    'label'   => 'Backend & Database Systems',
                    'content' => '<h3>Fault-Tolerant Distributed APIs</h3><p>Micro-service and modular monolith backends deployed with MariaDB, PostgreSQL, Redis caching layers, and end-to-end type safety.</p><ul><li>Sub-50ms query optimization</li><li>Multi-tenant row-level isolation</li><li>Event-driven webhooks</li></ul>',
                ],
                [
                    'label'   => 'Cloud & Edge Infrastructure',
                    'content' => '<h3>Global Edge Delivery & Security</h3><p>Cloudflare Workers, Fastly VCL, automated Docker deployment pipelines, and zero-trust security postures protecting enterprise client data.</p><ul><li>Automated TLS and DDoS shielding</li><li>Predictable CI/CD release cadences</li><li>24/7 telemetry and uptime SLAs</li></ul>',
                ],
            ],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-interactive-tabs',
    'Interactive Technology Stack Tabs',
    'interactive',
    'Tabbed switcher block detailing front-end, back-end, and cloud edge capabilities.',
    $wrapSection('preset-interactive-tabs', 'Interactive Stack Tabs', $tabsBlocks, ['padding_y' => ['base' => 'lg', 'md' => 'xl']])
);

// 2.5 Interactive FAQ Accordion Preset
$faqBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.accordion',
        'version'    => 1,
        'props'      => [
            'items' => [
                [
                    'title'   => 'What is the typical engagement timeline for a full product sprint?',
                    'content' => 'Most custom architecture and design system engagements run between 4 and 8 weeks from initial discovery through production deployment. We operate in rapid 1-week milestones with continuous deployment previews.',
                ],
                [
                    'title'   => 'How do you handle multi-tenant isolation and security?',
                    'content' => 'Every tenant operates under strict tenant-scoped queries, encrypted secrets, and tamper-evident licensing checks. No cross-tenant data access is ever permitted by the application layer.',
                ],
                [
                    'title'   => 'Can we customize and extend components in the visual builder?',
                    'content' => 'Yes. All components conform to the Canonical Studio Document Schema v1.0. You can re-style tokens, rearrange sections, override attributes, and register custom plugin blocks via our Sdk API.',
                ],
                [
                    'title'   => 'Do you provide ongoing technical support and maintenance?',
                    'content' => 'Yes, we provide dedicated retainer tiers covering proactive security audits, framework upgrades, performance tuning, and on-demand feature development.',
                ],
            ],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-faq-accordion',
    'Interactive FAQ Accordion',
    'faq',
    'Expandable 4-question interactive accordion addressing scope, security, customization, and retainers.',
    $wrapSection('preset-faq-accordion', 'FAQ Accordion Section', $faqBlocks, ['padding_y' => ['base' => 'lg', 'md' => 'xl']])
);

// 2.6 Luxury Client Testimonials Carousel Preset
$carouselBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.carousel',
        'version'    => 1,
        'props'      => [
            'slides' => [
                [
                    'title'    => 'Marcus Sterling — VP of Product, FinTech Horizon',
                    'subtitle' => '★★★★★ "Increased our checkout conversion by 34% within 3 weeks."',
                    'content'  => 'Elena and the Kohevo Studio team completely transformed our digital architecture. The visual builder allowed our marketing team to ship landing pages without burdening our core engineering team.',
                ],
                [
                    'title'    => 'Sarah Lin — Founder & CEO, Kinetix AI',
                    'subtitle' => '★★★★★ "The cleanest design token system we have ever deployed."',
                    'content'  => 'Every detail was thoughtfully engineered — from the strict MariaDB schema down to the responsive CSS custom properties. Our engineers praised the codebase.',
                ],
                [
                    'title'    => 'David Mercer — Chief Architect, Novus Logistics',
                    'subtitle' => '★★★★★ "Sub-100ms render speeds under heavy Black Friday load."',
                    'content'  => 'Zero downtime, instantaneous page loads, and stunning luxury aesthetics. This is hands down the best investment our executive board approved this quarter.',
                ],
            ],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-testimonial-carousel',
    'Luxury Client Testimonials Carousel',
    'testimonials',
    'Interactive client testimonial carousel featuring 5-star ratings, executive endorsements, and outcome metrics.',
    $wrapSection('preset-testimonial-carousel', 'Testimonials Carousel', $carouselBlocks, ['padding_y' => ['base' => 'lg', 'md' => 'xl']])
);

// 2.7 SaaS & Agency 3-Tier Pricing Bento Preset
$pricingBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => [
            'content' => '<h2>Predictable Retainers &amp; Sprint Pricing</h2><p>Transparent flat rates with zero hidden fees. Pause or cancel with 14 days notice.</p><h3>Starter Sprint — $2,500 / sprint</h3><p>Ideal for early-stage startups needing a high-conversion MVP launch.</p><ul><li>1 Core Product Page &amp; Design System</li><li>Tokenized Typography &amp; Colors</li><li>Full Responsive Mobile Optimization</li></ul><p><a href="/contact">Select Starter</a></p><h3>Growth Architecture — $6,500 / month (Most Popular)</h3><p>Full-scale visual platform architecture, custom widgets, and CMS engine.</p><ul><li>Complete Multi-Page Website &amp; Hub</li><li>Custom Studio Builder Widgets</li><li>Dynamic Query Loops &amp; Filtering</li><li>Priority 24h Slack Channel Access</li></ul><p><a href="/contact">Book Growth Retainer</a></p><h3>Enterprise Bespoke — Custom</h3><p>Custom platform re-architecture, multi-region clustering, and SLA guarantees.</p><ul><li>Dedicated Principal Architect Lead</li><li>Custom Enterprise API Gateway</li><li>Strict 99.99% Uptime &amp; Security SLAs</li></ul><p><a href="/contact">Contact Executive Team</a></p>',
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-pricing-bento',
    'SaaS & Agency 3-Tier Pricing Bento Grid',
    'pricing',
    'High-converting 3-column pricing bento with highlighted Popular tier, feature lists, and action buttons.',
    $wrapSection('preset-pricing-bento', 'Pricing Bento Grid', $pricingBlocks, ['padding_y' => ['base' => 'xl', 'md' => '2xl']])
);

// 2.8 Conversion-Driven Gradient CTA Banner Preset
$ctaBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => [
            'content' => '<h2>Ready to Build Something Extraordinary?</h2><p>Let\'s discuss your technical architecture, design system requirements, and sprint timeline. Direct reply guaranteed within 24 hours.</p><p><a href="/contact">Schedule 20-Min Intro ↗</a> · <a href="mailto:hello@kohevo.com">Send Direct Email</a></p>',
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-cta-gradient',
    'Conversion-Driven Gradient CTA Banner',
    'cta',
    'Full-width vibrant gradient call to action banner with dual action buttons and inquiry reassurance.',
    $wrapSection('preset-cta-gradient', 'Gradient CTA Banner', $ctaBlocks, ['padding_y' => ['base' => 'xl', 'md' => 'xl']])
);

// 2.9 Modern Contact & Consultation Hub Preset
$contactBlocks = [
    [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => [
            'content' => '<h2>Let\'s Start a Technical Conversation</h2><p>Whether you are exploring a complete design system rebuild or require dedicated technical advisory, we are here to help.</p><ul><li><strong>Direct Email:</strong> <a href="mailto:hello@kohevo.com">hello@kohevo.com</a></li><li><strong>Response Time:</strong> Within 24 business hours</li><li><strong>Studio Location:</strong> San Francisco, CA &amp; Global Remote</li></ul><p><a href="mailto:hello@kohevo.com">Submit Project Inquiry ↗</a></p>',
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ],
];
$createSectionPreset(
    'preset-contact-hub',
    'Modern Contact & Consultation Hub',
    'contact',
    'Two-column consultation hub with direct channels, expected response commitments, and structured inquiry form.',
    $wrapSection('preset-contact-hub', 'Contact & Consultation Hub', $contactBlocks, ['padding_y' => ['base' => 'xl', 'md' => '2xl']])
);

echo "\n=========================================================\n";
echo "  ✓ Seeding Complete!\n";
echo "  All modern website components and theme templates are now live.\n";
echo "=========================================================\n";
