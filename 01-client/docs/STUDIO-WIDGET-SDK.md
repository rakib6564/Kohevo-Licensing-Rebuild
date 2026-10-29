# Kohevo Studio — Developer Widget SDK Guide

This guide documents the Developer Widget SDK for creating, versioning, and distributing custom widgets for Kohevo Studio Builder (Sprint 8 / Section 31, 32, 64).

---

## 1. Overview

Kohevo Studio Builder provides an open, decoupled Developer Widget SDK that allows plugin developers to create and register custom visual blocks without modifying any core Studio Builder files.

Custom widgets integrate seamlessly with:
- **BlockRegistry**: Schema validation, prop normalization, and dependency tracking.
- **BlockRendererRegistry**: Server-side HTML compilation and dynamic resolution.
- **WidgetRegistry**: Visual editor palette and property inspector projection.
- **WidgetMigrator**: Version upgrades from v1 to v2 without breaking saved documents.

---

## 2. Quickstart Registration

In your plugin's `boot()` method:

```php
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

class MyCustomWidgetPlugin extends Plugin
{
    public function boot(): void
    {
        Studio::widgets()->register([
            'type'        => 'my_vendor.callout',
            'version'     => 1,
            'label'       => 'Callout Box',
            'category'    => 'content',
            'icon'        => 'alert-circle',
            'schema'      => [
                ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'default' => 'Note'],
                ['key' => 'message', 'type' => 'text', 'label' => 'Message', 'required' => true, 'default' => 'Important details...'],
                ['key' => 'tone', 'type' => 'enum', 'label' => 'Tone', 'allowed_values' => ['info', 'warning', 'success'], 'default' => 'info'],
            ],
            'renderer'    => [self::class, 'renderCallout'],
            'migration'   => [self::class, 'migrateCallout'],
        ]);
    }

    public static function renderCallout(BlockRenderScope $scope): string
    {
        $title   = $scope->string('title');
        $message = $scope->string('message');
        $tone    = $scope->string('tone', 'info');

        return '<div class="my-callout my-callout--' . Html::e($tone) . '">'
            . '<h4 class="my-callout__title">' . Html::e($title) . '</h4>'
            . '<p class="my-callout__message">' . Html::e($message) . '</p>'
            . '</div>';
    }

    public static function migrateCallout(int $fromVersion, int $toVersion, array $props): array
    {
        if ($fromVersion === 1 && $toVersion === 2) {
            $props['is_dismissible'] = false;
        }
        return $props;
    }
}
```

---

## 3. Widget Contract & Fields

Every custom widget defines:

| Field | Type | Description |
|---|---|---|
| `type` / `key` | `string` | Namespaced identifier formatted as `vendor.widget_name`. |
| `version` | `int` | Schema version integer (`>= 1`). |
| `label` | `string` | Human-readable label displayed in the visual editor palette. |
| `category` | `string` | Grouping key in the widget library (`layout`, `content`, `media`, `marketing`, `custom`). |
| `icon` | `string` | Identifier for the palette icon. |
| `schema` | `array` / `FieldSchema` | Declarative typed schema defining allowable props. |
| `renderer` | `callable` / `BlockRendererInterface` | Server renderer that compiles the block into semantic HTML. |
| `migration` | `?callable` | Version transformation hook `fn(int $from, int $to, array $props): array`. |
| `allows_children` | `bool` | Whether this widget can nest child blocks (default `false`). |
| `default_props` | `array` | Initial properties when dragged onto the canvas. |

---

## 4. Property Schemas (`FieldSchema`)

The `FieldSchema` enforces strict, fail-closed validation on all saved properties.

Supported property types:
- `string`: Single-line text (supports `max_length`, `min_length`).
- `text`: Multi-line text.
- `rich_text`: Sanitized HTML content.
- `number`: Numeric value (supports `min`, `max`, `integer_only`).
- `boolean`: True/false toggle.
- `enum`: Restricted choice from `allowed_values`.
- `url`: Safe URL (enforces `http`, `https`, or relative paths; rejects dangerous schemes `javascript:`, `data:`, `vbscript:`).
- `media_ref`: Reference to managed tenant media library item.
- `token_ref`: Reference to a design token (`color.*`, `font.*`, etc.).
- `link`: Structured hyperlink object with URL and target.
- `repeater`: Nested repeatable list of items with an `item_schema`.
- `object`: Nested associative object with a `properties` schema.

---

## 5. Server-Side Rendering (`BlockRenderScope`)

Custom renderers receive an immutable, read-only `BlockRenderScope` instance:

```php
public function render(BlockRenderScope $scope): string
```

Helper methods on `BlockRenderScope`:
- `$scope->string(string $key, string $default = ''): string` — Type-safe string getter.
- `$scope->bool(string $key, bool $default = false): bool` — Type-safe boolean getter.
- `$scope->prop(string $key, mixed $default = null): mixed` — Raw property getter.
- `$scope->childrenHtml(): string` — Pre-rendered HTML of nested child blocks.
- `$scope->image(mixed $mediaRef): ?ResolvedMedia` — Resolves tenant-isolated image assets.
- `$scope->showsDiagnostics(): bool` — True when in editor/preview mode; false in public output.

### Rendering Best Practices:
1. Always escape authored text and attributes with `Html::e($str)` or `Html::classAttr($classes)`.
2. Never execute raw SQL queries inside the renderer.
3. Never switch tenant contexts or access global session state.
4. Renderers run in an isolated sandbox — if a third-party renderer throws, it fails closed to an empty string or error placeholder, never taking down the host page.

---

## 6. Widget Versioning & Migrations

When releasing an updated version of a widget with new or modified properties:

1. Increment the widget `version` (e.g. `1` -> `2`).
2. Provide a `migration` callback:

```php
'migration' => function (int $fromVersion, int $toVersion, array $props): array {
    if ($fromVersion === 1 && $toVersion === 2) {
        $props['badge_text'] = 'New';
        unset($props['deprecated_setting']);
    }
    return $props;
}
```

3. Call `Studio::migrate($document)` or `WidgetSdk::instance()->migrate($block)` to upgrade documents during revision load, import, or publish.

---

## 7. Security & Isolation Model

Per Section 32 of the Master Architecture:
- **Core Namespace Protection**: Third-party extensions cannot register blocks using reserved namespaces (`core.*`, `layout.*`, `theme.*`, `system.*`, `slate.*`).
- **No Database Escalation**: Renderers do not gain write or admin database access.
- **Tenant Scope Enforcement**: All asset resolution and data access remain strictly isolated to the current tenant.
- **Fail-Closed Execution**: Render failures are logged securely and isolated from the rest of the document.

---

## 8. Kohevo module widgets (`studio-widgets.php`)

A Kohevo module plugin (Booking, Membership, Forms, and later Shop) offers its widgets in Add › Components by shipping `plugins/<slug>/studio-widgets.php`. Studio's `PluginWidgetCatalog` reads that file for **every installed plugin, active or not**, so a page that holds a booking block still opens, validates and saves when Booking is switched off. Whether a widget can be inserted and whether it renders is decided by its entitlement: `EntitlementService::canAccess()` needs the plugin active **and** licensed, so an inactive or unlicensed plugin's widgets are listed as locked and render nothing for visitors.

The file returns plain objects (no hook, no boot order):

```php
<?php
declare(strict_types=1);

if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }

require_once __DIR__ . '/studio/ShopProductCardRenderer.php';

return [
    'blocks'    => [ /* DeclarativeBlockDefinition, category 'business', requiredEntitlement '<module>' */ ],
    'renderers' => [ /* BlockRendererInterface, one per block type */ ],
    'providers' => [ /* DataProviderInterface for the data the blocks bind to (tenant-scoped, entitlement-gated) */ ],
];
```

Rules:
- Use the plugin's own namespace for types (`booking.*`, `membership.*`, `forms.*`, `shop.*`). A type Studio or another plugin already registered is ignored.
- Give every block `category: 'business'` and `requiredEntitlement` set to the plugin's `commercial_module.entitlement`.
- A file that throws or returns the wrong shape is logged through `StudioLog` and skipped; Studio keeps working.
- Add the block's French title and description (`studio_block_<type>_title` / `_desc`) and any new field labels (`studio_field_*`) to `plugins/studio-builder/lang/fr.php`, then regenerate `ui/tests/fixtures/manifest.json`.

See `plugins/booking/studio-widgets.php` and `plugins/membership/studio-widgets.php` for working examples, and `plugins/shop/studio-widgets.php` for the keys below.

### Style parts (`parts`)

A plugin's widgets get Style › Parts like Studio's own: `'parts' => ['shop.products' => ['title' => ['label' => 'Title', 'selectors' => ['.sb-shop-card__title']]]]`. Only the plugin's own block types may be named; selectors are class names, attribute tests and combinators (Studio scopes them to the block, so a part can only reach its own block); `'states' => false` marks a pseudo-element. Studio's own map (`BlockParts`) wins for a type both name. Add each part label's French text as a field label (`studio_field_*`).

### Content types a plugin keeps itself (`singles`)

`'singles' => [new MyItemSingle()]`, each a `Registry\PluginSingleInterface`: a content type key (`product`), its name, the entitlement, an address prefix, `find(tenant, slug)` for one published item and `sitemap(tenant)`. Studio then serves `/{prefix}/{slug}` with the published Single template whose display conditions include `singular` = that type (a template that only says "entire site" is not used), with the item in the render context as `single_item` (`$scope->context()->attribute('single_item')`; the plugin's own data in its `data` key). The item's title, description and image become the page's title, description, canonical and social image; the type is offered in the display-conditions dialog; the items are listed in the sitemap. An inactive or unlicensed plugin, an unpublished item or no matching template is the ordinary 404.

### Per-visitor content on cached pages (fragments)

Studio's public pages are cached for everyone and carry no session, so a widget that shows something of one visitor (a cart) renders a placeholder with `data-sb-fragment="<same-origin URL>"` and a plain link inside it. studio-runtime.js fetches that URL with the visitor's cookies (`X-Studio-Fragment: 1`) and puts the HTML it answers in the placeholder; it is markup only, never executed. `data-sb-fragment-query="order,key"` passes on those parameters of the page's own address; `data-sb-fragment-group` names fragments that refresh together. A `<form method="post">` inside a fragment, or one marked `data-sb-fragment-form`, is sent in place: the endpoint answers JSON with `html` (the fragment's new content), `message` (shown in the form's `[data-sb-fragment-message]`) and/or `redirect` (this site, or any https address such as Stripe Checkout). The endpoint is the plugin's own (`plugins/<slug>/public/*.php`, `slate_public_entry()` first, `Cache-Control: private, no-store`), and it does its own CSRF check for the forms it renders.
