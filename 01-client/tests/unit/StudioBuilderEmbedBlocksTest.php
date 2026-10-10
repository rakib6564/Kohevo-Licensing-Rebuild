<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the `forms.embed` and `booking.embed` blocks.
 *
 * Autoloader only, no database: the Forms and Booking plugins are replaced by small stand-ins that keep their
 * tenant scoping contract (and, where a test needs it, deliberately break it, so the provider's own tenant check is
 * what is under test). Everything else is the real pipeline: providers -> binding resolver -> renderer.
 *
 * Verifies: entitlement and binding rules, tenant scoping of the stored id, escaping, that the script-less editor
 * canvas never receives a <script>, <iframe> or <form>, the live form/iframe in Preview and Public, once-per-page
 * helper assets, hostile provider rows, and the editor's pick-list manifest.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Provider\BookingEmbedTargetProvider;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\FormsFormEmbedProvider;
use Slate\Module\StudioBuilder\Provider\PublicDataProviderInterface;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBEB_TENANT = 101;
const SBEB_OTHER_TENANT = 202;

if (!class_exists('FormsAPI', false)) {
    /** Stand-in for the Forms plugin. `getFormById` ignores the tenant on purpose: the provider must not rely on it alone. */
    final class FormsAPI
    {
        public const ASSET_VERSION = '9.9.9';
        public static int $renders = 0;
        /** @var array<int, array<string, mixed>> */
        public static array $forms = [];

        public static function getFormById(int $id): ?array
        {
            return self::$forms[$id] ?? null;
        }

        /** @return list<array{id: int, title: string}> */
        public static function publishedChoices(): array
        {
            $tenant = (int) ($GLOBALS['SLATE_TENANT_OVERRIDE'] ?? 0);
            $out = [];
            foreach (self::$forms as $f) {
                if ((int) $f['tenant_id'] === $tenant && $f['status'] === 'published') {
                    $out[] = ['id' => (int) $f['id'], 'title' => (string) $f['title']];
                }
            }
            return $out;
        }

        public static function renderContentBlock(array $props, array $block = []): string
        {
            self::$renders++;
            return '<div class="cb-form-block forms-public-shell"><form method="post" action="/forms/' . htmlspecialchars((string) $props['formSlug']) . '"><input type="hidden" name="csrf" value="t"></form></div>';
        }
    }
}

if (!class_exists('BookingAPI', false)) {
    /** Stand-in for the Booking plugin (same deliberate looseness: `getService` ignores the tenant). */
    final class BookingAPI
    {
        /** @var array<int, array<string, mixed>> */
        public static array $services = [];

        public static function getService($idOrSlug): ?array
        {
            return self::$services[(int) $idOrSlug] ?? null;
        }

        public static function getActiveServices(): array
        {
            $tenant = (int) ($GLOBALS['SLATE_TENANT_OVERRIDE'] ?? 0);
            return array_values(array_filter(self::$services, static fn(array $s): bool => (int) $s['tenant_id'] === $tenant && !empty($s['is_active'])));
        }

        public static function serviceName(array $s): string { return (string) $s['name']; }
        public static function serviceDescription(array $s): string { return (string) ($s['description'] ?? ''); }
    }
}

/** Seed the stand-ins: tenant 101 owns forms 1 (published), 2 (draft); tenant 202 owns form 3. Same shape for services. */
function sbeb_seed(): void
{
    FormsAPI::$renders = 0;
    FormsAPI::$forms = [
        1 => ['id' => 1, 'tenant_id' => SBEB_TENANT, 'slug' => 'contact-us', 'title' => 'Contact <us>', 'description' => 'Say "hi"', 'status' => 'published', 'submit_label' => 'Send',
              'fields' => [['type' => 'text', 'label' => 'Your <name>', 'required' => true], ['type' => 'hidden', 'label' => 'utm'], ['type' => 'step', 'label' => 'Step 2'], ['type' => 'email', 'label' => 'Email']]],
        2 => ['id' => 2, 'tenant_id' => SBEB_TENANT, 'slug' => 'secret-draft', 'title' => 'Draft form', 'description' => '', 'status' => 'draft', 'fields' => []],
        3 => ['id' => 3, 'tenant_id' => SBEB_OTHER_TENANT, 'slug' => 'other-tenant', 'title' => 'Other Tenant Form', 'description' => '', 'status' => 'published', 'fields' => []],
    ];
    BookingAPI::$services = [
        10 => ['id' => 10, 'tenant_id' => SBEB_TENANT, 'name' => 'Cut <b>&</b> Style', 'description' => 'Full cut', 'duration_min' => 45, 'price_cents' => 5000, 'currency' => 'usd', 'is_active' => 1],
        11 => ['id' => 11, 'tenant_id' => SBEB_TENANT, 'name' => 'Retired service', 'duration_min' => 30, 'price_cents' => 100, 'currency' => 'usd', 'is_active' => 0],
        12 => ['id' => 12, 'tenant_id' => SBEB_OTHER_TENANT, 'name' => 'Other Tenant Service', 'duration_min' => 30, 'price_cents' => 100, 'currency' => 'usd', 'is_active' => 1],
    ];
}

final class _SbebNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

/** A provider that answers `forms.form_embed` with whatever row it was given (hostile-data tests). */
final class _SbebFakeFormProvider implements PublicDataProviderInterface
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}
    public function key(): string { return 'forms.form_embed'; }
    public function parameterSchema(): FieldSchema { return FieldSchema::define([]); }
    public function requiredEntitlement(): ?string { return 'forms'; }
    public function requiredPermission(): string { return StudioPermissions::VIEW; }
    public function maxResults(): int { return 1; }
    public function execute(TenantContext $tenants, array $params): array { return $this->rows; }
}

/** Renderers for the page: a renderer set, the real providers (or a replacement), one shared collector. */
function sbeb_page(?DataProviderRegistry $providers = null): array
{
    if ($providers === null) {
        $providers = new DataProviderRegistry();
        $providers->register(new FormsFormEmbedProvider());
        $providers->register(new BookingEmbedTargetProvider());
    }
    $tenants = new TenantContext();
    $documents = new DocumentRenderer(
        ModuleBlockDefinitions::studioRegistry(),
        BlockRendererRegistry::withStudioRenderers(),
        new _SbebNoMedia(),
        new ProviderBindingResolver($providers, $tenants),
    );
    return ['tenants' => $tenants, 'documents' => $documents, 'theme' => (new ThemeResolver())->resolve('default'), 'collector' => new RenderCollector()];
}

function sbeb_site(): SiteContext
{
    return new SiteContext('https://acme.test', 'Acme');
}

function sbeb_ctx(string $mode, array $entitled = ['forms', 'booking']): RenderContext
{
    $entitlement = static fn(string $m): bool => in_array($m, $entitled, true);
    return match ($mode) {
        'public'  => RenderContext::forPublic(SBEB_TENANT, sbeb_site(), $entitlement),
        'preview' => RenderContext::forPreview(SBEB_TENANT, sbeb_site(), StudioActor::authenticated(3, [StudioPermissions::VIEW]), $entitlement),
        'editor'  => RenderContext::forEditor(SBEB_TENANT, sbeb_site(), StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), $entitlement),
    };
}

/** @return array<string, mixed> */
function sbeb_block(string $type, array $props, array $bindings): array
{
    return [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => $bindings, 'children' => [], 'animation' => [], 'interactions' => [],
    ];
}

function sbeb_form_block(?int $id): array
{
    return sbeb_block('forms.embed', [], ['form' => ['provider' => 'forms.form_embed'] + ($id !== null ? ['params' => ['id' => $id]] : [])]);
}

function sbeb_booking_block(?int $serviceId, array $props = ['min_height' => 560], bool $bound = true): array
{
    return sbeb_block('booking.embed', $props, $bound ? ['service' => ['provider' => 'booking.embed_target'] + ($serviceId !== null ? ['params' => ['id' => $serviceId]] : [])] : []);
}

/** Render one block as tenant 101 in the given mode; pass a $page to share a collector across blocks. */
function sbeb_render(array $block, string $mode, ?array $page = null, array $entitled = ['forms', 'booking']): string
{
    $page ??= sbeb_page();
    sbeb_seed_once();
    return $page['tenants']->runAs(SBEB_TENANT, static fn(): string => $page['documents']->renderBlock($block, sbeb_ctx($mode, $entitled), $page['theme'], $page['collector'], false));
}

function sbeb_seed_once(): void
{
    static $done = false;
    if (!$done) {
        sbeb_seed();
        $done = true;
    }
}

// ── Definitions ──────────────────────────────────────────────────────────────

unit('embed blocks: declared with their module entitlement, one allowlisted provider and a binding slot', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $forms = $registry->get('forms.embed');
    $booking = $registry->get('booking.embed');
    assert_true($forms !== null && $booking !== null, 'both are registered');
    assert_eq('forms', $forms->requiredEntitlement());
    assert_eq('booking', $booking->requiredEntitlement());
    assert_eq(['forms.form_embed'], $forms->allowedBindingProviders());
    assert_eq(['booking.embed_target'], $booking->allowedBindingProviders());
    assert_eq([['provider' => 'forms.form_embed', 'slot' => 'form']], $forms->toEditorManifest()['binding_slots']);
    assert_eq([['provider' => 'booking.embed_target', 'slot' => 'service']], $booking->toEditorManifest()['binding_slots']);
    assert_eq('business', $forms->toEditorManifest()['category']);
    assert_eq('calendar', $booking->toEditorManifest()['icon']);
    assert_eq(560, $booking->toEditorManifest()['default_props']['min_height']);
    $renderers = BlockRendererRegistry::withStudioRenderers();
    assert_true($renderers->get('forms.embed')?->isDynamic() === true && $renderers->get('booking.embed')?->isDynamic() === true, 'both resolve per request, never baked into a compiled page');
    foreach (['forms.form_embed', 'booking.embed_target'] as $key) {
        $reg = \Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory::dataProviders();
        assert_true($reg->has($key) && $reg->get($key) instanceof PublicDataProviderInterface, "{$key} is registered and public-marked");
    }
});

unit('embed blocks: min_height is bounded by the schema', function (): void {
    $schema = ModuleBlockDefinitions::studioRegistry()->get('booking.embed')->schema();
    assert_true($schema->validate(['min_height' => 560])->isValid());
    assert_true(!$schema->validate(['min_height' => 50])->isValid(), 'too small');
    assert_true(!$schema->validate(['min_height' => 99999])->isValid(), 'too large');
    assert_true(!$schema->validate(['min_height' => 'tall'])->isValid(), 'not a number');
});

// ── forms.embed ──────────────────────────────────────────────────────────────

unit('forms.embed (public): the Forms module renders the live form once its stylesheet and script are on the page', function (): void {
    sbeb_seed();
    $page = sbeb_page();
    $first = sbeb_render(sbeb_form_block(1), 'public', $page);
    $second = sbeb_render(sbeb_form_block(1), 'public', $page);
    assert_true(str_contains($first, '<form method="post" action="/forms/contact-us">'), 'the form comes from FormsAPI::renderContentBlock, posting to the Forms route');
    assert_true(str_contains($first, 'href="https://acme.test/plugins/forms/assets/css/public.css?v=9.9.9"') && str_contains($first, 'forms-logic.js?v=9.9.9" defer'), 'its own assets');
    assert_true(!str_contains($second, 'forms-logic.js') && str_contains($second, '<form'), 'assets are emitted once per page');
    assert_eq(2, FormsAPI::$renders);
    assert_true(!str_contains($first, 'Contact'), 'Studio adds no form fields or copy of its own');
});

unit('forms.embed (preview): the live form too', function (): void {
    sbeb_seed();
    $html = sbeb_render(sbeb_form_block(1), 'preview');
    assert_true(str_contains($html, '<form method="post" action="/forms/contact-us">'));
});

unit('forms.embed (editor): a static preview card, never the form, a script or a frame', function (): void {
    sbeb_seed();
    $before = FormsAPI::$renders;
    $html = sbeb_render(sbeb_form_block(1), 'editor');
    assert_eq($before, FormsAPI::$renders, 'the Forms renderer is not called for the script-less canvas');
    assert_true(str_contains($html, 'Contact &lt;us&gt;') && str_contains($html, 'Say &quot;hi&quot;'), 'name and description, escaped');
    assert_true(str_contains($html, '<li>Your &lt;name&gt; *</li>') && str_contains($html, '<li>Email</li>'), 'the visible fields, required marked, escaped');
    assert_true(!str_contains($html, 'utm') && !str_contains($html, 'Step 2'), 'hidden and step fields are not listed');
    foreach (['<script', '<iframe', '<form', '<input', '<link', 'contact-us'] as $forbidden) {
        assert_true(!str_contains($html, $forbidden), "no {$forbidden} in the editor preview");
    }
    assert_true(str_contains($html, 'data-sb-node') && str_contains($html, 'data-sb-type="forms.embed"'), 'it is still a selectable canvas node');
});

unit('forms.embed: a form of another tenant, a draft and an unknown id render nothing (and nothing leaks)', function (): void {
    sbeb_seed();
    foreach ([3 => 'Other Tenant Form', 2 => 'Draft form', 999 => 'x'] as $id => $leak) {
        foreach (['public', 'preview'] as $mode) {
            $html = sbeb_render(sbeb_form_block($id), $mode);
            assert_true(!str_contains($html, '<form') && !str_contains($html, $leak) && !str_contains($html, 'forms-logic'), "form {$id} ({$mode}) renders nothing");
        }
        $editor = sbeb_render(sbeb_form_block($id), 'editor');
        assert_true(str_contains($editor, 'Choose a published form') && !str_contains($editor, $leak), "form {$id} in the editor is a pick-me notice without the other form's data");
    }
    assert_eq(0, FormsAPI::$renders, 'the Forms renderer never ran for a form this tenant may not show');
    assert_eq('', trim(strip_tags(sbeb_render(sbeb_form_block(3), 'public'))), 'public output is empty, no licence or tenant hint');
});

unit('forms.embed: no form picked, or a hand-made binding with a bad id, shows a notice to authors only', function (): void {
    sbeb_seed();
    foreach ([null, 0, -4] as $id) {
        $public = sbeb_render(sbeb_form_block($id), 'public');
        assert_true(!str_contains($public, '<form') && !str_contains($public, 'Choose a published form'), 'public: nothing');
        assert_true(str_contains(sbeb_render(sbeb_form_block($id), 'editor'), 'Choose a published form'), 'editor: tells the author');
    }
    $stringId = sbeb_block('forms.embed', [], ['form' => ['provider' => 'forms.form_embed', 'params' => ['id' => 'contact-us']]]);
    assert_true(!str_contains(sbeb_render($stringId, 'public'), '<form'), 'a slug where an id belongs is refused by the parameter schema');
    $foreignProvider = sbeb_block('forms.embed', [], ['form' => ['provider' => 'forms.form', 'params' => ['slug' => 'contact-us']]]);
    assert_true(!str_contains(sbeb_render($foreignProvider, 'public'), '<form'), 'a provider outside the block allowlist is ignored');
});

unit('forms.embed: without the forms entitlement nothing is rendered or looked up', function (): void {
    sbeb_seed();
    $html = sbeb_render(sbeb_form_block(1), 'public', null, ['booking']);
    assert_eq('', $html);
    assert_eq(0, FormsAPI::$renders);
    assert_true(str_contains(sbeb_render(sbeb_form_block(1), 'editor', null, ['booking']), 'sb-unavailable'), 'the editor says the block is unavailable');
});

unit('forms.embed: a hostile slug from a provider never reaches the Forms renderer or the page', function (): void {
    sbeb_seed();
    $providers = new DataProviderRegistry();
    $providers->register(new _SbebFakeFormProvider([['id' => 1, 'slug' => '"><script>alert(1)</script>', 'title' => 'x', 'description' => '', 'field_labels' => '']]));
    $page = sbeb_page($providers);
    $block = sbeb_block('forms.embed', [], ['form' => ['provider' => 'forms.form_embed']]);
    $html = sbeb_render($block, 'public', $page);
    assert_true(!str_contains($html, '<script') && !str_contains($html, 'alert'), 'nothing is emitted');
    assert_eq(0, FormsAPI::$renders);
});

unit('forms.form_embed provider: a row of another tenant is refused even if the plugin returned it', function (): void {
    sbeb_seed();
    $tenants = new TenantContext();
    $provider = new FormsFormEmbedProvider();
    assert_eq([], $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 3])), 'form 3 belongs to tenant 202');
    assert_eq([], $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 2])), 'a draft');
    assert_eq([], $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 0])));
    $rows = $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 1]));
    assert_eq(1, count($rows));
    assert_eq('contact-us', $rows[0]['slug']);
    assert_eq("Your <name> *\nEmail", $rows[0]['field_labels']);
    assert_true(!array_key_exists('fields', $rows[0]) && !array_key_exists('tenant_id', $rows[0]), 'only catalogue data leaves the provider');
});

// ── booking.embed ────────────────────────────────────────────────────────────

unit('booking.embed (public): the safe booking iframe for one service, with the helper script once per page', function (): void {
    sbeb_seed();
    $page = sbeb_page();
    $one = sbeb_render(sbeb_booking_block(10), 'public', $page);
    $two = sbeb_render(sbeb_booking_block(10), 'public', $page);
    assert_true(str_contains($one, '<iframe class="sb-embed-frame" src="https://acme.test/book?embed=1&amp;service=10" data-kohevo-booking'), 'the booking route with embed=1 and the service');
    assert_true(str_contains($one, 'loading="lazy"') && str_contains($one, 'style="min-height:560px"'), 'lazy, with the reserved height');
    assert_true(str_contains($one, '<script src="https://acme.test/plugins/booking/assets/js/embed.js" async></script>'), 'the booking plugin\'s own sizing helper');
    assert_true(!str_contains($two, 'embed.js') && str_contains($two, '<iframe'), 'the helper is emitted once');
    assert_true(!str_contains($one, 'Cut') && !str_contains($one, 'sandbox'), 'no service copy of Studio\'s own in the page');
});

unit('booking.embed (public): no service chosen embeds the whole booking page; the height is bounded', function (): void {
    sbeb_seed();
    $whole = sbeb_render(sbeb_booking_block(null), 'public');
    assert_true(str_contains($whole, 'src="https://acme.test/book?embed=1"') && !str_contains($whole, 'service='), 'whole page');
    $unbound = sbeb_render(sbeb_booking_block(null, ['min_height' => 560], false), 'public');
    assert_true(str_contains($unbound, 'src="https://acme.test/book?embed=1"'), 'a block with no binding at all is the whole page too');
    assert_true(str_contains(sbeb_render(sbeb_booking_block(null, ['min_height' => 99999]), 'public'), 'min-height:2400px'), 'clamped high');
    assert_true(str_contains(sbeb_render(sbeb_booking_block(null, ['min_height' => 5]), 'public'), 'min-height:200px'), 'clamped low');
    assert_true(str_contains(sbeb_render(sbeb_booking_block(null, []), 'public'), 'min-height:560px'), 'default');
});

unit('booking.embed (editor): a static preview card, never an iframe or a script', function (): void {
    sbeb_seed();
    $service = sbeb_render(sbeb_booking_block(10), 'editor');
    assert_true(str_contains($service, 'Cut &lt;b&gt;&amp;&lt;/b&gt; Style') && str_contains($service, '45 min') && str_contains($service, '50.00 USD'), 'service name, duration and price, escaped');
    $whole = sbeb_render(sbeb_booking_block(null), 'editor');
    assert_true(str_contains($whole, 'Your booking page'));
    foreach ([$service, $whole] as $html) {
        foreach (['<script', '<iframe', '<form', 'embed.js', '/book'] as $forbidden) {
            assert_true(!str_contains($html, $forbidden), "no {$forbidden} in the editor preview");
        }
        assert_true(str_contains($html, 'data-sb-type="booking.embed"'));
    }
});

unit('booking.embed: a service of another tenant, an inactive one and an unknown id render nothing', function (): void {
    sbeb_seed();
    foreach ([12 => 'Other Tenant Service', 11 => 'Retired service', 999 => 'x'] as $id => $leak) {
        foreach (['public', 'preview'] as $mode) {
            $html = sbeb_render(sbeb_booking_block($id), $mode);
            assert_true(!str_contains($html, '<iframe') && !str_contains($html, 'embed.js') && !str_contains($html, $leak), "service {$id} ({$mode}) renders nothing, not even the whole page");
        }
        $editor = sbeb_render(sbeb_booking_block($id), 'editor');
        assert_true(str_contains($editor, 'no longer available') && !str_contains($editor, $leak), "service {$id}: the author is told, without the other service's data");
    }
});

unit('booking.embed: without the booking entitlement nothing is rendered', function (): void {
    sbeb_seed();
    assert_eq('', sbeb_render(sbeb_booking_block(10), 'public', null, ['forms']));
});

unit('booking.embed_target provider: tenant check holds even if the plugin returns a foreign row', function (): void {
    sbeb_seed();
    $tenants = new TenantContext();
    $provider = new BookingEmbedTargetProvider();
    assert_eq([], $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 12])));
    assert_eq([], $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 11])));
    $row = $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, ['id' => 10]));
    assert_eq([10, 'service'], [$row[0]['id'], $row[0]['scope']]);
    $page = $tenants->runAs(SBEB_TENANT, static fn() => $provider->execute($tenants, []));
    assert_eq([0, 'page'], [$page[0]['id'], $page[0]['scope']]);
});

// ── The editor's pick-lists ──────────────────────────────────────────────────

unit('provider params: the id is a dropdown of THIS tenant\'s published forms and active services', function (): void {
    sbeb_seed();
    $tenants = new TenantContext();
    $forms = $tenants->runAs(SBEB_TENANT, static fn() => StudioApplicationService::providerParams(new FormsFormEmbedProvider()));
    assert_eq('id', $forms[0]['key']);
    assert_true($forms[0]['required'] === true && $forms[0]['type'] === 'number');
    assert_eq([['value' => 1, 'label' => 'Contact <us>']], $forms[0]['choices'], 'no draft, no other tenant');
    $services = $tenants->runAs(SBEB_TENANT, static fn() => StudioApplicationService::providerParams(new BookingEmbedTargetProvider()));
    assert_eq([['value' => 10, 'label' => 'Cut <b>&</b> Style']], $services[0]['choices'], 'no retired service, no other tenant');
    assert_true($services[0]['required'] === false);
    $other = $tenants->runAs(SBEB_OTHER_TENANT, static fn() => StudioApplicationService::providerParams(new FormsFormEmbedProvider()));
    assert_eq([['value' => 3, 'label' => 'Other Tenant Form']], $other[0]['choices']);
    $plain = StudioApplicationService::providerParams(new \Slate\Module\StudioBuilder\Provider\BookingServicesProvider());
    assert_eq([], $plain, 'a provider without parameters has none');
    $slug = StudioApplicationService::providerParams(new \Slate\Module\StudioBuilder\Provider\FormsFormProvider());
    assert_true(!array_key_exists('choices', $slug[0]), 'other providers keep their plain parameter');
});

unit('embed copy: French strings exist for the block copy, parameter labels and preview notes', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $keys = [
        'studio_param_forms_form_embed_id', 'studio_param_booking_embed_target_id',
        'studio_embed_form_missing', 'studio_embed_form_kind', 'studio_embed_form_note',
        'studio_embed_service_missing', 'studio_embed_booking_kind', 'studio_embed_booking_page', 'studio_embed_booking_all', 'studio_embed_booking_note',
        'studio_ui_pick_none', 'studio_ui_pick_empty',
    ];
    foreach ($keys as $key) {
        assert_true(isset($fr[$key]) && trim((string) $fr[$key]) !== '', "{$key} has a French entry");
    }
});

unit('forms embed: the site stylesheet defines the variables the Forms stylesheet reads, so the submit button is never transparent', function (): void {
    \Slate\Module\StudioBuilder\Render\StudioStylesheet::resetCache();
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-block--forms-embed{--f-accent:var(--sb-color-accent)'), 'the accent variable is mapped to the site accent');
    foreach (['--f-accent-d', '--f-ink', '--f-muted', '--f-line', '--f-surface', '--f-soft', '--f-ring'] as $variable) {
        assert_true(str_contains($css, $variable . ':'), "{$variable} is defined for the embed");
    }
});
