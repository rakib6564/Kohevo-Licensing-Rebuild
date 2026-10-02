<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 7 Acceptance.
 *
 * Verifies Sprint 7 Acceptance Criteria (Section 63 & Sections 22, 23, 24, 30):
 *   The platform can support a complete production marketing website:
 *   - Modal / popup: `core.modal` with accessible dialog markup, trigger button, and aria attributes.
 *   - Offcanvas drawer: `layout.offcanvas` with position classes and backdrop navigation.
 *   - Forms: `core.form` and `core.form_field` supporting text, email, select, validation, and submit actions.
 *   - Media: `core.gallery` with responsive column grid and `core.video` with privacy-enhanced YouTube/Vimeo embed and schema sanitization.
 *   - Interactions and animation: entrance animations (`sb-animate-fade-up`) and interactive states (`sb-interaction-hover`).
 *   - Strict multi-tenant isolation: Tenant B never sees Tenant A's published marketing page.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    slate_require_test_database();
    require_once dirname(__DIR__) . '/unit/harness.php';
    $studioS7IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S7_MIGRATIONS = [
    '0001_core_init',
    '0002_identity_core',
    '0011_login_attempts',
    '0014_tenant_profiles',
    '0023_installation_identity',
    '0022_remote_license_cache',
    '0024_remote_license_metadata',
    '0025_remote_license_cache_installation_id',
    '0026_remote_license_cache_signed_payload',
];

const S7_TENANT_A = 101;
const S7_TENANT_B = 202;

function s7_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $dsn2 = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname={$dbName};charset=" . DB_CHARSET;
    $pdo  = new \PDO($dsn2, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S7_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s7_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);

    $activeProp = new \ReflectionProperty(\PluginLoader::class, 'active');
    $prevActive = $activeProp->getValue();
    $slugsProp  = new \ReflectionProperty(\PluginLoader::class, 'activeSlugs');
    $prevSlugs  = $slugsProp->getValue();
    $bootedProp = new \ReflectionProperty(\PluginLoader::class, 'booted');
    $prevBooted = $bootedProp->getValue();

    $envKeys = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY', 'DB_HOST', 'DB_USER', 'DB_PASS'];
    $prevEnv = [];
    foreach ($envKeys as $k) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
    }
    $_ENV['LICENSE_SERVER_URL']        = 'https://license.test';
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    $_ENV['LICENSE_PRODUCT']           = 'kohevo';
    $_ENV['LICENSE_KEY']               = 'test-key';
    $_ENV['DB_HOST']                   = DB_HOST;
    $_ENV['DB_USER']                   = DB_USER;
    $_ENV['DB_PASS']                   = DB_PASS;

    try {
        $activeProp->setValue(null, []);
        $slugsProp->setValue(null, null);
        $bootedProp->setValue(null, false);
        return $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        $activeProp->setValue(null, $prevActive);
        $slugsProp->setValue(null, $prevSlugs);
        $bootedProp->setValue(null, $prevBooted);
        $property->setValue(null, $previous);
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

function s7_make_block(string $type, array $props, array $children = [], ?array $animation = null, ?array $interactions = null): array
{
    $block = [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => $type,
        'version'    => 1,
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => $children,
    ];
    if ($animation !== null) {
        $block['animation'] = $animation;
    }
    if ($interactions !== null) {
        $block['interactions'] = $interactions;
    }
    return $block;
}

function s7_make_section(array $blocks, string $label = 'Marketing Section'): array
{
    return [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => $blocks,
    ];
}

unit('sprint7 integration: production marketing website with animation, modal, offcanvas, forms, video, gallery, and isolation', function (): void {
    $dbName = 'slate_s7_' . slate_test_ns();
    $pdo    = s7_fresh_db($dbName);

    try {
        s7_with_pdo($pdo, static function () use ($pdo): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S7_TENANT_A, 'Tenant A Marketing Pro', 'tenant-a-s7', S7_TENANT_B, 'Tenant B Isolated', 'tenant-b-s7']
            );

            // Install studio-builder with licenses
            license_test_seed_cache(S7_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S7_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            // Seed Users
            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, S7_TENANT_A, 'alice@tenanta.test', 'hash', 'Alice TenantA', 20, S7_TENANT_B, 'bob@tenantb.test', 'hash', 'Bob TenantB']
            );

            // Build StudioRuntime
            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(10, StudioPermissions::ALL);
            $actorB = StudioActor::authenticated(20, StudioPermissions::ALL);

            // 1. Tenant A: Create Complete Marketing Landing Page
            $resPage = $rt->tenants->runAs(S7_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Enterprise Product Tour', 'tour', 'page', 'standalone'));
            $pageId = (int) $resPage['page']['id'];
            $revId = (int) $resPage['revision']['id'];

            // Construct Marketing Sections:
            // Section 1: Hero with animations and interactions
            $heroHeading = s7_make_block(
                'core.heading',
                ['text' => 'The Next-Gen SaaS Platform', 'level' => 'h1'],
                [],
                ['type' => 'fade_up', 'duration' => 600],
                ['trigger' => 'hover']
            );
            $heroSubtext = s7_make_block(
                'core.text',
                ['content' => 'Automate your entire digital workflow with scalable visual site building.'],
                [],
                ['type' => 'fade_in', 'duration' => 800]
            );

            // Section 2: Offcanvas Navigation Drawer
            $offcanvasDrawer = s7_make_block(
                'layout.offcanvas',
                [
                    'drawer_id'       => 'product-nav-drawer',
                    'title'           => 'Product Directory',
                    'position'        => 'left',
                    'trigger_text'    => 'Explore Features ☰',
                    'trigger_variant' => 'primary',
                ],
                [
                    s7_make_block('core.heading', ['text' => 'Quick Links', 'level' => 'h3']),
                    s7_make_block('core.text', ['content' => 'Platform Overview, Security Whitepaper, Roadmap']),
                ]
            );

            // Section 3: Contact Modal Dialog
            $contactModal = s7_make_block(
                'core.modal',
                [
                    'modal_id'        => 'lead-capture-modal',
                    'title'           => 'Schedule an Executive Demo',
                    'size'            => 'lg',
                    'trigger_text'    => 'Book a Private Consultation',
                    'trigger_variant' => 'primary',
                ],
                [
                    // Embedded Form inside Modal
                    s7_make_block(
                        'core.form',
                        [
                            'action'         => '/api/enterprise/demo-requests',
                            'method'         => 'post',
                            'form_name'      => 'executive_demo_form',
                            'submit_text'    => 'Confirm Demo Booking',
                            'submit_variant' => 'primary',
                        ],
                        [
                            s7_make_block('core.form_field', [
                                'name'        => 'business_name',
                                'label'       => 'Company Name',
                                'field_type'  => 'text',
                                'placeholder' => 'Acme Corporation',
                                'required'    => true,
                            ]),
                            s7_make_block('core.form_field', [
                                'name'        => 'work_email',
                                'label'       => 'Work Email Address',
                                'field_type'  => 'email',
                                'placeholder' => 'alex@acme.com',
                                'required'    => true,
                            ]),
                            s7_make_block('core.form_field', [
                                'name'        => 'team_size',
                                'label'       => 'Estimated Team Size',
                                'field_type'  => 'select',
                                'options'     => '1-10, 11-50, 51-200, 200+',
                                'placeholder' => 'Select team size',
                                'required'    => false,
                            ]),
                            s7_make_block('core.form_field', [
                                'name'        => 'notes',
                                'label'       => 'Special Project Requirements',
                                'field_type'  => 'textarea',
                                'placeholder' => 'Describe your infrastructure needs...',
                            ]),
                        ]
                    ),
                ]
            );

            // Section 4: Video Showcase
            $videoShowcase = s7_make_block(
                'core.video',
                [
                    'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'aspect_ratio' => '16:9',
                    'autoplay'     => false,
                    'controls'     => true,
                ]
            );

            // Section 5: Customer Gallery
            $customerGallery = s7_make_block(
                'core.gallery',
                [
                    'columns'      => '3',
                    'gap'          => 'md',
                    'aspect_ratio' => '4:3',
                    'rounded'      => true,
                    'images'       => [
                        ['url' => 'https://assets.example.test/img1.jpg', 'alt' => 'Cloud Cluster', 'caption' => 'Global Infrastructure'],
                        ['url' => 'https://assets.example.test/img2.jpg', 'alt' => 'Security Shield', 'caption' => 'SOC2 Certified'],
                        ['url' => 'https://assets.example.test/img3.jpg', 'alt' => 'Analytics Graph', 'caption' => 'Real-time Metrics'],
                    ],
                ]
            );

            $secHero = s7_make_section([$heroHeading, $heroSubtext], 'Hero');
            $secInteractive = s7_make_section([$offcanvasDrawer, $contactModal], 'Interactive Navigation & Actions');
            $secMedia = s7_make_section([$videoShowcase, $customerGallery], 'Media & Video');

            $op1 = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secHero]);
            $op2 = new DocumentOperation('insert_section', ['index' => 1, 'section' => $secInteractive]);
            $op3 = new DocumentOperation('insert_section', ['index' => 2, 'section' => $secMedia]);

            $resEdit = $rt->tenants->runAs(S7_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $pageId, [$op1, $op2, $op3], $revId, 'Build complete marketing page'));
            $revId = (int) $resEdit['revision']['id'];

            // Publish the page
            $rt->tenants->runAs(S7_TENANT_A, static fn() => $rt->app->publish($actorA, $pageId, $revId));

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 1: Public Marketing Page Renders All Sprint 7 UI Blocks
            // ─────────────────────────────────────────────────────────────────
            $respA = $rt->tenants->runAs(S7_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/tour'));
            assert_true($respA !== null, 'Public response for /tour is returned');
            assert_eq(200, $respA->status);
            $body = $respA->body;

            // 1. Animation & Interaction compile
            assert_true(str_contains($body, 'sb-animate-fade-up'), 'Contains sb-animate-fade-up class');
            assert_true(str_contains($body, 'sb-interaction-hover'), 'Contains sb-interaction-hover class');
            assert_true(str_contains($body, 'data-sb-interaction-trigger="hover"'), 'Contains interaction trigger data attribute');

            // 2. Offcanvas Navigation Drawer
            assert_true(str_contains($body, 'data-sb-offcanvas-open="product-nav-drawer"'), 'Contains offcanvas trigger attribute');
            assert_true(str_contains($body, 'Explore Features ☰'), 'Contains offcanvas trigger button text');
            assert_true(str_contains($body, 'id="product-nav-drawer"'), 'Contains offcanvas container id');
            assert_true(str_contains($body, 'sb-offcanvas--left'), 'Contains offcanvas left position class');
            assert_true(str_contains($body, 'data-sb-offcanvas-close'), 'Contains offcanvas close control');
            assert_true(str_contains($body, 'Product Directory'), 'Contains offcanvas title');
            assert_true(str_contains($body, 'Platform Overview, Security Whitepaper'), 'Contains offcanvas child content');

            // 3. Modal Dialog
            assert_true(str_contains($body, 'data-sb-modal-open="lead-capture-modal"'), 'Contains modal open trigger');
            assert_true(str_contains($body, 'Book a Private Consultation'), 'Contains modal trigger text');
            assert_true(str_contains($body, 'id="lead-capture-modal"'), 'Contains modal container id');
            assert_true(str_contains($body, 'role="dialog"'), 'Contains accessible role="dialog"');
            assert_true(str_contains($body, 'aria-modal="true"'), 'Contains aria-modal="true"');
            assert_true(str_contains($body, 'Schedule an Executive Demo'), 'Contains modal dialog title');
            assert_true(str_contains($body, 'data-sb-modal-close'), 'Contains modal close control');

            // 4. Form and Fields
            assert_true(str_contains($body, 'action="/api/enterprise/demo-requests"'), 'Contains form action URL');
            assert_true(str_contains($body, 'method="post"'), 'Contains form method');
            assert_true(str_contains($body, 'data-sb-form="executive_demo_form"'), 'Contains data-sb-form attribute');
            assert_true(str_contains($body, 'name="business_name"'), 'Contains text input name');
            assert_true(str_contains($body, 'placeholder="Acme Corporation"'), 'Contains input placeholder');
            assert_true(str_contains($body, 'name="work_email"'), 'Contains email input name');
            assert_true(str_contains($body, 'type="email"'), 'Contains email type');
            assert_true(str_contains($body, '<select name="team_size"'), 'Contains select dropdown');
            assert_true(str_contains($body, '<option value="51-200">51-200</option>'), 'Contains select options');
            assert_true(str_contains($body, '<textarea name="notes"'), 'Contains textarea element');
            assert_true(str_contains($body, 'Confirm Demo Booking'), 'Contains submit button text');

            // 5. Video with YouTube Sanitization
            assert_true(str_contains($body, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'Contains privacy-enhanced YouTube embed');
            assert_true(str_contains($body, 'sb-video--aspect-16-9'), 'Contains aspect ratio wrapper class');
            assert_true(str_contains($body, 'allowfullscreen'), 'Contains allowfullscreen attribute');

            // 6. Media Gallery
            assert_true(str_contains($body, 'sb-gallery--cols-3'), 'Contains cols-3 grid class');
            assert_true(str_contains($body, 'sb-gallery--gap-md'), 'Contains gap-md class');
            assert_true(str_contains($body, 'sb-gallery--rounded'), 'Contains rounded class');
            assert_true(str_contains($body, 'src="https://assets.example.test/img1.jpg"'), 'Contains gallery image 1');
            assert_true(str_contains($body, 'Global Infrastructure'), 'Contains gallery caption');

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 2: Multi-Tenant Isolation
            // ─────────────────────────────────────────────────────────────────
            // Tenant B requesting /tour -> returns null (Tenant B has not created or published this page)
            $respB = $rt->tenants->runAs(S7_TENANT_B, static fn() => $rt->publicRuntime->handlePath('/tour'));
            assert_null($respB, 'Tenant B cannot access Tenant A published marketing website page');
        });
    } finally {
        $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
        $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
        (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
});
