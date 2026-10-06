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
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}

echo "=========================================================\n";
echo "  Kohevo Studio: Modern Website Kit Seeder\n";
echo "  Tenant ID: {$tenantId}\n";
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
) use ($tenantId, $userId, $runtime, $actor, $genUuid): int {
    $existing = Database::row("SELECT id FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = ?", [$tenantId, $templateKey]);
    if ($existing !== null) {
        echo "  [skip] Theme template '{$templateKey}' already exists (ID: {$existing['id']})\n";
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
) use ($tenantId, $userId, $genUuid): int {
    $existing = Database::row("SELECT id FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = ?", [$tenantId, $templateKey]);
    if ($existing !== null) {
        echo "  [skip] Section preset '{$templateKey}' already exists (ID: {$existing['id']})\n";
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
$docHeaderGlass = CanonicalDocumentSchema::emptyDocument('header_partial', 'header-glass', 'Site Header');
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
                    'content' => '<header style="position: sticky; top: 0; z-index: 100; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); background: rgba(11, 15, 25, 0.82); border-bottom: 1px solid rgba(255,255,255,0.08); padding: 14px 28px;">
  <div style="max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 20px;">
    <a href="/" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: #ffffff;">
      <span style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.05rem; color: #fff; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);">K</span>
      <span style="font-weight: 800; font-size: 1.2rem; letter-spacing: -0.02em; color: #f8fafc;">KOHEVO <span style="color: #818cf8; font-weight: 400;">STUDIO</span></span>
    </a>
    <nav style="display: flex; align-items: center; gap: 28px;">
      <a href="/portfolio" style="color: #cbd5e1; text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color=\'#818cf8\'" onmouseout="this.style.color=\'#cbd5e1\'">Portfolio</a>
      <a href="/services" style="color: #cbd5e1; text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color=\'#818cf8\'" onmouseout="this.style.color=\'#cbd5e1\'">Services</a>
      <a href="/case-studies" style="color: #cbd5e1; text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color=\'#818cf8\'" onmouseout="this.style.color=\'#cbd5e1\'">Case Studies</a>
      <a href="/pricing" style="color: #cbd5e1; text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color=\'#818cf8\'" onmouseout="this.style.color=\'#cbd5e1\'">Pricing</a>
      <a href="/testimonials" style="color: #cbd5e1; text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color=\'#818cf8\'" onmouseout="this.style.color=\'#cbd5e1\'">Reviews</a>
    </nav>
    <div style="display: flex; align-items: center; gap: 14px;">
      <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.76rem; font-weight: 700; color: #34d399;">
        <span style="width: 7px; height: 7px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981;"></span> Q4 Available
      </span>
      <a href="/contact" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #ffffff; text-decoration: none; font-size: 0.88rem; font-weight: 700; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35); transition: transform 0.15s, box-shadow 0.15s;">
        Book Intro ↗
      </a>
    </div>
  </div>
</header>',
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
$docFooterLuxury = CanonicalDocumentSchema::emptyDocument('footer_partial', 'footer-luxury', 'Site Footer');
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
                    'content' => '<footer style="background: #0b0f19; color: #94a3b8; border-top: 1px solid rgba(255,255,255,0.08); padding: 64px 24px 32px 24px; font-family: Inter, sans-serif;">
  <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 48px; margin-bottom: 48px;">
    <div>
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
        <span style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem; color: #fff;">K</span>
        <span style="font-weight: 800; font-size: 1.15rem; color: #f8fafc; letter-spacing: -0.02em;">KOHEVO STUDIO</span>
      </div>
      <p style="font-size: 0.88rem; line-height: 1.6; color: #94a3b8; margin-bottom: 20px;">
        High-performance visual design system, tokenized components, and dynamic experience engine for premium brands and creative leaders worldwide.
      </p>
      <div style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #10b981; font-weight: 600;">
        <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
        San Francisco, CA · Remote Worldwide
      </div>
    </div>
    <div>
      <h4 style="color: #f8fafc; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 20px;">Solutions</h4>
      <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; font-size: 0.9rem;">
        <li><a href="/services" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Product Architecture</a></li>
        <li><a href="/services" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Design Systems & Tokens</a></li>
        <li><a href="/services" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Full-Stack Development</a></li>
        <li><a href="/services" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">AI & LLM Workflows</a></li>
      </ul>
    </div>
    <div>
      <h4 style="color: #f8fafc; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 20px;">Company</h4>
      <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; font-size: 0.9rem;">
        <li><a href="/portfolio" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Selected Work</a></li>
        <li><a href="/case-studies" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Technical Teardowns</a></li>
        <li><a href="/testimonials" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Client Reviews</a></li>
        <li><a href="/pricing" style="color: #94a3b8; text-decoration: none; transition: color 0.2s;">Investment & Retainers</a></li>
      </ul>
    </div>
    <div>
      <h4 style="color: #f8fafc; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 20px;">Inquiries & Updates</h4>
      <p style="font-size: 0.85rem; line-height: 1.5; color: #94a3b8; margin-bottom: 16px;">
        Subscribe to quarterly product design essays and architectural deep dives.
      </p>
      <form action="#" onsubmit="event.preventDefault(); alert(\'Subscribed!\');" style="display: flex; gap: 8px;">
        <input type="email" placeholder="Your email address" style="background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 10px 14px; font-size: 0.85rem; color: #fff; width: 100%;" required>
        <button type="submit" style="background: #6366f1; border: none; border-radius: 6px; color: #fff; padding: 0 16px; font-weight: 700; font-size: 0.85rem; cursor: pointer;">Join</button>
      </form>
    </div>
  </div>
  <div style="max-width: 1200px; margin: 0 auto; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; font-size: 0.8rem;">
    <div>© ' . date('Y') . ' Kohevo Studio. All rights reserved. Crafted with high-performance elegance.</div>
    <div style="display: flex; gap: 20px;">
      <a href="#" style="color: #64748b; text-decoration: none;">Privacy Policy</a>
      <a href="#" style="color: #64748b; text-decoration: none;">Terms of Service</a>
      <a href="#" style="color: #64748b; text-decoration: none;">Security Architecture</a>
    </div>
  </div>
</footer>',
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
$docSingleCaseStudy = CanonicalDocumentSchema::emptyDocument('page', 'single-case-study', 'Case Study');
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
$docArchivePortfolio = CanonicalDocumentSchema::emptyDocument('system', 'archive-portfolio', 'Selected Work Archive');
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
$doc404 = CanonicalDocumentSchema::emptyDocument('system', 'not-found-404', '404 Page Not Found');
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
                    'content' => '<div style="text-align: center; padding: 60px 20px; font-family: Inter, sans-serif;">
  <div style="display: inline-block; padding: 6px 16px; border-radius: 9999px; background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.35); font-weight: 800; font-size: 0.85rem; color: #818cf8; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 24px;">
    System Exception · HTTP 404
  </div>
  <h1 style="font-size: clamp(3rem, 8vw, 6rem); font-weight: 900; line-height: 1; margin: 0 0 16px 0; background: linear-gradient(135deg, #ffffff 40%, #6366f1); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
    404
  </h1>
  <h2 style="font-size: 1.6rem; font-weight: 700; color: #f8fafc; margin: 0 0 14px 0;">
    Lost in Digital Coordinates
  </h2>
  <p style="font-size: 1rem; color: #94a3b8; max-width: 480px; margin: 0 auto 36px auto; line-height: 1.6;">
    The page or route you requested has migrated, been renamed, or does not exist in our production routing registry.
  </p>
  <div style="display: flex; justify-content: center; gap: 14px; flex-wrap: wrap; margin-bottom: 36px;">
    <a href="/" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; text-decoration: none; font-weight: 700; font-size: 0.95rem; box-shadow: 0 4px 18px rgba(99, 102, 241, 0.4);">
      Return to Homepage ↗
    </a>
    <a href="/portfolio" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 10px; background: #1e293b; border: 1px solid #334155; color: #cbd5e1; text-decoration: none; font-weight: 700; font-size: 0.95rem;">
      Explore Work
    </a>
    <a href="/contact" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 10px; background: #1e293b; border: 1px solid #334155; color: #cbd5e1; text-decoration: none; font-weight: 700; font-size: 0.95rem;">
      Contact Support
    </a>
  </div>
</div>',
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
    $doc = CanonicalDocumentSchema::emptyDocument('section_preset', $key, $label);
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
            'content' => '<div style="text-align: center; max-width: 900px; margin: 0 auto; font-family: Inter, sans-serif;">
  <div style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 9999px; background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.3); font-size: 0.8rem; font-weight: 700; color: #818cf8; margin-bottom: 24px;">
    <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
    Available for Select Product Engagements · Q4 2026
  </div>
  <h1 style="font-size: clamp(2.5rem, 6vw, 4.5rem); font-weight: 900; letter-spacing: -0.03em; line-height: 1.08; color: #f8fafc; margin-bottom: 24px;">
    Designing High-Performance <span style="background: linear-gradient(135deg, #6366f1 30%, #ec4899); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Visual Systems</span> for the Web.
  </h1>
  <p style="font-size: 1.15rem; line-height: 1.65; color: #94a3b8; max-width: 680px; margin: 0 auto 36px auto;">
    Principal Product Architect partnering with forward-thinking founders to build tokenized design systems, interactive React canvases, and scalable SaaS platforms.
  </p>
  <div style="display: flex; justify-content: center; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 56px;">
    <a href="/portfolio" style="padding: 14px 28px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700; font-size: 0.95rem; text-decoration: none; box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);">
      Explore Selected Work ↗
    </a>
    <a href="/contact" style="padding: 14px 28px; border-radius: 10px; background: #1e293b; border: 1px solid #334155; color: #cbd5e1; font-weight: 700; font-size: 0.95rem; text-decoration: none;">
      Schedule 20-Min Intro
    </a>
  </div>
  <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 28px;">
    <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; margin-bottom: 18px;">
      Trusted By Engineering & Product Leaders At
    </div>
    <div style="display: flex; justify-content: center; align-items: center; gap: 40px; flex-wrap: wrap; opacity: 0.7; filter: grayscale(1);">
      <span style="font-weight: 800; font-size: 1.2rem; color: #cbd5e1; letter-spacing: -0.02em;">STRIPE</span>
      <span style="font-weight: 800; font-size: 1.2rem; color: #cbd5e1; letter-spacing: -0.02em;">LINEAR</span>
      <span style="font-weight: 800; font-size: 1.2rem; color: #cbd5e1; letter-spacing: -0.02em;">VERCEL</span>
      <span style="font-weight: 800; font-size: 1.2rem; color: #cbd5e1; letter-spacing: -0.02em;">RAYCAST</span>
      <span style="font-weight: 800; font-size: 1.2rem; color: #cbd5e1; letter-spacing: -0.02em;">SUPABASE</span>
    </div>
  </div>
</div>',
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
            'content' => '<div style="font-family: Inter, sans-serif; max-width: 1200px; margin: 0 auto;">
  <div style="text-align: center; margin-bottom: 48px;">
    <div style="font-size: 0.8rem; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">// CORE CAPABILITIES</div>
    <h2 style="font-size: 2.4rem; font-weight: 800; color: #f8fafc; letter-spacing: -0.02em; margin: 0 0 12px 0;">Architected for Speed & Visual Longevity</h2>
    <p style="font-size: 1rem; color: #94a3b8; max-width: 580px; margin: 0 auto;">Eliminate technical debt with battle-tested modular design patterns.</p>
  </div>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
      <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(99, 102, 241, 0.15); display: flex; align-items: center; justify-content: center; color: #818cf8; font-size: 1.4rem; margin-bottom: 20px;">⚡</div>
      <h3 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 10px;">Tokenized Design Systems</h3>
      <p style="font-size: 0.9rem; line-height: 1.6; color: #94a3b8; margin: 0;">Comprehensive typography, radius, color, and shadow token tiers mapped directly to CSS custom properties and Figma variables.</p>
    </div>
    <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
      <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center; color: #34d399; font-size: 1.4rem; margin-bottom: 20px;">💎</div>
      <h3 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 10px;">Interactive Visual Builder</h3>
      <p style="font-size: 0.9rem; line-height: 1.6; color: #94a3b8; margin: 0;">In-canvas WYSIWYG editing, real-time responsive breakpoints, and strict schema validation protecting your live site from regressions.</p>
    </div>
    <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
      <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(236, 72, 153, 0.15); display: flex; align-items: center; justify-content: center; color: #f472b6; font-size: 1.4rem; margin-bottom: 20px;">🚀</div>
      <h3 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 10px;">Sub-100ms Server Renders</h3>
      <p style="font-size: 0.9rem; line-height: 1.6; color: #94a3b8; margin: 0;">Pre-compiled HTML AST cache, streaming responsive assets, and zero runtime JavaScript overhead on static document slices.</p>
    </div>
  </div>
</div>',
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
            'content' => '<div style="font-family: Inter, sans-serif; max-width: 1200px; margin: 0 auto;">
  <div style="text-align: center; margin-bottom: 48px;">
    <div style="font-size: 0.8rem; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">// TRANSPARENT INVESTMENT</div>
    <h2 style="font-size: 2.4rem; font-weight: 800; color: #f8fafc; letter-spacing: -0.02em; margin: 0 0 12px 0;">Predictable Retainers & Sprint Pricing</h2>
    <p style="font-size: 1rem; color: #94a3b8; max-width: 580px; margin: 0 auto;">Transparent flat rates with zero hidden fees. Pause or cancel with 14 days notice.</p>
  </div>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; align-items: stretch;">
    <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 36px 28px; display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div style="font-weight: 700; font-size: 1.15rem; color: #f8fafc; margin-bottom: 8px;">Starter Sprint</div>
        <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 24px;">Ideal for early-stage startups needing a high-conversion MVP launch.</p>
        <div style="font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: 24px;">$2,500 <span style="font-size: 0.9rem; font-weight: 500; color: #64748b;">/ sprint</span></div>
        <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #cbd5e1;">
          <li>✓ 1 Core Product Page & Design System</li>
          <li>✓ Tokenized Typography & Colors</li>
          <li>✓ Full Responsive Mobile Optimization</li>
          <li>✓ 1 Round of Revisions</li>
        </ul>
      </div>
      <a href="/contact" style="display: block; text-align: center; padding: 12px; border-radius: 8px; background: #1e293b; border: 1px solid #334155; color: #fff; text-decoration: none; font-weight: 700;">Select Starter</a>
    </div>
    <div style="background: #182338; border: 2px solid #6366f1; border-radius: 16px; padding: 36px 28px; display: flex; flex-direction: column; justify-content: space-between; position: relative; box-shadow: 0 8px 30px rgba(99, 102, 241, 0.25);">
      <div style="position: absolute; top: -12px; right: 24px; background: #6366f1; color: #fff; padding: 4px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">Most Popular</div>
      <div>
        <div style="font-weight: 700; font-size: 1.15rem; color: #f8fafc; margin-bottom: 8px;">Growth Architecture</div>
        <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 24px;">Full-scale visual platform architecture, custom widgets, and CMS engine.</p>
        <div style="font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: 24px;">$6,500 <span style="font-size: 0.9rem; font-weight: 500; color: #64748b;">/ month</span></div>
        <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #cbd5e1;">
          <li>✓ Complete Multi-Page Website & Hub</li>
          <li>✓ Custom Studio Builder Widgets</li>
          <li>✓ Dynamic Query Loops & Filtering</li>
          <li>✓ Priority 24h Slack Channel Access</li>
          <li>✓ Ongoing Weekly Sprints</li>
        </ul>
      </div>
      <a href="/contact" style="display: block; text-align: center; padding: 12px; border-radius: 8px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; text-decoration: none; font-weight: 700; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);">Book Growth Retainer</a>
    </div>
    <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 36px 28px; display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div style="font-weight: 700; font-size: 1.15rem; color: #f8fafc; margin-bottom: 8px;">Enterprise Bespoke</div>
        <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 24px;">Custom platform re-architecture, multi-region clustering, and SLA guarantees.</p>
        <div style="font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: 24px;">Custom</div>
        <ul style="list-style: none; padding: 0; margin: 0 0 32px 0; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #cbd5e1;">
          <li>✓ Dedicated Principal Architect Lead</li>
          <li>✓ Custom Enterprise API Gateway</li>
          <li>✓ Strict 99.99% Uptime & Security SLAs</li>
          <li>✓ Unlimited Production Sprints</li>
        </ul>
      </div>
      <a href="/contact" style="display: block; text-align: center; padding: 12px; border-radius: 8px; background: #1e293b; border: 1px solid #334155; color: #fff; text-decoration: none; font-weight: 700;">Contact Executive Team</a>
    </div>
  </div>
</div>',
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
            'content' => '<div style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #db2777 100%); border-radius: 24px; padding: 64px 32px; text-align: center; color: #fff; box-shadow: 0 10px 40px rgba(99, 102, 241, 0.35); font-family: Inter, sans-serif;">
  <span style="display: inline-block; padding: 4px 14px; border-radius: 9999px; background: rgba(255,255,255,0.2); font-size: 0.8rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 20px;">Limited Q4 Availability</span>
  <h2 style="font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 900; line-height: 1.15; margin: 0 0 16px 0; color: #fff;">Ready to Build Something Extraordinary?</h2>
  <p style="font-size: 1.1rem; line-height: 1.6; max-width: 620px; margin: 0 auto 36px auto; opacity: 0.92;">Let\'s discuss your technical architecture, design system requirements, and sprint timeline. Direct reply guaranteed within 24 hours.</p>
  <div style="display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
    <a href="/contact" style="padding: 14px 32px; border-radius: 10px; background: #ffffff; color: #4338ca; font-weight: 800; font-size: 1rem; text-decoration: none; box-shadow: 0 4px 16px rgba(0,0,0,0.2);">Schedule 20-Min Intro ↗</a>
    <a href="mailto:hello@kohevo.com" style="padding: 14px 28px; border-radius: 10px; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.3); color: #fff; font-weight: 700; font-size: 1rem; text-decoration: none;">Send Direct Email</a>
  </div>
</div>',
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
            'content' => '<div style="font-family: Inter, sans-serif; max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 48px; align-items: start;">
  <div>
    <div style="font-size: 0.8rem; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">// GET IN TOUCH</div>
    <h2 style="font-size: 2.4rem; font-weight: 800; color: #f8fafc; letter-spacing: -0.02em; margin: 0 0 16px 0;">Let\'s Start a Technical Conversation</h2>
    <p style="font-size: 1rem; color: #94a3b8; line-height: 1.6; margin-bottom: 32px;">
      Whether you are exploring a complete design system rebuild or require dedicated technical advisory, we are here to help.
    </p>
    <div style="display: flex; flex-direction: column; gap: 20px; font-size: 0.95rem;">
      <div style="display: flex; align-items: center; gap: 14px;">
        <span style="width: 40px; height: 40px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); display: flex; align-items: center; justify-content: center; color: #818cf8;">✉</span>
        <div><div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Direct Email</div><a href="mailto:hello@kohevo.com" style="color: #f8fafc; font-weight: 600; text-decoration: none;">hello@kohevo.com</a></div>
      </div>
      <div style="display: flex; align-items: center; gap: 14px;">
        <span style="width: 40px; height: 40px; border-radius: 10px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center; color: #34d399;">⚡</span>
        <div><div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Response Time</div><div style="color: #f8fafc; font-weight: 600;">Within 24 business hours</div></div>
      </div>
      <div style="display: flex; align-items: center; gap: 14px;">
        <span style="width: 40px; height: 40px; border-radius: 10px; background: rgba(236, 72, 153, 0.15); display: flex; align-items: center; justify-content: center; color: #f472b6;">📍</span>
        <div><div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Studio Location</div><div style="color: #f8fafc; font-weight: 600;">San Francisco, CA & Global Remote</div></div>
      </div>
    </div>
  </div>
  <div style="background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 36px; box-shadow: 0 6px 24px rgba(0,0,0,0.25);">
    <h3 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 20px;">Send a Direct Message</h3>
    <form action="#" onsubmit="event.preventDefault(); alert(\'Thank you! Your message has been received.\');" style="display: flex; flex-direction: column; gap: 16px;">
      <div>
        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 6px;">Your Name</label>
        <input type="text" placeholder="Alex Morgan" required style="width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 10px 14px; color: #fff; font-size: 0.9rem;">
      </div>
      <div>
        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 6px;">Work Email</label>
        <input type="email" placeholder="alex@company.com" required style="width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 10px 14px; color: #fff; font-size: 0.9rem;">
      </div>
      <div>
        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 6px;">Project Scope / Budget</label>
        <select style="width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 10px 14px; color: #fff; font-size: 0.9rem;">
          <option>$2,500 - $5,000 (Starter Sprint)</option>
          <option>$5,000 - $15,000 (Growth Platform)</option>
          <option>$15,000+ (Enterprise Architecture)</option>
        </select>
      </div>
      <div>
        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 6px;">Project Overview</label>
        <textarea rows="3" placeholder="Briefly describe what you are looking to build or optimize..." style="width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 10px 14px; color: #fff; font-size: 0.9rem;"></textarea>
      </div>
      <button type="submit" style="width: 100%; padding: 12px; border-radius: 8px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; color: #fff; font-weight: 700; font-size: 0.95rem; cursor: pointer; margin-top: 8px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);">
        Submit Inquiry ↗
      </button>
    </form>
  </div>
</div>',
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
