<?php
/**
 * Kohevo Studio — Complete Construction Company Website Seeder.
 *
 * Brand: Vanguard Construction Group
 * Specialization: Commercial High-Rise, Custom Residential, Industrial Hubs, Civil & Infrastructure
 *
 * Uses Canonical Studio Builder document schema v1.0, clean validated semantic blocks:
 *   - core.rich_text (clean semantic HTML)
 *   - core.stats (stat counters)
 *   - core.accordion (interactive FAQ & scope breakdowns)
 *   - core.carousel (client testimonials)
 *   - core.tabs (project sectors & delivery frameworks)
 *
 * Usage:
 *   php bin/seed-construction-company-site.php [--wipe-current] [--tenant=ID]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php bin/seed-construction-company-site.php\n");
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

$tenantId = (int) (defined('TENANT_ID') ? TENANT_ID : 1);
$wipe = in_array('--wipe-current', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}

echo "=========================================================\n";
echo "  Vanguard Construction Group — Website Seeder\n";
echo "  Tenant ID: {$tenantId}\n";
echo "  Mode: " . ($wipe ? "WIPE & REBUILD" : "UPDATE/CREATE") . "\n";
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

// ── Step 1: Wipe current pages if requested ──────────────────────────────
if ($wipe) {
    echo "1. Deleting all existing StudioBuilder pages & revisions...\n";
    $pageCount = Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_compilations WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_dependencies WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_revisions WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_templates WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_locks WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM studiobuilder_pages WHERE tenant_id = ?", [$tenantId]);
    echo "   ✓ Successfully deleted {$pageCount} existing pages and associated data.\n\n";
}

// Helper: build, compile and publish page
$createAndPublishPage = function(
    string $slug,
    string $title,
    string $pageType,
    string $routeMode,
    array $document,
    array $seo = []
) use ($tenantId, $userId, $runtime, $actor, $genUuid): int {
    $existing = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ?", [$tenantId, $slug, $pageType]);
    
    $document['template_key'] = 'default';
    $docJson = CanonicalJson::encode($document);
    $seoJson = json_encode($seo ?: ['title' => $title, 'description' => "Vanguard Construction Group — {$title}"]);

    if ($existing !== null) {
        $pageId = (int) $existing['id'];
        Database::query("UPDATE studiobuilder_pages SET title = ?, route_mode = ?, seo_json = ?, updated_at = ? WHERE id = ? AND tenant_id = ?", [
            $title, $routeMode, $seoJson, date('Y-m-d H:i:s'), $pageId, $tenantId
        ]);
        $revNumber = (int) (Database::value("SELECT MAX(revision_number) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?", [$tenantId, $pageId]) ?: 0) + 1;
    } else {
        $pageUuid = $genUuid();
        $pageId = Database::insert('studiobuilder_pages', [
            'tenant_id'                => $tenantId,
            'uuid'                     => $pageUuid,
            'slug'                     => $slug,
            'title'                    => $title,
            'page_type'                => $pageType,
            'route_mode'               => $routeMode,
            'status'                   => 'draft',
            'active_draft_revision_id' => null,
            'published_revision_id'    => null,
            'seo_json'                 => $seoJson,
            'settings_json'            => CanonicalJson::encode(['created_via' => 'seed-construction-company']),
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
        'summary'            => "Published construction page: {$title}",
        'parent_revision_id' => null,
        'created_by'         => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
    ]);

    Database::query("UPDATE studiobuilder_pages SET active_draft_revision_id = ?, status = 'published', published_revision_id = ?, published_at = ? WHERE id = ? AND tenant_id = ?", [
        $revId, $revId, date('Y-m-d H:i:s'), $pageId, $tenantId
    ]);

    // Validate Document & Compile
    try {
        $validated = ValidatedDocument::from($document, $runtime->registry);
        
        // Compile full page/landing/system documents to studiobuilder_compilations
        if (in_array($pageType, ['page', 'landing', 'system'], true)) {
            $pageRow = Database::row("SELECT * FROM studiobuilder_pages WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
            $revRow  = Database::row("SELECT * FROM studiobuilder_revisions WHERE id = ? AND tenant_id = ?", [$revId, $tenantId]);
            $pageAddress = \Slate\Module\StudioBuilder\Domain\PageAddress::fromRow($pageRow);
            $context = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic($tenantId, $runtime->render->siteContext());
            $compiled = $runtime->compiler->compileAndStorePublished($pageAddress, $revRow, $context);
            echo "    [compile] Compiled & stored published artifact (" . strlen($compiled->html) . " B HTML, " . strlen($compiled->css) . " B CSS)\n";
        }
    } catch (\Slate\Module\StudioBuilder\Exception\StudioValidationException $e) {
        echo "  [warn] Validation errors on '{$title}':\n";
        foreach ($e->errors() as $err) {
            echo "    - {$err['path']} [{$err['code']}]: {$err['message']}\n";
        }
    } catch (\Throwable $e) {
        echo "  [warn] Compilation issue on '{$title}': " . $e->getMessage() . "\n";
    }

    echo "  ✓ Published [{$pageType}]: '{$title}' (ID: {$pageId}, Slug: /{$slug})\n";
    return $pageId;
};

// Helpers for canonical sections and blocks
$createSection = function(string $label, array $blocks, array $layoutOverrides = []): array {
    return [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), $layoutOverrides),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => $blocks,
    ];
};

$richText = function(string $html): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.rich_text',
        'version'    => 1,
        'props'      => ['content' => $html],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

$statsBlock = function(array $stats): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.stats',
        'version'    => 1,
        'props'      => [
            'items' => array_map(static fn(array $item): array => [
                'value'    => (float) $item['value'],
                'label'    => (string) $item['label'],
                'prefix'   => (string) ($item['prefix'] ?? ''),
                'suffix'   => (string) ($item['suffix'] ?? ''),
                'decimals' => (int) ($item['decimals'] ?? 0),
            ], $stats),
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

$tabsBlock = function(array $tabs, string $position = 'top'): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.tabs',
        'version'    => 1,
        'props'      => [
            'items'   => array_map(static fn(array $tab): array => [
                'label'   => (string) $tab['label'],
                'content' => (string) $tab['content'],
            ], $tabs),
            'options' => ['position' => $position],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

$accordionBlock = function(array $items): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.accordion',
        'version'    => 1,
        'props'      => [
            'items'          => array_map(static fn(array $it): array => [
                'heading' => (string) ($it['heading'] ?? $it['title'] ?? ''),
                'body'    => (string) ($it['body'] ?? $it['content'] ?? ''),
            ], $items),
            'allow_multiple' => false,
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

$carouselBlock = function(array $slides): array {
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'core.carousel',
        'version'    => 1,
        'props'      => [
            'slides'  => array_map(static fn(array $s): array => [
                'image'   => null,
                'quote'   => (string) ($s['quote'] ?? $s['content'] ?? ''),
                'author'  => (string) ($s['author'] ?? $s['title'] ?? ''),
                'caption' => (string) ($s['caption'] ?? $s['subtitle'] ?? ''),
            ], $slides),
            'options' => [
                'per_view'    => 1,
                'loop'        => true,
                'autoplay_ms' => 0,
            ],
        ],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];
};

echo "2. Building & publishing Construction Company pages...\n\n";

// =========================================================================
// PAGE 1: HOME (Homepage)
// =========================================================================
$docHome = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Vanguard Construction Group');
$docHome['sections'] = [
    // 1. Hero Section
    $createSection('Hero: Precision Construction', [
        $richText(
            '<p><strong>LICENSED GENERAL CONTRACTORS · EST. 1998 · $50M BONDING CAPACITY</strong></p>'
            . '<h1>Built With Precision. Engineered For Generations.</h1>'
            . '<p>Vanguard Construction Group delivers premier commercial complexes, bespoke luxury residences, advanced industrial hubs, and critical infrastructure across Texas and the Southwest. Delivered on schedule, within budget, and built without compromise.</p>'
            . '<p><a href="/contact"><strong>Request a Project Consultation ↗</strong></a> · <a href="/projects">Explore Our Portfolio</a> · <a href="tel:5557284900">Direct: (555) 728-4900</a></p>'
        ),
        $statsBlock([
            ['label' => 'Completed Projects', 'value' => 480, 'prefix' => '', 'suffix' => '+', 'decimals' => 0],
            ['label' => 'Delivered Contract Value', 'value' => 1.2, 'prefix' => '$', 'suffix' => 'B', 'decimals' => 1],
            ['label' => 'Lost-Time Incidents (5 Yrs)', 'value' => 0, 'prefix' => '', 'suffix' => '', 'decimals' => 0],
            ['label' => 'On-Time Handover Rate', 'value' => 99.4, 'prefix' => '', 'suffix' => '%', 'decimals' => 1],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    // 2. Trust Credentials & Industry Accreditations
    $createSection('Industry Certifications & Credentials', [
        $richText(
            '<h3>Certified Excellence &amp; Jobsite Safety Accreditations</h3>'
            . '<p>🛡️ <strong>OSHA VPP Star Safety Certified</strong> · 🌱 <strong>LEED AP Gold &amp; Platinum Builders</strong> · 🏛️ <strong>Associated General Contractors (AGC) Member</strong> · 📜 <strong>Licensed, Bonded &amp; Insured ($50M Single / $100M Aggregate)</strong></p>'
        )
    ], ['width' => 'full', 'padding_y' => ['base' => 'md', 'md' => 'md'], 'background_token' => 'surface.secondary']),

    // 3. Four Core Construction Sectors Tabs
    $createSection('Specialized Construction Sectors', [
        $richText(
            '<h2>Comprehensive Capabilities Across Four Primary Sectors</h2>'
            . '<p>From urban commercial high-rises to custom architectural estates, our dedicated project teams deliver specialized craftsmanship with integrated project controls.</p>'
        ),
        $tabsBlock([
            [
                'label'   => 'Commercial & Corporate',
                'content' => "Commercial High-Rises & Corporate Campuses: Class-A office towers, medical office pavilions, multi-tenant retail power centers, and financial headquarters built for long-term operational efficiency.\n• Post-tensioned concrete and structural steel frame erection\n• High-efficiency double-curtain glass facades & energy modeling\n• Guaranteed Maximum Price (GMP) delivery models",
            ],
            [
                'label'   => 'Luxury Custom Residential',
                'content' => "One-of-a-Kind Architectural Residences: Bespoke custom homes, cantilevered hillside estates, whole-home historic restorations, and private retreat compounds engineered to the highest architectural standards.\n• Architectural board-form and cast-in-place concrete structures\n• Imported structural glazing and custom thermal building envelopes\n• Dedicated residential project directors and craftsmen",
            ],
            [
                'label'   => 'Industrial & Logistics',
                'content' => "Advanced Warehousing & Manufacturing: High-bay automated distribution facilities, cold-chain storage facilities, pharmaceutical cleanrooms, and advanced manufacturing plants.\n• Laser-screeded F-min 100 super-flat industrial floor slabs\n• 42-foot clear heights with insulated tilt-wall panel construction\n• Heavy power distribution and redundant backup generators",
            ],
            [
                'label'   => 'Civil & Heavy Concrete',
                'content' => "Mass Concrete & Municipal Infrastructure: Mass concrete placements, deep foundations, seismic structural retrofitting, municipal utility realignments, and bridge approaches.\n• FRP carbon-fiber structural column reinforcement\n• Deep micropile underpinning and pressure grouting\n• Complex urban civil works executed with zero lane-closure downtime",
            ],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    // 4. Featured Completed Projects Showcase
    $createSection('Featured Projects Showcase', [
        $richText(
            '<h2>Signature Projects Delivered Across Texas</h2>'
            . '<p>A selected preview of our 480+ completed builds. <a href="/projects">View the full portfolio directory ↗</a></p>'
            . '<h3>🏢 Apex Financial Tower — $78M</h3>'
            . '<p><strong>Commercial Class-A High-Rise · Austin, TX · 28 Stories · 480,000 sq ft</strong><br>Features LEED Gold certification, subterranean parking, post-tensioned slabs, and double-curtain facade delivered 4 weeks ahead of schedule.</p>'
            . '<h3>🏡 The Highland Cantilever Estate — $14M</h3>'
            . '<p><strong>Bespoke Custom Residential · Westlake Hills · 11,200 sq ft</strong><br>Cantilevered architectural residence suspended over a limestone bluff with architectural board-form concrete and zero-edge infinity pool.</p>'
            . '<h3>📦 Pacific Cold-Chain Distribution Hub — $42M</h3>'
            . '<p><strong>Industrial Automated Logistics · San Antonio, TX · 320,000 sq ft</strong><br>Automated temperature-controlled logistics center with 42ft clear height, 48 dock positions, and laser-screeded industrial floor slab.</p>'
            . '<h3>🏥 Travis County Medical Pavilion — $34M</h3>'
            . '<p><strong>Healthcare &amp; Life Sciences · Austin, TX · 75,000 sq ft</strong><br>Outpatient surgical hospital and bio-tech laboratory equipped with medical gas distribution and full OSHPD / NFPA 99 compliance.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.secondary']),

    // 5. The Vanguard 4-Stage Construction Process Accordion
    $createSection('4-Stage Project Delivery Framework', [
        $richText(
            '<h2>Our Proven 4-Phase Delivery Framework</h2>'
            . '<p>We eliminate unpredictability from complex construction through digital BIM modeling, proactive supply chain procurement, and transparent weekly reporting.</p>'
        ),
        $accordionBlock([
            [
                'heading' => 'Phase 01: Pre-Construction, 3D BIM & Value Engineering',
                'body'    => 'Comprehensive site feasibility, 3D Building Information Modeling (BIM) clash elimination, detailed trade cost modeling, and value engineering saving an average of 8-12% on materials before ground is broken.',
            ],
            [
                'heading' => 'Phase 02: Permitting & Trade Procurement',
                'body'    => 'Securing long-lead structural steel and electrical equipment early, pre-qualifying specialty trade subcontractors, and expediting municipal approvals through established agency relationships.',
            ],
            [
                'heading' => 'Phase 03: Field Execution & Quality Assurance',
                'body'    => 'Full-time site superintendence, daily OSHA compliance audits, weekly milestone drone imagery, laser-verified tolerances, and rigid weekly owner-architect-contractor (OAC) updates.',
            ],
            [
                'heading' => 'Phase 04: Commissioning & Turnkey Handover',
                'body'    => 'Full MEP systems balancing and commissioning, zero-item punch list policy, digitized operations and maintenance manuals, and a backed 10-year structural warranty bond.',
            ],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    // 6. Testimonials Carousel
    $createSection('Owner & Architect Endorsements', [
        $richText(
            '<h2>Trusted By Leading Developers &amp; Property Owners</h2>'
            . '<p>Read testimonials from commercial real estate trusts, institutional leaders, and custom homeowners.</p>'
        ),
        $carouselBlock([
            [
                'author'  => 'Arthur Pendelton — Managing Director, Pendelton REIT',
                'caption' => '★★★★★ Finished 3 weeks ahead of schedule and $420k under budget.',
                'quote'   => 'Vanguard completed our 180,000 sq ft headquarters three weeks ahead of schedule and $420k under our GMP budget. Their field superintendents are the most organized professionals we have worked with in 30 years.',
            ],
            [
                'author'  => 'Elena Rostova, AIA — Principal Architect & Estate Owner',
                'caption' => '★★★★★ Master builders who honor architectural intent without compromise.',
                'quote'   => 'Their engineering team executed an extraordinarily complex cantilever design for our hilltop residence without a single structural compromise. Truly master builders.',
            ],
            [
                'author'  => 'David Mercer — VP of Facilities, Continental Logistics',
                'caption' => '★★★★★ Super-flat slab and tilt-wall panels delivered with flawless precision.',
                'quote'   => 'Our 320,000 sq ft cold storage hub required strict temperature zoning and high-tolerance flooring for robotics. Vanguard delivered the facility on an aggressive 14-month schedule.',
            ],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.secondary']),

    // 7. Bottom Call to Action
    $createSection('Call to Action: Break Ground', [
        $richText(
            '<h2>Ready To Break Ground On Your Next Project?</h2>'
            . '<p>Speak directly with our chief estimators and senior project managers. Receive an initial constructability review and budget appraisal within 48 hours.</p>'
            . '<p><a href="/contact"><strong>Get a Free Project Estimate ↗</strong></a> · <a href="tel:5557284900">Direct Estimating Line: (555) 728-4900</a> · <a href="mailto:bids@vanguardbuild.com">bids@vanguardbuild.com</a></p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),
];

$createAndPublishPage(
    'home',
    'Vanguard Construction Group — Premier General Contractors',
    'page',
    'homepage',
    $docHome,
    [
        'title' => 'Vanguard Construction Group — Commercial & Residential General Contractors',
        'description' => 'Over 25 years of construction excellence. Commercial high-rises, custom luxury residential, industrial facilities, and heavy civil construction.',
    ]
);

// =========================================================================
// PAGE 2: ABOUT US (/about)
// =========================================================================
$docAbout = CanonicalDocumentSchema::emptyDocument('page', 'default', 'About Us — Vanguard Construction Group');
$docAbout['sections'] = [
    $createSection('About Hero', [
        $richText(
            '<p><strong>COMPANY OVERVIEW · ESTABLISHED 1998</strong></p>'
            . '<h1>Twenty-Eight Years of Unwavering Craftsmanship &amp; Integrity</h1>'
            . '<p>Founded in 1998, Vanguard Construction Group has grown from a regional concrete and framing contractor into a premier full-service general contracting firm managing over $1.2B in completed assets across Texas and the Southwest.</p>'
        ),
        $statsBlock([
            ['label' => 'Consecutive Safe Hours', 'value' => 1.8, 'prefix' => '', 'suffix' => 'M+', 'decimals' => 1],
            ['label' => 'Full-Time Craft Personnel', 'value' => 145, 'prefix' => '', 'suffix' => '', 'decimals' => 0],
            ['label' => 'Heavy Equipment Fleet Units', 'value' => 62, 'prefix' => '', 'suffix' => '', 'decimals' => 0],
            ['label' => 'Bonding Capacity', 'value' => 100, 'prefix' => '$', 'suffix' => 'M', 'decimals' => 0],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    $createSection('Core Values & Safety First', [
        $richText(
            '<h2>Guiding Principles That Drive Every Jobsite</h2>'
            . '<h3>🛡️ Zero-Harm Safety Protocol</h3>'
            . '<p>Our OSHA Voluntary Protection Program Star status reflects our belief that no deadline or budget justifies compromising jobsite safety. Over 1.8M consecutive man-hours without a lost-time event.</p>'
            . '<h3>📐 Strict Material &amp; Craft Standards</h3>'
            . '<p>We source only certified structural steel, lab-tested aggregates, and low-VOC sustainable components from accredited regional suppliers with full chain-of-custody documentation.</p>'
            . '<h3>🤝 Open-Book Transparency</h3>'
            . '<p>All Guaranteed Maximum Price (GMP) and Cost-Plus contracts feature complete open-book trade accounting. Every dollar spent is verified and auditable by the project owner.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'lg', 'md' => 'xl'], 'background_token' => 'surface.secondary']),

    $createSection('Executive Leadership Team', [
        $richText(
            '<h2>Seasoned Construction Leaders &amp; Engineers</h2>'
            . '<p>Our executive team brings over a century of combined field and design-build engineering experience to every project.</p>'
            . '<h3>Robert Vance, PE — Founder &amp; Chief Executive Officer</h3>'
            . '<p>34 years in commercial and infrastructure development. BS &amp; MS Civil Engineering, Stanford University. Former AGC Regional Board Member.</p>'
            . '<h3>Marcus Thorne — VP of Field Operations &amp; Safety Director</h3>'
            . '<p>27 years on active jobsites overseeing multi-trade orchestration and OSHA compliance across 250+ major commercial and civil builds.</p>'
            . '<h3>Elena Alvarez, AIA — Director of Design-Build Services</h3>'
            . '<p>Licensed architect with 19 years specializing in integrated project delivery, BIM coordinate modeling, and LEED Gold certification.</p>'
            . '<h3>David Sterling — Chief Estimator &amp; Pre-Construction</h3>'
            . '<p>22 years mastering cost modeling, value engineering, regional supply chain procurement, and subcontractor bidding.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),
];

$createAndPublishPage('about', 'About Us — Vanguard Construction Group', 'page', 'standalone', $docAbout);

// =========================================================================
// PAGE 3: SERVICES (/services)
// =========================================================================
$docServices = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Services — Vanguard Construction Group');
$docServices['sections'] = [
    $createSection('Services Hero', [
        $richText(
            '<p><strong>COMPREHENSIVE CAPABILITIES</strong></p>'
            . '<h1>Turnkey Construction &amp; Project Management Services</h1>'
            . '<p>From initial feasibility and schematic budget modeling through structural topping out and final occupancy permitting, Vanguard delivers total project accountability.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    $createSection('Full Scope of Services', [
        $richText(
            '<h2>End-to-End Project Capabilities</h2>'
            . '<h3>01. Pre-Construction Planning &amp; Estimating</h3>'
            . '<p>The most critical phase of any construction project occurs before a single shovel enters the earth. We perform full 3D clash detection, life-cycle cost analysis, municipal permitting strategies, and locked GMP pricing models.</p>'
            . '<ul><li>Parametric 3D BIM modeling &amp; clash elimination</li><li>Rigorous subcontractor competitive bidding</li><li>Value engineering saving an average of 8-12% on materials</li></ul>'
            . '<h3>02. General Contracting &amp; Turnkey Execution</h3>'
            . '<p>As prime contractor, Vanguard orchestrates all civil, structural, mechanical, electrical, and plumbing trades under strict critical-path schedules. We self-perform key concrete and structural framing trades to control quality and pacing.</p>'
            . '<ul><li>Dedicated full-time on-site project superintendent</li><li>Weekly drone surveys &amp; milestone schedule updates</li><li>Strict OSHA compliance and daily jobsite safety audits</li></ul>'
            . '<h3>03. Design-Build (Single-Point Responsibility)</h3>'
            . '<p>One contract, one unified team. We unite architect, engineer, and master builder under one roof, compressing project timelines by up to 25% and removing finger-pointing between designers and builders.</p>'
            . '<ul><li>Elimination of costly designer-builder change orders</li><li>Concurrent design detailing and foundation permitting</li><li>Guaranteed maximum price committed early in design</li></ul>'
            . '<h3>04. Structural &amp; Seismic Retrofitting</h3>'
            . '<p>Protecting existing commercial buildings, historic structures, and residential properties with state-of-the-art carbon fiber wraps, foundation underpinning, and moment-frame reinforcements.</p>'
            . '<ul><li>FRP (Fiber-Reinforced Polymer) structural wrapping</li><li>Deep micropile underpinning and pressure grouting</li><li>Full structural engineering certification for municipal codes</li></ul>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.secondary']),

    $createSection('Delivery Methods Comparison FAQ', [
        $richText(
            '<h2>Contracting &amp; Delivery Method Guidance</h2>'
            . '<p>We accommodate multiple standard contracting structures depending on your project financing and timeline priorities.</p>'
        ),
        $accordionBlock([
            [
                'heading' => 'Guaranteed Maximum Price (GMP) / CMAR',
                'body'    => 'The owner holds contracts with the architect and Vanguard. Vanguard provides a guaranteed ceiling price early in pre-construction. Any cost savings below the GMP are returned to the owner.',
            ],
            [
                'heading' => 'Turnkey Design-Build Delivery',
                'body'    => 'Single-point contract with Vanguard covering both complete architectural design and construction. Streamlined communication and fastest delivery timeline.',
            ],
            [
                'heading' => 'Lump Sum / Competitive Low-Bid General Contracting',
                'body'    => 'Traditional Design-Bid-Build model where architectural plans are 100% complete before bidding. Best for public works or projects requiring sealed competitive bids.',
            ],
        ])
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),
];

$createAndPublishPage('services', 'Services — Vanguard Construction Group', 'page', 'standalone', $docServices);

// =========================================================================
// PAGE 4: PROJECTS / PORTFOLIO (/projects)
// =========================================================================
$docProjects = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Projects — Vanguard Construction Group');
$docProjects['sections'] = [
    $createSection('Projects Hero', [
        $richText(
            '<p><strong>SELECTED WORK &amp; CASE STUDIES</strong></p>'
            . '<h1>Proven Track Record Across Over 480 Built Assets</h1>'
            . '<p>Explore a curated selection of our commercial high-rises, custom private estates, healthcare centers, and industrial logistics facilities.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),

    $createSection('Detailed Project Portfolio', [
        $richText(
            '<h2>Signature Project Portfolio Case Studies</h2>'
            . '<h3>🏢 Apex Financial Tower — $78M</h3>'
            . '<p><strong>Commercial Class-A High-Rise · Austin, TX · 28 Stories · 480,000 sq ft</strong><br>'
            . '28-story corporate headquarters featuring LEED Gold certification, 4 subterranean parking levels, post-tensioned slabs, and double-curtain facade delivered 4 weeks ahead of schedule.<br>'
            . '<em>Timeline: 22 Months · Delivery: CMAR · Safety: 0 Incidents</em></p>'
            . '<h3>🏡 The Highland Cantilever Estate — $14M</h3>'
            . '<p><strong>Bespoke Custom Residential · Westlake Hills · 11,200 sq ft</strong><br>'
            . 'Cantilevered architectural residence suspended over a limestone bluff with architectural board-form concrete, floor-to-ceiling glass, and zero-edge infinity pool.<br>'
            . '<em>Timeline: 18 Months · Delivery: Design-Build · Finish: Exposed Concrete</em></p>'
            . '<h3>🏥 Travis County Medical Pavilion — $34M</h3>'
            . '<p><strong>Healthcare &amp; Life Sciences · Austin, TX · 75,000 sq ft</strong><br>'
            . 'Outpatient surgical hospital and bio-tech laboratory equipped with medical gas distribution, positive-pressure cleanrooms, and full OSHPD / NFPA 99 compliance.<br>'
            . '<em>Timeline: 16 Months · Delivery: Turnkey GC · Compliance: OSHPD Certified</em></p>'
            . '<h3>📦 Pacific Cold-Chain Distribution Hub — $42M</h3>'
            . '<p><strong>Industrial Automated Logistics · San Antonio, TX · 320,000 sq ft</strong><br>'
            . 'Automated temperature-controlled logistics center with 42ft clear height, 48 dock positions, and laser-screeded F-min 100 super-flat industrial floor slab.<br>'
            . '<em>Timeline: 14 Months · Delivery: Design-Build · Scale: 320,000 sq ft</em></p>'
            . '<h3>🏫 St. Jude STEM Research Center — $28M</h3>'
            . '<p><strong>Higher Education &amp; Laboratories · 65,000 sq ft</strong><br>'
            . '3-story university science center featuring tiered lecture halls, wet research laboratories, and exposed mass-timber glulam structural framing.<br>'
            . '<em>Timeline: 15 Months · Delivery: CMAR · Structure: Mass Timber</em></p>'
            . '<h3>🌉 Red River Bridge Viaduct Retrofit — $19M</h3>'
            . '<p><strong>Civil Infrastructure · 6-Lane Municipal Crossing</strong><br>'
            . 'Structural rehabilitation and seismic upgrading of a 6-lane municipal bridge crossing using carbon fiber column wraps and hydrodemolition deck resurfacing.<br>'
            . '<em>Timeline: 9 Months · Delivery: Heavy Civil · Impact: Zero Lane Closures</em></p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.secondary']),
];

$createAndPublishPage('projects', 'Projects — Vanguard Construction Group', 'page', 'standalone', $docProjects);

// =========================================================================
// PAGE 5: CONTACT & ESTIMATION (/contact)
// =========================================================================
$docContact = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Contact — Vanguard Construction Group');
$docContact['sections'] = [
    $createSection('Contact Hero & Inquiries', [
        $richText(
            '<p><strong>DIRECT CONSULTATION &amp; ESTIMATION DESK</strong></p>'
            . '<h1>Let\'s Discuss Your Upcoming Construction Build</h1>'
            . '<p>Whether you have finished architectural blueprints ready for tender or are evaluating pre-construction feasibility on raw land, our senior estimating directors are available to assist.</p>'
            . '<h3>📞 Direct Contact Channels</h3>'
            . '<p><strong>Direct Telephone:</strong> <a href="tel:5557284900">(555) 728-4900</a><br>'
            . '<strong>Bid Submissions &amp; Plans:</strong> <a href="mailto:bids@vanguardbuild.com">bids@vanguardbuild.com</a><br>'
            . '<strong>General Inquiries:</strong> <a href="mailto:info@vanguardbuild.com">info@vanguardbuild.com</a><br>'
            . '<strong>24/7 Jobsite Emergency Line:</strong> <a href="tel:5557284911">(555) 728-4911</a></p>'
            . '<h3>🏢 Corporate Headquarters &amp; Yard</h3>'
            . '<p>Vanguard Construction Group<br>740 Industrial Parkway, Suite 400<br>Austin, TX 78701<br>Office Hours: Monday – Friday, 7:00 AM – 6:00 PM CST</p>'
            . '<h3>📋 Request a Project Estimate</h3>'
            . '<p>To request a preliminary constructability review, send project location, architectural drawings (PDF/BIM), and target groundbreaking dates to <strong><a href="mailto:bids@vanguardbuild.com">bids@vanguardbuild.com</a></strong>. A Senior Estimator will respond within 24 business hours.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.primary']),
];

$createAndPublishPage('contact', 'Contact — Vanguard Construction Group', 'page', 'standalone', $docContact);

// =========================================================================
// PAGE 6: HEADER PARTIAL (Site-Wide Construction Navigation)
// =========================================================================
$docHeader = CanonicalDocumentSchema::emptyDocument('header_partial', 'default', 'Site Header');
$docHeader['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$docHeader['settings']['template_type'] = 'header';
$docHeader['sections'] = [
    $createSection('Sticky Construction Navigation Header', [
        $richText(
            '<p>🏗️ <strong>VANGUARD CONSTRUCTION GROUP</strong> · General Contractor Lic #TX-849201</p>'
            . '<p><a href="/home"><strong>Home</strong></a> · <a href="/about"><strong>About Us</strong></a> · <a href="/services"><strong>Services</strong></a> · <a href="/projects"><strong>Projects</strong></a> · <a href="/contact"><strong>Contact &amp; Free Quote</strong></a> · <a href="tel:5557284900">📞 (555) 728-4900</a></p>'
        )
    ], ['width' => 'full', 'padding_y' => ['base' => 'sm', 'md' => 'sm'], 'background_token' => 'surface.transparent']),
];

$createAndPublishPage('default', 'Vanguard Construction Header', 'header_partial', 'standalone', $docHeader);

// =========================================================================
// PAGE 7: FOOTER PARTIAL (Site-Wide Construction Mega Footer)
// =========================================================================
$docFooter = CanonicalDocumentSchema::emptyDocument('footer_partial', 'default', 'Site Footer');
$docFooter['settings']['conditions'] = ['rules' => [['type' => 'include', 'condition' => 'entire_site']]];
$docFooter['settings']['template_type'] = 'footer';
$docFooter['sections'] = [
    $createSection('Construction Mega Footer', [
        $richText(
            '<p>🏗️ <strong>VANGUARD CONSTRUCTION GROUP</strong></p>'
            . '<p>Engineering excellence and master general contracting across Texas and the Southwest since 1998. General Contractor Lic #TX-849201. $50M Single / $100M Aggregate Bonding Capacity.</p>'
            . '<p><strong>Services:</strong> <a href="/services">Pre-Construction &amp; BIM</a> · <a href="/services">General Contracting</a> · <a href="/services">Design-Build Delivery</a> · <a href="/services">Industrial Warehousing</a> · <a href="/services">Seismic Retrofit</a></p>'
            . '<p><strong>Company:</strong> <a href="/about">Our Story &amp; Leadership</a> · <a href="/projects">Project Portfolio</a> · <a href="/about">Safety Commitment (OSHA VPP Star)</a> · <a href="/contact">Subcontractor Prequalification</a> · <a href="/contact">Request a Quote</a></p>'
            . '<p><strong>Direct Office:</strong> 740 Industrial Parkway, Suite 400, Austin, TX 78701 · <strong>Phone:</strong> (555) 728-4900 · <strong>Bids:</strong> bids@vanguardbuild.com</p>'
            . '<p>© ' . date('Y') . ' Vanguard Construction Group. All rights reserved. OSHA VPP Star Certified · LEED Gold Accredited · AGC Member.</p>'
        )
    ], ['width' => 'wide', 'padding_y' => ['base' => 'xl', 'md' => '2xl'], 'background_token' => 'surface.secondary']),
];

$createAndPublishPage('default-footer', 'Vanguard Construction Footer', 'footer_partial', 'standalone', $docFooter);

echo "\n=========================================================\n";
echo "  ✓ Seeding Complete!\n";
echo "  All 5 Construction Company pages and partials are live:\n";
echo "    - /home (Homepage)\n";
echo "    - /about (About Us & Leadership)\n";
echo "    - /services (Capabilities & Delivery Methods)\n";
echo "    - /projects (Selected Portfolio)\n";
echo "    - /contact (Estimation & Inquiries)\n";
echo "    - Site Header & Mega Footer partials\n";
echo "=========================================================\n";
