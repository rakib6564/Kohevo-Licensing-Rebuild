<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — widgets that plugins bring (`studio-widgets.php`).
 *
 * Autoloader only, no database: the Booking and Membership plugins are replaced by small stand-ins that keep
 * their tenant scoping contract. Everything else is the real pipeline: PluginWidgetCatalog -> registries ->
 * providers -> binding resolver -> renderer.
 *
 * Verifies: every module widget is declared by its own plugin with that plugin's entitlement, discovery skips a
 * broken file without taking Studio down, an unlicensed widget is listed locked, and the Service Card, Plan Card
 * and Member Sign-in widgets render escaped, tenant-scoped markup that links into the module's own pages.
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
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Registry\PluginWidgetCatalog;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBPW_TENANT = 101;
const SBPW_OTHER_TENANT = 202;

if (!class_exists('BookingAPI', false)) {
    /** Stand-in for the Booking plugin (same shape as the one in StudioBuilderEmbedBlocksTest). */
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

if (!class_exists('MembershipAPI', false)) {
    /** Stand-in for the Membership plugin: `plans(true)` is tenant-scoped and active-only, like the real one. */
    final class MembershipAPI
    {
        /** @var array<int, array<string, mixed>> */
        public static array $plans = [];

        public static function plans(bool $activeOnly = false): array
        {
            $tenant = (int) ($GLOBALS['SLATE_TENANT_OVERRIDE'] ?? 0);
            return array_values(array_filter(self::$plans, static fn(array $p): bool => (int) $p['tenant_id'] === $tenant && (!$activeOnly || !empty($p['is_active']))));
        }

        public static function sellablePlans(): array
        {
            return array_values(array_filter(self::plans(true), static fn(array $p): bool => ($p['plan_type'] ?? '') !== 'insurance'));
        }

        public static function planName(array $p): string { return (string) $p['name']; }
        public static function planDescription(array $p): string { return (string) ($p['description'] ?? ''); }
    }
}

function sbpw_seed(): void
{
    BookingAPI::$services = [
        10 => ['id' => 10, 'tenant_id' => SBPW_TENANT, 'name' => 'Cut <b>&</b> Style', 'description' => 'Full cut', 'duration_min' => 45, 'price_cents' => 5000, 'currency' => 'usd', 'is_active' => 1],
        11 => ['id' => 11, 'tenant_id' => SBPW_TENANT, 'name' => 'Retired service', 'duration_min' => 30, 'price_cents' => 100, 'currency' => 'usd', 'is_active' => 0],
        12 => ['id' => 12, 'tenant_id' => SBPW_OTHER_TENANT, 'name' => 'Other Tenant Service', 'duration_min' => 30, 'price_cents' => 100, 'currency' => 'usd', 'is_active' => 1],
    ];
    MembershipAPI::$plans = [
        5 => ['id' => 5, 'tenant_id' => SBPW_TENANT, 'name' => 'Gold <plan>', 'description' => 'Unlimited classes', 'plan_type' => 'membership', 'price_cents' => 9900, 'currency' => 'eur', 'is_active' => 1],
        6 => ['id' => 6, 'tenant_id' => SBPW_TENANT, 'name' => 'Insurance', 'plan_type' => 'insurance', 'price_cents' => 1500, 'currency' => 'eur', 'is_active' => 1],
        7 => ['id' => 7, 'tenant_id' => SBPW_TENANT, 'name' => 'Old plan', 'plan_type' => 'membership', 'price_cents' => 100, 'currency' => 'eur', 'is_active' => 0],
        8 => ['id' => 8, 'tenant_id' => SBPW_OTHER_TENANT, 'name' => 'Other Tenant Plan', 'plan_type' => 'membership', 'price_cents' => 100, 'currency' => 'eur', 'is_active' => 1],
    ];
}

final class _SbpwNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

/** @return array<string, mixed> */
function sbpw_block(string $type, array $props, array $bindings = []): array
{
    return [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => ($type === 'core.container' ? 2 : 1), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => $bindings, 'children' => [], 'animation' => [], 'interactions' => [],
    ];
}

function sbpw_bound(string $type, string $slot, string $provider, ?int $id, array $props = []): array
{
    return sbpw_block($type, $props, [$slot => ['provider' => $provider] + ($id !== null ? ['params' => ['id' => $id]] : [])]);
}

/** Render one block as tenant 101 in the given mode. */
function sbpw_render(array $block, string $mode, array $entitled = ['booking', 'membership']): string
{
    sbpw_seed();
    $tenants = new TenantContext();
    $documents = new DocumentRenderer(
        ModuleBlockDefinitions::studioRegistry(),
        BlockRendererRegistry::withStudioRenderers(),
        new _SbpwNoMedia(),
        new ProviderBindingResolver(StudioRuntimeFactory::dataProviders(), $tenants),
    );
    $site = new SiteContext('https://acme.test', 'Acme');
    $entitlement = static fn(string $m): bool => in_array($m, $entitled, true);
    $ctx = match ($mode) {
        'public' => RenderContext::forPublic(SBPW_TENANT, $site, $entitlement),
        'editor' => RenderContext::forEditor(SBPW_TENANT, $site, StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), $entitlement),
    };
    return $tenants->runAs(SBPW_TENANT, static fn(): string => $documents->renderBlock($block, $ctx, (new ThemeResolver())->resolve('default'), new RenderCollector(), false));
}

/** A throwaway plugins folder holding the given `studio-widgets.php` bodies. @param array<string, string> $files */
function sbpw_plugins_dir(array $files): string
{
    $dir = sys_get_temp_dir() . '/sbpw-plugins-' . bin2hex(random_bytes(4));
    foreach ($files as $plugin => $php) {
        mkdir($dir . '/' . $plugin, 0777, true);
        file_put_contents($dir . '/' . $plugin . '/' . PluginWidgetCatalog::FILE, $php);
    }
    return $dir;
}

// ── Discovery ────────────────────────────────────────────────────────────────

unit('plugin widgets: each module plugin declares its own widgets, gated by its own entitlement', function (): void {
    $byType = [];
    foreach (PluginWidgetCatalog::blocks() as $block) {
        $byType[$block->type()] = $block->requiredEntitlement();
    }
    ksort($byType);
    assert_eq([
        'booking.embed' => 'booking', 'booking.service' => 'booking', 'booking.services' => 'booking',
        'forms.embed' => 'forms', 'forms.form_card' => 'forms',
        'membership.member_links' => 'membership', 'membership.plan' => 'membership', 'membership.plans' => 'membership',
        'shop.cart' => 'shop', 'shop.checkout' => 'shop', 'shop.mini_cart' => 'shop', 'shop.order_confirmation' => 'shop',
        'shop.product_add_to_cart' => 'shop', 'shop.product_description' => 'shop', 'shop.product_image' => 'shop',
        'shop.product_meta' => 'shop', 'shop.product_price' => 'shop', 'shop.product_stock' => 'shop',
        'shop.product_title' => 'shop', 'shop.products' => 'shop',
    ], $byType);
    foreach (PluginWidgetCatalog::blocks() as $block) {
        assert_eq('business', $block->category(), $block->type() . ' is a Kohevo component (Add › Components)');
    }
});

unit('plugin widgets: every widget has a renderer, and every provider it binds to is registered', function (): void {
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $providers = StudioRuntimeFactory::dataProviders();
    foreach (PluginWidgetCatalog::blocks() as $block) {
        assert_true($renderers->has($block->type()), $block->type() . ' has a renderer');
        foreach ($block->allowedBindingProviders() as $key) {
            assert_true($providers->has($key), "{$block->type()} binds to a registered provider ({$key})");
        }
    }
    assert_true(ModuleBlockDefinitions::studioRegistry()->has('payment.button'), 'Studio keeps its own payment blocks');
});

unit('plugin widgets: a broken or wrong-shaped file is logged and skipped; the good ones still load', function (): void {
    $good = '<?php return ["blocks" => [new Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition(type: "acme.thing", version: 1, label: "Thing", category: "business", icon: "box", schema: Slate\Module\StudioBuilder\Schema\FieldSchema::define([]))]];';
    $dir = sbpw_plugins_dir([
        'a-good'  => $good,
        'b-throw' => '<?php throw new RuntimeException("boom");',
        'c-shape' => '<?php return ["blocks" => ["not a definition"]];',
        'd-none'  => '<?php return "nothing";',
    ]);
    $logged = [];
    StudioLog::useSink(static function (string $line) use (&$logged): void { $logged[] = $line; });
    try {
        $types = array_map(static fn($b): string => $b->type(), PluginWidgetCatalog::blocks($dir));
    } finally {
        StudioLog::useSink(null);
        PluginWidgetCatalog::reset();
    }
    assert_eq(['acme.thing'], $types);
    assert_eq(3, count($logged), 'one log line per skipped file');
});

unit('plugin widgets: a plugin may declare style parts for its own blocks only, and content types it keeps itself', function (): void {
    $block = static fn(string $type): string => 'new Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition(type: "' . $type . '", version: 1, label: "Thing", category: "business", icon: "box", schema: Slate\Module\StudioBuilder\Schema\FieldSchema::define([]))';
    $single = 'new class implements Slate\Module\StudioBuilder\Registry\PluginSingleInterface {
        public function contentType(): string { return "gadget"; }
        public function label(): string { return "Gadgets"; }
        public function requiredEntitlement(): string { return "acme"; }
        public function prefix(int $t): string { return "gadget"; }
        public function find(int $t, string $s): ?array { return null; }
        public function sitemap(int $t): array { return []; }
    }';
    $dir = sbpw_plugins_dir([
        'a-good'    => '<?php return ["blocks" => [' . $block('acme.thing') . '], "parts" => ["acme.thing" => ["title" => ["label" => "Title", "selectors" => [".acme-thing__title", ".acme-thing a"]], "badge" => ["label" => "Badge", "selectors" => [".acme-badge::before"], "states" => false]]], "singles" => [' . $single . ']];',
        'b-foreign' => '<?php return ["blocks" => [' . $block('beta.thing') . '], "parts" => ["core.text" => ["x" => ["label" => "X", "selectors" => [".sb-text"]]]]];',
        'c-css'     => '<?php return ["blocks" => [' . $block('gamma.thing') . '], "parts" => ["gamma.thing" => ["x" => ["label" => "X", "selectors" => [".a{color:red}"]]]]];',
        'd-type'    => '<?php return ["blocks" => [' . $block('delta.thing') . '], "singles" => [new class implements Slate\Module\StudioBuilder\Registry\PluginSingleInterface {
            public function contentType(): string { return "Bad Type"; } public function label(): string { return ""; } public function requiredEntitlement(): string { return "x"; }
            public function prefix(int $t): string { return "x"; } public function find(int $t, string $s): ?array { return null; } public function sitemap(int $t): array { return []; } }]];',
    ]);
    $logged = [];
    StudioLog::useSink(static function (string $line) use (&$logged): void { $logged[] = $line; });
    try {
        $types = array_map(static fn($b): string => $b->type(), PluginWidgetCatalog::blocks($dir));
        $parts = PluginWidgetCatalog::parts($dir);
        $singles = array_map(static fn($s): string => $s->contentType(), PluginWidgetCatalog::singles($dir));
    } finally {
        StudioLog::useSink(null);
        PluginWidgetCatalog::reset();
    }
    assert_eq(['acme.thing'], $types, 'a file whose parts name another type, carry CSS, or whose content type is not a key is skipped whole');
    assert_eq(['acme.thing'], array_keys($parts));
    assert_eq(['label' => 'Badge', 'selectors' => ['.acme-badge::before'], 'states' => false], $parts['acme.thing']['badge']);
    assert_eq(['gadget'], $singles);
    assert_eq(3, count($logged), 'one log line per skipped file');
});

unit('plugin widgets: Shop\'s parts reach BlockParts and the editor, and its products are a content type Studio serves', function (): void {
    assert_eq(['card', 'image', 'body', 'icon', 'link', 'title', 'price', 'button', 'badge', 'filter', 'pager', 'grid'], array_keys(\Slate\Module\StudioBuilder\Registry\BlockParts::for('shop.products')));
    assert_true(in_array('shop.cart', \Slate\Module\StudioBuilder\Registry\BlockParts::types(), true));
    $manifest = ModuleBlockDefinitions::studioRegistry()->get('shop.product_add_to_cart')->toEditorManifest();
    assert_eq(['button', 'options', 'option_labels', 'variant_price', 'quantity', 'message'], array_column($manifest['style_parts'], 'key'));
    $single = PluginWidgetCatalog::single('product');
    assert_true($single !== null, 'Shop declares products');
    assert_eq('shop', $single->requiredEntitlement());
    assert_eq('Products', $single->label());
    assert_true(PluginWidgetCatalog::single('nothing') === null);
});

unit('plugin widgets: an unlicensed (or switched-off) plugin\'s widgets are listed locked, never dropped', function (): void {
    $manifests = ModuleBlockDefinitions::studioRegistry()->editorManifests(static fn(?string $m): bool => $m === null || $m === 'forms', null, true);
    $locked = [];
    foreach ($manifests as $m) {
        if (!empty($m['locked'])) {
            $locked[$m['type']] = $m['locked_module'];
        }
    }
    assert_eq('booking', $locked['booking.service'] ?? null);
    assert_eq('membership', $locked['membership.plan'] ?? null);
    assert_eq('membership', $locked['membership.member_links'] ?? null);
    assert_true(!isset($locked['forms.embed']), 'a licensed plugin\'s widgets are not locked');
});

// ── booking.service ──────────────────────────────────────────────────────────

unit('booking.service: one service card, escaped, with a Book link onto that service', function (): void {
    $html = sbpw_render(sbpw_bound('booking.service', 'service', 'booking.embed_target', 10), 'public');
    assert_true(str_contains($html, 'Cut &lt;b&gt;&amp;&lt;/b&gt; Style'), 'the name is escaped');
    assert_true(str_contains($html, '45 min') && str_contains($html, '50.00 USD'), 'length and price');
    assert_true(str_contains($html, 'href="https://acme.test/book?service=10"'), 'books that service');
    $plain = sbpw_render(sbpw_bound('booking.service', 'service', 'booking.embed_target', 10, ['show_price' => false, 'show_duration' => false, 'cta_label' => 'Go <now>']), 'public');
    assert_true(!str_contains($plain, '50.00') && !str_contains($plain, '45 min') && str_contains($plain, 'Go &lt;now&gt;'), 'price and length can be hidden; the label is escaped');
});

unit('booking.service: an inactive, foreign or unpicked service renders nothing publicly and a notice to authors', function (): void {
    foreach ([11, 12, null] as $id) {
        $block = sbpw_bound('booking.service', 'service', 'booking.embed_target', $id);
        assert_eq('', trim(strip_tags(sbpw_render($block, 'public'))), 'nothing for visitors (' . var_export($id, true) . ')');
        assert_true(str_contains(sbpw_render($block, 'editor'), 'sb-unavailable'), 'a notice in the editor');
    }
    assert_true(!str_contains(sbpw_render(sbpw_bound('booking.service', 'service', 'booking.embed_target', 10), 'public', ['membership']), 'Cut'), 'nothing without the booking entitlement');
});

// ── membership.plan ──────────────────────────────────────────────────────────

unit('membership.plan: one plan card, escaped, whose Join link opens that plan in the member portal', function (): void {
    $html = sbpw_render(sbpw_bound('membership.plan', 'plan', 'membership.plan', 5), 'public');
    assert_true(str_contains($html, 'Gold &lt;plan&gt;') && str_contains($html, 'Unlimited classes'), 'name and description, escaped');
    assert_true(str_contains($html, '99.00 EUR'), 'price');
    assert_true(str_contains($html, 'href="https://acme.test/member?view=plans&amp;plan=5"'), 'the portal sells, the card links');
});

unit('membership.plan: insurance, an inactive plan and another tenant\'s plan are never shown or offered', function (): void {
    foreach ([6, 7, 8] as $id) {
        assert_eq('', trim(strip_tags(sbpw_render(sbpw_bound('membership.plan', 'plan', 'membership.plan', $id), 'public'))), "plan {$id} renders nothing");
    }
    sbpw_seed();
    $provider = StudioRuntimeFactory::dataProviders()->get('membership.plan');
    $choices = (new TenantContext())->runAs(SBPW_TENANT, static fn(): array => $provider->paramChoices('id'));
    assert_eq([['value' => 5, 'label' => 'Gold <plan>']], $choices, 'the picker lists only this tenant\'s sellable plans');
});

// ── membership.member_links ──────────────────────────────────────────────────

unit('membership.member_links: Sign in and Join into the customer portal; My membership on request; labels escaped', function (): void {
    $html = sbpw_render(sbpw_block('membership.member_links', []), 'public');
    assert_true(str_contains($html, 'href="https://acme.test/customer/login.php?next=%2Fmember%3Fview%3Dplans"'), 'sign in, back to the plans');
    assert_true(str_contains($html, 'href="https://acme.test/customer/register.php?next=%2Fmember%3Fview%3Dplans"'), 'join');
    assert_true(!str_contains($html, 'https://acme.test/member"'), 'My membership is off by default');
    $custom = sbpw_render(sbpw_block('membership.member_links', ['show_join' => false, 'show_account' => true, 'signin_label' => 'Log <in>']), 'public');
    assert_true(!str_contains($custom, 'register.php') && str_contains($custom, 'href="https://acme.test/member"') && str_contains($custom, 'Log &lt;in&gt;'), 'switches and labels apply');
    assert_true(!str_contains(sbpw_render(sbpw_block('membership.member_links', []), 'public', ['booking']), 'login.php'), 'nothing without the membership entitlement');
});
