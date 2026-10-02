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
