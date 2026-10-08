<?php
/**
 * Kohevo Studio — Complete Rakib Hasan Portfolio Website Seeder.
 *
 * Implements the exact pixel-perfect portfolio design requested by the user:
 * - Dynamic data: Profile, coordinates, projects, services, reviews, bio, skills
 * - Fixed chrome header with brand and animated 3-bar hamburger icon
 * - Fullscreen sliding menu drawer with staggered animated links & socials
 * - Dynamic filter chips (All, Software, WordPress) & project card grid
 * - Interactive dynamic project detail view with overview, approach, outcome
 * - Services with live Fiverr rating (5.0 · 101 reviews, Level 2 seller)
 * - 4-step "How we work" process and client testimonials
 * - About section with photo, bio, remote experience, and skills
 * - Interactive contact form with validation and direct email link
 * - Client hash router (#/, #/work, #/work/:id, #/services, #/about, #/contact)
 *
 * Usage:
 *   php bin/seed-rakib-portfolio.php [--wipe-current] [--tenant=ID]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php bin/seed-rakib-portfolio.php\n");
    exit(1);
}

define('SLATE_ALLOW_LIVE_DB', 1);
require_once __DIR__ . '/../config.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Render\Compile\CompiledPage;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

$tenantId = (int) (defined('TENANT_ID') ? TENANT_ID : 1);
$wipe = in_array('--wipe-current', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}

echo "=========================================================\n";
echo "  Rakib Hasan — Full-Stack Portfolio Seeder\n";
echo "  Tenant ID: {$tenantId}\n";
echo "  Mode: " . ($wipe ? "WIPE & REBUILD" : "UPDATE/CREATE") . "\n";
echo "=========================================================\n\n";

// 1. Boot StudioBuilder plugin so custom widgets are registered
$builderPlugin = new StudioBuilder('studio-builder', ['version' => '1.0.0'], dirname(__DIR__) . '/plugins/studio-builder');
$builderPlugin->boot();

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

// ── Step 1: Wipe current pages if requested ──────────────────────────────
if ($wipe) {
    echo "1. Deleting previous StudioBuilder pages & revisions...\n";
    $pageCount = Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_compilations WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_dependencies WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_revisions WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_templates WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_locks WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_pages WHERE tenant_id = ?", [$tenantId]);
    echo "   ✓ Successfully deleted {$pageCount} existing pages and associated data.\n\n";
}

// ── Step 2: Load Assets ──────────────────────────────────────────────────
$bodyFile = dirname(__DIR__) . '/plugins/studio-builder/assets/portfolio_body.html';
$cssFile  = dirname(__DIR__) . '/plugins/studio-builder/assets/portfolio_style.css';

if (!file_exists($bodyFile) || !file_exists($cssFile)) {
    fwrite(STDERR, "Error: Required asset files not found in plugins/studio-builder/assets/\n");
    exit(1);
}

$rawHtml = (string) file_get_contents($bodyFile);
$fullCss = (string) file_get_contents($cssFile);

// Build safe CSS without base64 font data for tenant setting (under 64KB)
// Strip the large base64 @font-face rules (fonts are loaded via portfolio.css)
$cleanCssWithoutFonts = trim((string) preg_replace('/@font-face\s*\{[^}]*src:\s*url\(data:font\/woff2;base64,[^}]*\}/s', '', $fullCss));
$safeCss = $cleanCssWithoutFonts;

echo "2. Saving tenant custom CSS setting...\n";
Database::query(
    "INSERT INTO settings (tenant_id, setting_key, setting_value, updated_at) "
    . "VALUES (?, ?, ?, NOW()) "
    . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
    [$tenantId, StudioCodePolicy::SETTING_CUSTOM_CSS, $safeCss]
);
echo "   ✓ Saved custom CSS setting (" . strlen($safeCss) . " bytes)\n\n";

// ── Step 3: Canonical Document Helpers & Builders ────────────────────────
$makeBlock = function(string $type, array $props, array $style = []): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => $type,
        'version'    => 1,
        'props'      => $props,
        'style'      => array_merge(CanonicalDocumentSchema::defaultBlockStyle(), $style),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

$makeSection = function(string $label, array $blocks, string $bgToken = 'surface.primary', string $width = 'normal'): array {
    return [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => [
            'background_token' => $bgToken,
            'columns'          => ['base' => 1, 'md' => 1],
            'gap'              => 'md',
            'padding_y'        => ['base' => 'lg', 'md' => 'xl'],
            'width'            => $width,
        ],
        'visibility' => ['auth_state' => 'any', 'devices' => CanonicalDocumentSchema::ALLOWED_BREAKPOINTS],
        'blocks'     => $blocks,
    ];
};

// 1. Home Document (Full-screen interactive portfolio showcase)
$buildHomeDocument = function(string $pageTitle, string $seoDesc): array {
    return [
        'document_type'  => 'page',
        'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'template_key'   => 'default',
        'settings'       => [
            'container_width' => 'full',
            'header_mode'     => 'hidden',
            'footer_mode'     => 'hidden',
            'token_group'     => 'default',
        ],
        'seo'            => [
            'title'             => $pageTitle,
            'description'       => $seoDesc,
            'canonical_url'     => 'https://rakibhasaan.com/solaya/',
            'robots'            => 'index,follow',
            'og_image_media_id' => null,
        ],
        'sections'       => [
            [
                'id'         => CanonicalDocumentSchema::newSectionId(),
                'label'      => 'Rakib Hasan Portfolio Experience',
                'global_ref' => null,
                'layout'     => [
                    'background_token' => 'surface.primary',
                    'columns'          => ['base' => 1, 'md' => 12],
                    'gap'              => 'none',
                    'padding_y'        => ['base' => 'none', 'md' => 'none'],
                    'width'            => 'full',
                ],
                'visibility' => ['auth_state' => 'any', 'devices' => CanonicalDocumentSchema::ALLOWED_BREAKPOINTS],
                'blocks'     => [
                    [
                        'id'         => CanonicalDocumentSchema::newBlockId(),
                        'type'       => 'portfolio.rakib_showcase',
                        'version'    => 1,
                        'props'      => [
                            'title'         => $pageTitle,
                            'brand_text'    => 'Portfolio / 2026',
                            'first_name'    => 'rakib',
                            'last_name'     => 'hasan',
                            'role'          => "Freelance developer\n& designer",
                            'vertical_text' => 'Digital design • Development',
                            'location'      => "Based in\nBangladesh",
                            'coordinates'   => "23.8103° N\n90.4125° E",
                            'domain'        => 'rakibhasaan.com',
                            'domain_url'    => 'https://rakibhasaan.com',
                            'email'         => 'hello@rakibhasaan.com',
                        ],
                        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                        'bindings'   => [],
                        'children'   => [],
                    ],
                ],
            ],
        ],
    ];
};

// 2. Work Document (Native editable sections & blocks: projects, case studies, links)
$buildWorkDocument = function() use ($makeSection, $makeBlock): array {
    return [
        'document_type'  => 'page',
        'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'template_key'   => 'default',
        'settings'       => [
            'container_width' => 'normal',
            'header_mode'     => 'inherit',
            'footer_mode'     => 'inherit',
            'token_group'     => 'default',
        ],
        'seo'            => [
            'title'             => 'Projects | Rakib Hasan – Software, WordPress & Web',
            'description'       => 'Software, WordPress and web projects by Rakib Hasan, from licensed platforms to business websites.',
            'canonical_url'     => null,
            'robots'            => 'index,follow',
            'og_image_media_id' => null,
        ],
        'sections'       => [
            $makeSection('Projects', [
                $makeBlock('core.heading', ['level' => 'h1', 'text' => 'Projects']),
                $makeBlock('core.rich_text', ['content' => '<p>Software, WordPress and web projects, from licensed platforms to business websites.</p>']),
                $makeBlock('core.feature_list', [
                    'title'        => '',
                    'columns'      => 2,
                    'card_options' => ['bordered' => true, 'surface_token' => null],
                    'items'        => [
                        [
                            'heading' => 'Kohevo',
                            'body'    => 'A white-label practice-management and studio platform rebuilt with modular licensing, REST APIs, and modern responsive design.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'Construction company website',
                            'body'    => 'A high-converting contractor website with quote estimation forms, portfolio gallery, and service area targeting.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'E-commerce WordPress store',
                            'body'    => 'Full-featured online store with payment gateways, dynamic cart, inventory tracking, and speed optimization.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'Restaurant website with online ordering',
                            'body'    => 'Digital menu, table reservation system, and direct pickup/delivery ordering interface.',
                            'url'     => null,
                        ],
                    ],
                ]),
            ], 'surface.primary'),
            $makeSection('Kohevo', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Kohevo']),
                $makeBlock('core.rich_text', [
                    'content' => '<p><strong>Year</strong> Ongoing · <strong>Role</strong> Full-stack development · <strong>Stack</strong> PHP, MySQL, JavaScript, REST APIs, Tailwind</p><p>Kohevo is a modular multi-tenant web application platform. I re-architected core modules, built a robust licensing system, designed intuitive admin dashboards, and modernized the user interface for high performance and clean aesthetics.</p>',
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => 'https://github.com/rakib6564/Kohevo-Licensing-Rebuild',
                        'label'  => 'View on GitHub',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
            ], 'surface.secondary'),
            $makeSection('Construction company website', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Construction company website']),
                $makeBlock('core.rich_text', [
                    'content' => '<p><strong>Year</strong> WordPress · <strong>Role</strong> Design & development · <strong>Stack</strong> Elementor, ACF, Custom CSS, Responsive Layout</p><p>Built for a general contractor, featuring project galleries, service request forms, client testimonials, and Google Maps integration.</p>',
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => 'https://www.fiverr.com/rakib_64/construction-website-contractor-real-estate-company-commercial-roofing-builder-web',
                        'label'  => 'View gig on Fiverr',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
            ], 'surface.secondary'),
            $makeSection('E-commerce WordPress store', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'E-commerce WordPress store']),
                $makeBlock('core.rich_text', [
                    'content' => '<p><strong>Year</strong> WordPress · <strong>Role</strong> Design & development · <strong>Stack</strong> WooCommerce, Stripe, PayPal, Custom CSS</p><p>High-converting store designed for clean navigation, instant search, fast checkout, and optimized mobile experience.</p>',
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => 'https://www.fiverr.com/rakib_64/design-a-professional-ecommerce-and-wordpress-website-or-redesign-online-store',
                        'label'  => 'View gig on Fiverr',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
            ], 'surface.secondary'),
            $makeSection('Restaurant website with online ordering', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Restaurant website with online ordering']),
                $makeBlock('core.rich_text', [
                    'content' => '<p><strong>Year</strong> WordPress · <strong>Role</strong> Design & development · <strong>Stack</strong> WordPress, GloriaFood / Custom Menu, Responsive UI</p><p>Online food ordering system with category filtering, opening hours, reservation modal, and responsive mobile ordering.</p>',
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => 'https://www.fiverr.com/rakib_64/build-restaurant-website-online-food-ordering-reservations-coffee-shop-website',
                        'label'  => 'View gig on Fiverr',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
            ], 'surface.secondary'),
        ],
    ];
};

// 3. Services Document (Native editable sections & blocks: offerings, process, testimonials)
$buildServicesDocument = function() use ($makeSection, $makeBlock): array {
    return [
        'document_type'  => 'page',
        'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'template_key'   => 'default',
        'settings'       => [
            'container_width' => 'normal',
            'header_mode'     => 'inherit',
            'footer_mode'     => 'inherit',
            'token_group'     => 'default',
        ],
        'seo'            => [
            'title'             => 'Services | Rakib Hasan – Custom Software, CRM & WordPress',
            'description'       => 'Custom software, CRM systems, booking engines, WordPress & WooCommerce development by Rakib Hasan (5.0 rating on Fiverr).',
            'canonical_url'     => null,
            'robots'            => 'index,follow',
            'og_image_media_id' => null,
        ],
        'sections'       => [
            $makeSection('Services', [
                $makeBlock('core.heading', ['level' => 'h1', 'text' => 'What I build']),
                $makeBlock('core.rich_text', [
                    'content' => '<p><strong>Fiverr rating</strong> 5.0 · 101 reviews<br><strong>Seller level</strong> Level 2<br><strong>Avg. response</strong> About 1 hour<br><strong>Languages</strong> English, Bengali, Hindi</p>',
                ]),
                $makeBlock('core.feature_list', [
                    'title'        => '',
                    'columns'      => 2,
                    'card_options' => ['bordered' => true, 'surface_token' => null],
                    'items'        => [
                        [
                            'heading' => 'Custom software & web applications',
                            'body'    => 'Business software, CRM systems, licensing systems, multi-step booking engines, and custom client portals with PHP, MySQL, and vanilla JavaScript.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'WordPress & WooCommerce development',
                            'body'    => 'Custom themes, plugin customizations, e-commerce storefronts, restaurant ordering systems, and high-converting landing pages.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'Frontend design & UI implementation',
                            'body'    => 'Modern, responsive layouts translated from Figma or custom designs into clean, semantic HTML, CSS, and modern JavaScript.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'API integrations & back-end tools',
                            'body'    => 'Payment gateways (Stripe, PayPal), CRM webhooks, third-party REST APIs, automated notifications, and database migrations.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'Website speed optimization & SEO',
                            'body'    => 'Core Web Vitals tuning, caching strategies, asset minification, image compression, structured data, and on-page SEO best practices.',
                            'url'     => null,
                        ],
                        [
                            'heading' => 'Maintenance & technical support',
                            'body'    => 'Bug fixes, security hardening, plugin updates, server migrations (cPanel, VPS), and ongoing technical consultation.',
                            'url'     => null,
                        ],
                    ],
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'outline',
                    'link'       => [
                        'href'   => 'https://www.fiverr.com/rakib_64',
                        'label'  => 'See my gigs on Fiverr',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
            ], 'surface.primary'),
            $makeSection('How we work', [
                $makeBlock('core.feature_list', [
                    'title'        => 'How we work',
                    'columns'      => 4,
                    'card_options' => ['bordered' => true, 'surface_token' => null],
                    'items'        => [
                        [
                            'heading' => '01 Discover',
                            'body'    => 'We agree goals, users and scope. You receive a clear outline and quote before work begins.',
                            'url'     => null,
                        ],
                        [
                            'heading' => '02 Design',
                            'body'    => 'Concepts and wireframes in Figma or browser with clean, intentional layout.',
                            'url'     => null,
                        ],
                        [
                            'heading' => '03 Build',
                            'body'    => 'Clean, performant code with regular milestone updates and responsive testing.',
                            'url'     => null,
                        ],
                        [
                            'heading' => '04 Launch',
                            'body'    => 'Testing, deployment, speed optimization and ongoing support.',
                            'url'     => null,
                        ],
                    ],
                ]),
            ], 'surface.secondary'),
            $makeSection('What clients say', [
                $makeBlock('core.feature_list', [
                    'title'        => 'What clients say',
                    'columns'      => 3,
                    'card_options' => ['bordered' => true, 'surface_token' => null],
                    'items'        => [
                        [
                            'heading' => '“Great work and support!”',
                            'body'    => 'Germany · Fiverr — Rakib delivered the project ahead of schedule and handled all revisions promptly. High code quality and great communication.',
                            'url'     => null,
                        ],
                        [
                            'heading' => '“Professional and fast”',
                            'body'    => 'United States · Fiverr — Excellent communication and fast turnaround. Understood our requirements immediately and built an elegant solution.',
                            'url'     => null,
                        ],
                        [
                            'heading' => '“Highly recommended”',
                            'body'    => 'United Kingdom · Fiverr — Exceptional attention to detail and proactive communication throughout. Will hire again.',
                            'url'     => null,
                        ],
                    ],
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'ghost',
                    'link'       => [
                        'href'   => 'https://www.fiverr.com/rakib_64',
                        'label'  => 'Read all 101 reviews',
                        'rel'    => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => '/solaya/contact',
                        'label'  => 'Start a project',
                        'rel'    => null,
                        'target' => '_self',
                    ],
                ]),
            ], 'surface.primary'),
        ],
    ];
};

// 4. About Document (Native editable sections & blocks: bio, skills, remote experience)
$buildAboutDocument = function() use ($makeSection, $makeBlock): array {
    return [
        'document_type'  => 'page',
        'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'template_key'   => 'default',
        'settings'       => [
            'container_width' => 'normal',
            'header_mode'     => 'inherit',
            'footer_mode'     => 'inherit',
            'token_group'     => 'default',
        ],
        'seo'            => [
            'title'             => 'About Rakib Hasan – Full-Stack Developer & Designer',
            'description'       => 'Background, experience and skills of Rakib Hasan. Full-stack developer working with teams in Germany and Belgium.',
            'canonical_url'     => null,
            'robots'            => 'index,follow',
            'og_image_media_id' => null,
        ],
        'sections'       => [
            $makeSection('About', [
                $makeBlock('core.heading', ['level' => 'h1', 'text' => 'Hello, I’m Rakib']),
                $makeBlock('core.rich_text', [
                    'content' => '<p>I’m a freelance full-stack developer and designer in Bangladesh. I build custom software, CRM and booking tools, and polished WordPress websites for clients and agencies worldwide.</p><p>My focus is on fast, accessible, reliable code with clean typography and intentional layout. I work directly with founders, business owners, and remote teams.</p>',
                ]),
            ], 'surface.primary'),
            $makeSection('Skills & experience', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Skills']),
                $makeBlock('core.rich_text', [
                    'content' => '<p>PHP · MySQL · WordPress · JavaScript · jQuery · HTML5 · CSS3 · Bootstrap · Stripe · Graphic design · SEO · AI tools</p>',
                ]),
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Experience']),
                $makeBlock('core.rich_text', [
                    'content' => '<p>Remote IT role · <a href="https://koyfomedia.com" target="_blank" rel="noopener noreferrer">koyfomedia.com</a> (Germany)<br>Web development · <a href="https://stelaire.be" target="_blank" rel="noopener noreferrer">stelaire.be</a> (Belgium)<br>Freelance Level 2 Seller on Fiverr with 100+ 5-star reviews</p>',
                ]),
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Languages']),
                $makeBlock('core.rich_text', [
                    'content' => '<p>English, Bengali, Hindi</p>',
                ]),
            ], 'surface.secondary'),
        ],
    ];
};

// 5. Contact Document (Native editable sections & blocks: inquiry message, email button, profiles)
$buildContactDocument = function() use ($makeSection, $makeBlock): array {
    return [
        'document_type'  => 'page',
        'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'template_key'   => 'default',
        'settings'       => [
            'container_width' => 'normal',
            'header_mode'     => 'inherit',
            'footer_mode'     => 'inherit',
            'token_group'     => 'default',
        ],
        'seo'            => [
            'title'             => 'Contact Rakib Hasan – Start a Project',
            'description'       => 'Tell me about your project: software, booking system, WordPress site or design. I usually reply within a few hours.',
            'canonical_url'     => null,
            'robots'            => 'index,follow',
            'og_image_media_id' => null,
        ],
        'sections'       => [
            $makeSection('Contact', [
                $makeBlock('core.heading', ['level' => 'h1', 'text' => 'Let’s work together']),
                $makeBlock('core.rich_text', [
                    'content' => '<p>Tell me about your project: software, booking system, WordPress site or design. I usually reply within a few hours.</p>',
                ]),
                $makeBlock('core.button', [
                    'full_width' => false,
                    'variant'    => 'primary',
                    'link'       => [
                        'href'   => 'mailto:hello@rakibhasaan.com',
                        'label'  => 'Email hello@rakibhasaan.com',
                        'rel'    => null,
                        'target' => '_self',
                    ],
                ]),
            ], 'surface.primary'),
            $makeSection('Details', [
                $makeBlock('core.heading', ['level' => 'h2', 'text' => 'Contact details']),
                $makeBlock('core.rich_text', [
                    'content' => '<h3>Write to me</h3><p><a href="mailto:hello@rakibhasaan.com">hello@rakibhasaan.com</a></p><h3>Location</h3><p>Bangladesh (UTC+6) · Available for remote work worldwide</p><h3>Profiles</h3><p><a href="https://www.fiverr.com/rakib_64" target="_blank" rel="noopener noreferrer">Fiverr (Level 2 Seller)</a> · <a href="https://github.com/rakib6564" target="_blank" rel="noopener noreferrer">GitHub</a> · <a href="https://linkedin.com/in/rakibhasan" target="_blank" rel="noopener noreferrer">LinkedIn</a></p>',
                ]),
            ], 'surface.secondary'),
        ],
    ];
};

// ── Step 4: Page Publishing Function ─────────────────────────────────────
$createAndPublishPortfolioPage = function(
    string $slug,
    string $title,
    string $routeMode,
    string $seoDesc,
    array $document,
    ?string $customHtml = null,
    ?string $customCss = null
) use ($tenantId, $userId, $runtime, $genUuid): int {
    $existing = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = 'page'", [$tenantId, $slug]);
    
    $docJson = CanonicalJson::encode($document);
    $seoJson = json_encode(['title' => $title, 'description' => $seoDesc]);
    $docSettings = $document['settings'] ?? [
        'container_width' => 'normal',
        'header_mode'     => 'inherit',
        'footer_mode'     => 'inherit',
        'token_group'     => 'default',
    ];

    if ($existing !== null) {
        $pageId = (int) $existing['id'];
        Database::query("UPDATE studiobuilder_pages SET title = ?, route_mode = ?, seo_json = ?, settings_json = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?", [
            $title, $routeMode, $seoJson, CanonicalJson::encode($docSettings), $pageId, $tenantId
        ]);
        $revNumber = (int) (Database::value("SELECT MAX(revision_number) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?", [$tenantId, $pageId]) ?: 0) + 1;
    } else {
        $pageUuid = $genUuid();
        $pageId = Database::insert('studiobuilder_pages', [
            'tenant_id'                => $tenantId,
            'uuid'                     => $pageUuid,
            'slug'                     => $slug,
            'title'                    => $title,
            'page_type'                => 'page',
            'route_mode'               => $routeMode,
            'status'                   => 'draft',
            'active_draft_revision_id' => null,
            'published_revision_id'    => null,
            'seo_json'                 => $seoJson,
            'settings_json'            => CanonicalJson::encode($docSettings),
            'created_by'               => $userId,
            'updated_by'               => $userId,
            'created_at'               => date('Y-m-d H:i:s'),
            'updated_at'               => date('Y-m-d H:i:s'),
        ]);
        $revNumber = 1;
    }

    $revId = Database::insert('studiobuilder_revisions', [
        'tenant_id'          => $tenantId,
        'page_id'            => $pageId,
        'revision_number'    => $revNumber,
        'revision_kind'      => 'manual',
        'document_json'      => $docJson,
        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
        'summary'            => "Published portfolio page: {$title}",
        'parent_revision_id' => null,
        'created_by'         => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
    ]);

    Database::query("UPDATE studiobuilder_pages SET active_draft_revision_id = ?, status = 'published', published_revision_id = ?, published_at = NOW() WHERE id = ? AND tenant_id = ?", [
        $revId, $revId, $pageId, $tenantId
    ]);

    // Compile Document
    $pageRow = Database::row("SELECT * FROM studiobuilder_pages WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
    $revRow  = Database::row("SELECT * FROM studiobuilder_revisions WHERE id = ? AND tenant_id = ?", [$revId, $tenantId]);
    $pageAddress = PageAddress::fromRow($pageRow);
    $context = RenderContext::forPublic($tenantId, $runtime->render->siteContext());

    try {
        $compiled = $runtime->compiler->compile($pageAddress, $revRow, $context);
        
        $finalHtml = $customHtml ?? $compiled->html;
        $finalCss  = $customCss ?? $compiled->css;
        
        $compilationRow = [
            'tenant_id'             => $tenantId,
            'page_id'               => $pageId,
            'revision_id'           => $revId,
            'compile_mode'          => StudioCompiler::MODE_PUBLISHED,
            'compiled_html'         => $finalHtml,
            'compiled_css'          => $finalCss,
            'dynamic_manifest_json' => json_encode($compiled->dynamicManifest, JSON_UNESCAPED_SLASHES),
            'head_assets_json'      => json_encode($compiled->headAssets, JSON_UNESCAPED_SLASHES),
            'content_hash'          => $compiled->contentHash,
            'compiler_version'      => StudioCompiler::COMPILER_VERSION,
            'compiled_at'           => date('Y-m-d H:i:s'),
        ];

        Database::query("DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ? AND compile_mode = ?", [
            $tenantId, $pageId, StudioCompiler::MODE_PUBLISHED
        ]);
        Database::insert('studiobuilder_compilations', $compilationRow);

        echo "  ✓ Compiled & stored published artifact (" . strlen($finalHtml) . " B HTML, " . strlen($finalCss) . " B CSS)\n";
    } catch (\Throwable $e) {
        echo "  [warn] Compilation issue on '{$title}': " . $e->getMessage() . "\n";
    }

    echo "  ✓ Published: '{$title}' (ID: {$pageId}, Slug: /{$slug}, Route: {$routeMode})\n";
    return $pageId;
};

// ── Step 5: Publish Homepage and Standalone Routes ───────────────────────
echo "3. Publishing Homepage & Standalone Navigation Routes...\n";

// 1. Homepage (/)
$homeId = $createAndPublishPortfolioPage(
    'home',
    'Rakib Hasan | Full-Stack Developer & Designer, Bangladesh',
    'homepage',
    'Freelance full-stack developer and designer in Bangladesh. Custom software, CRM, restaurant booking, e-signature forms, WordPress plugins and AI tools.',
    $buildHomeDocument('Rakib Hasan | Full-Stack Developer & Designer, Bangladesh', 'Freelance full-stack developer and designer in Bangladesh. Custom software, CRM, restaurant booking, e-signature forms, WordPress plugins and AI tools.'),
    '<main class="sb-main sb-main--full" id="main">' . $rawHtml . '</main>',
    $fullCss
);

// 2. Work (/work)
$workId = $createAndPublishPortfolioPage(
    'work',
    'Projects | Rakib Hasan – Software, WordPress & Web',
    'standalone',
    'Software, WordPress and web projects by Rakib Hasan, from licensed platforms to business websites.',
    $buildWorkDocument()
);

// 3. Services (/services)
$servicesId = $createAndPublishPortfolioPage(
    'services',
    'Services | Rakib Hasan – Custom Software, CRM & WordPress',
    'standalone',
    'Custom software, CRM systems, booking engines, WordPress & WooCommerce development by Rakib Hasan (5.0 rating on Fiverr).',
    $buildServicesDocument()
);

// 4. About (/about)
$aboutId = $createAndPublishPortfolioPage(
    'about',
    'About Rakib Hasan – Full-Stack Developer & Designer',
    'standalone',
    'Background, experience and skills of Rakib Hasan. Full-stack developer working with teams in Germany and Belgium.',
    $buildAboutDocument()
);

// 5. Contact (/contact)
$contactId = $createAndPublishPortfolioPage(
    'contact',
    'Contact Rakib Hasan – Start a Project',
    'standalone',
    'Start a project with Rakib Hasan: software, booking systems, WordPress sites or development support.',
    $buildContactDocument()
);

echo "\n=========================================================\n";
echo "  ✓ Portfolio Website successfully seeded!\n";
echo "=========================================================\n\n";

// ── Step 6: Verify Public Homepage Render ────────────────────────────────
echo "4. Verifying Public Runtime Rendering...\n";
$rt = StudioRuntimeFactory::build();
$row = $rt->tenants->runAs($tenantId, fn() => $rt->pages->findPublishedHomepage(['page', 'landing']));
if ($row !== null) {
    $addr = PageAddress::fromRow($row);
    $context = RenderContext::forPublic($tenantId, $rt->render->siteContext(), fn() => true);
    $res = $rt->render->renderPublished($addr, $context);
    if ($res !== null) {
        echo "   ✓ Homepage render status: SUCCESS (" . strlen($res->html) . " bytes)\n";
        if (str_contains($res->html, 'rakib') && str_contains($res->html, 'hasan')) {
            echo "   ✓ Successfully detected 'rakib hasan' in rendered DOM!\n";
        }
    } else {
        echo "   ✗ Homepage renderPublished returned null!\n";
    }
} else {
    echo "   ✗ findPublishedHomepage returned null!\n";
}

