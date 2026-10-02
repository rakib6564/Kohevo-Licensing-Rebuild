<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 8: Developer Widget SDK & Third-Party Extension Loading.
 *
 * Verifies Sprint 8 requirements (Section 64 & Sections 31, 32):
 *  - WidgetSecurityValidator enforces namespace isolation, prevents reserved namespace hijacking,
 *    and validates schemas, permissions, and renderers.
 *  - CustomWidgetDefinition encapsulates widget metadata and projects to BlockDefinitionInterface and BlockRendererInterface.
 *  - WidgetSdk manages registration, discovery, hook loading, and registry population.
 *  - CustomWidgetRendererAdapter sandboxes renderer execution with fail-closed error handling.
 *  - WidgetMigrator executes version upgrades (v1 -> v2) on blocks and documents.
 *  - Studio::widgets() facade provides clean developer entry point.
 *  - Example plugin StudioTestimonialWidget registers acme.testimonial and renders correctly.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\WidgetRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Sdk\CustomWidgetDefinition;
use Slate\Module\StudioBuilder\Sdk\CustomWidgetRendererAdapter;
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\Sdk\WidgetMigrator;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;
use Slate\Module\StudioBuilder\Sdk\WidgetSecurityValidator;
use Slate\Tenancy\TenantContext;

function s8_scope(array $block, string $children = ''): BlockRenderScope
{
    $tenants = new TenantContext();
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();
    $media = new CoreMediaResolver($tenants);
    return new BlockRenderScope($block, $context, $media, $theme, $collector, $children, []);
}

unit('sprint8 unit: WidgetSecurityValidator enforces namespacing, reserved names, and schema safety', function (): void {
    // 1. Valid custom widget definition passes
    WidgetSecurityValidator::validate([
        'type'     => 'acme.pricing_table',
        'version'  => 1,
        'label'    => 'Pricing Table',
        'schema'   => [
            ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true],
            ['key' => 'price', 'type' => 'number', 'label' => 'Price', 'required' => true],
        ],
        'renderer' => fn(BlockRenderScope $s): string => '<div>Table</div>',
    ]);
    assert_true(true, 'Valid custom widget definition passes security validation');

    // 2. Reject unnamespaced type
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'pricing_table',
            'version'  => 1,
            'label'    => 'Pricing Table',
            'renderer' => fn() => '',
        ]);
    }, 'Rejects unnamespaced type');

    // 3. Reject reserved core namespaces
    foreach (['core.hero_custom', 'layout.banner', 'theme.header_custom', 'system.log', 'slate.internal'] as $reservedType) {
        assert_throws(StudioValidationException::class, static function () use ($reservedType): void {
            WidgetSecurityValidator::validate([
                'type'     => $reservedType,
                'version'  => 1,
                'label'    => 'Reserved Type',
                'renderer' => fn() => '',
            ]);
        }, "Rejects reserved namespace in '{$reservedType}'");
    }

    // 4. Reject invalid version (< 1)
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'acme.pricing_table',
            'version'  => 0,
            'label'    => 'Pricing Table',
            'renderer' => fn() => '',
        ]);
    }, 'Rejects version < 1');

    // 5. Reject empty label
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'acme.pricing_table',
            'version'  => 1,
            'label'    => '   ',
            'renderer' => fn() => '',
        ]);
    }, 'Rejects empty label');

    // 6. Reject forbidden property keys (e.g. tenant_id, children, id)
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'acme.pricing_table',
            'version'  => 1,
            'label'    => 'Pricing Table',
            'schema'   => [
                ['key' => 'tenant_id', 'type' => 'number', 'label' => 'Tenant'],
            ],
            'renderer' => fn() => '',
        ]);
    }, 'Rejects reserved property key tenant_id');

    // 7. Reject invalid field type
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'acme.pricing_table',
            'version'  => 1,
            'label'    => 'Pricing Table',
            'schema'   => [
                ['key' => 'config', 'type' => 'dangerous_eval_type', 'label' => 'Config'],
            ],
            'renderer' => fn() => '',
        ]);
    }, 'Rejects unknown/unsupported field type');

    // 8. Reject missing renderer
    assert_throws(StudioValidationException::class, static function (): void {
        WidgetSecurityValidator::validate([
            'type'     => 'acme.pricing_table',
            'version'  => 1,
            'label'    => 'Pricing Table',
            'renderer' => null,
        ]);
    }, 'Rejects null renderer');
});

unit('sprint8 unit: CustomWidgetDefinition projects to BlockDefinitionInterface and BlockRendererInterface', function (): void {
    $widget = new CustomWidgetDefinition(
        type: 'partner.countdown',
        version: 2,
        label: 'Event Countdown',
        category: 'marketing',
        icon: 'clock',
        schema: [
            ['key' => 'target_date', 'type' => 'string', 'label' => 'Target Date', 'required' => true],
            ['key' => 'headline', 'type' => 'string', 'label' => 'Headline', 'required' => false, 'default' => 'Launching Soon'],
        ],
        renderer: function (BlockRenderScope $scope): string {
            return '<div class="countdown">' . htmlspecialchars($scope->string('headline'), ENT_QUOTES, 'UTF-8') . '</div>';
        },
        migration: function (int $from, int $to, array $props): array {
            if ($from === 1 && $to === 2) {
                $props['show_seconds'] = true;
            }
            return $props;
        }
    );

    assert_eq('partner.countdown', $widget->type());
    assert_eq(2, $widget->version());
    assert_eq('Event Countdown', $widget->label());
    assert_eq('marketing', $widget->category());
    assert_eq('clock', $widget->icon());

    // BlockDefinitionInterface projection
    $blockDef = $widget->toBlockDefinition();
    assert_eq('partner.countdown', $blockDef->type());
    assert_eq(2, $blockDef->version());
    assert_eq('Event Countdown', $blockDef->label());
    assert_eq('marketing', $blockDef->category());
    assert_false($blockDef->allowsChildren());

    $manifest = $blockDef->toEditorManifest();
    assert_eq('partner.countdown', $manifest['type']);
    assert_eq(2, $manifest['version']);
    assert_true(is_array($manifest['field_schema']));

    // BlockRendererInterface projection
    $renderer = $widget->toBlockRenderer();
    assert_eq('partner.countdown', $renderer->type());
    assert_false($renderer->isDynamic());

    $scope = s8_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'partner.countdown',
        'version' => 2,
        'props'   => ['headline' => 'Black Friday Sale', 'target_date' => '2026-11-27'],
    ]);
    $html = $renderer->render($scope);
    assert_true(str_contains($html, 'Black Friday Sale'), 'Renderer outputs rendered headline');

    // Migration hook
    assert_true($widget->canMigrate(1, 2));
    assert_false($widget->canMigrate(2, 2));
    $migrated = $widget->migrate(1, 2, ['target_date' => '2026-11-27']);
    assert_true($migrated['show_seconds'] ?? false, 'Migration sets show_seconds');
});

unit('sprint8 unit: CustomWidgetRendererAdapter fails closed on renderer exceptions', function (): void {
    $failingRenderer = new CustomWidgetRendererAdapter(
        'vendor.broken_widget',
        function (BlockRenderScope $scope): string {
            throw new \RuntimeException('Database connection timed out');
        }
    );

    // In public mode (diagnostics disabled), failure outputs empty string (fail closed)
    $scopePublic = s8_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'vendor.broken_widget',
        'version' => 1,
        'props'   => [],
    ]);
    $outPublic = $failingRenderer->render($scopePublic);
    assert_eq('', $outPublic, 'Fails closed to empty string in public mode without crashing');
});

unit('sprint8 unit: Studio::widgets() and WidgetSdk register and populate registries', function (): void {
    WidgetSdk::reset();
    assert_eq(0, Studio::widgets()->count());

    Studio::widgets()->register([
        'type'     => 'acme.banner',
        'version'  => 1,
        'label'    => 'Promo Banner',
        'category' => 'marketing',
        'icon'     => 'flag',
        'schema'   => [
            ['key' => 'message', 'type' => 'string', 'label' => 'Banner Message', 'required' => true],
        ],
        'renderer' => fn(BlockRenderScope $s): string => '<aside>' . htmlspecialchars($s->string('message'), ENT_QUOTES, 'UTF-8') . '</aside>',
    ]);

    assert_eq(1, Studio::widgets()->count());
    assert_true(Studio::widgets()->has('acme.banner'));
    $retrieved = Studio::widgets()->get('acme.banner');
    assert_true($retrieved !== null);
    assert_eq('Promo Banner', $retrieved->label());

    // Populate onto BlockRegistry and BlockRendererRegistry
    $blockReg = BlockRegistry::withCoreFoundationBlocks();
    $rendererReg = new BlockRendererRegistry();
    $widgetReg = new WidgetRegistry();

    assert_false($blockReg->has('acme.banner'));
    assert_false($rendererReg->has('acme.banner'));
    assert_false($widgetReg->has('acme.banner'));

    Studio::widgets()->populate($blockReg, $rendererReg, $widgetReg);

    assert_true($blockReg->has('acme.banner'), 'Populated into BlockRegistry');
    assert_true($rendererReg->has('acme.banner'), 'Populated into BlockRendererRegistry');
    assert_true($widgetReg->has('acme.banner'), 'Populated into WidgetRegistry');

    // Clean up
    WidgetSdk::reset();
    assert_eq(0, Studio::widgets()->count());
});

unit('sprint8 unit: WidgetMigrator transforms older block and document versions', function (): void {
    WidgetSdk::reset();

    Studio::widgets()->register([
        'type'      => 'vendor.stat_box',
        'version'   => 2,
        'label'     => 'Stat Box',
        'schema'    => [
            ['key' => 'value', 'type' => 'string', 'label' => 'Value', 'required' => true],
            ['key' => 'prefix', 'type' => 'string', 'label' => 'Prefix', 'required' => false],
            ['key' => 'suffix', 'type' => 'string', 'label' => 'Suffix', 'required' => false],
        ],
        'renderer'  => fn(BlockRenderScope $s): string => '<div>' . htmlspecialchars($s->string('value'), ENT_QUOTES, 'UTF-8') . '</div>',
        'migration' => function (int $from, int $to, array $props): array {
            if ($from === 1 && $to === 2) {
                // v1 had 'number_display'; v2 split it into 'value' and 'suffix'
                $num = (string) ($props['number_display'] ?? '100%');
                $props['value'] = rtrim($num, '%');
                $props['suffix'] = str_ends_with($num, '%') ? '%' : '';
                unset($props['number_display']);
            }
            return $props;
        },
    ]);

    // 1. Migrate single block
    $oldBlock = [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => 'vendor.stat_box',
        'version'    => 1,
        'props'      => ['number_display' => '99%'],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => [],
    ];

    $migratedBlock = WidgetMigrator::migrateBlock($oldBlock);
    assert_eq(2, $migratedBlock['version'], 'Version updated to 2');
    assert_eq('99', $migratedBlock['props']['value']);
    assert_eq('%', $migratedBlock['props']['suffix']);
    assert_false(isset($migratedBlock['props']['number_display']));

    // 2. Migrate full document
    $doc = CanonicalDocumentSchema::emptyDocument();
    $doc['sections'][] = [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Stats Section',
        'global_ref' => null,
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [$oldBlock],
    ];

    $migratedDoc = Studio::migrate($doc);
    assert_eq(2, $migratedDoc['sections'][0]['blocks'][0]['version']);
    assert_eq('99', $migratedDoc['sections'][0]['blocks'][0]['props']['value']);
    assert_eq('%', $migratedDoc['sections'][0]['blocks'][0]['props']['suffix']);

    WidgetSdk::reset();
});

unit('sprint8 unit: StudioTestimonialWidget example plugin boots, renders, and migrates', function (): void {
    WidgetSdk::reset();

    require_once dirname(__DIR__, 2) . '/plugins/studio-testimonial-widget/StudioTestimonialWidget.php';

    $plugin = new StudioTestimonialWidget(
        'studio-testimonial-widget',
        ['version' => '1.0.0'],
        dirname(__DIR__, 2) . '/plugins/studio-testimonial-widget'
    );
    $plugin->boot();

    assert_true(Studio::widgets()->has('acme.testimonial'), 'acme.testimonial is registered by plugin');
    $widget = Studio::widgets()->get('acme.testimonial');
    assert_eq('Testimonial Card', $widget->label());
    assert_eq('marketing', $widget->category());

    $scope = s8_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'acme.testimonial',
        'version' => 1,
        'props'   => [
            'author'     => 'Marcus Vance',
            'role'       => 'Chief Architect',
            'company'    => 'CloudScale Inc',
            'quote'      => 'The performance and extensibility are unrivaled.',
            'rating'     => 5,
            'avatar_url' => 'https://assets.example.test/marcus.jpg',
        ],
    ]);

    $renderer = $widget->toBlockRenderer();
    $html = $renderer->render($scope);

    assert_true(str_contains($html, 'data-sb-custom-widget="acme.testimonial"'), 'Contains custom widget attribute');
    assert_true(str_contains($html, 'Marcus Vance'), 'Contains author name');
    assert_true(str_contains($html, 'Chief Architect, CloudScale Inc'), 'Contains author title and company');
    assert_true(str_contains($html, 'The performance and extensibility are unrivaled.'), 'Contains testimonial quote');
    assert_true(str_contains($html, '★★★★★'), 'Contains 5 star rating');
    assert_true(str_contains($html, 'src="https://assets.example.test/marcus.jpg"'), 'Contains avatar URL');

    // Migration test
    $migratedProps = $widget->migrate(1, 2, ['author' => 'Marcus']);
    assert_true($migratedProps['is_verified'] ?? false, 'Plugin migration added is_verified');

    WidgetSdk::reset();
});
