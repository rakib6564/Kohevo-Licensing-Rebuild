# Slate Plugin API — v1

This document is the **contract** every Slate plugin follows. It defines
what files a plugin must contain, what the bootstrap class must do, how
the database is owned, how plugins talk to each other, and what's banned.

Plugins that violate this contract will be rejected at upload time or
will misbehave at runtime in ways that aren't worth debugging. Read this
before writing any plugin code.

---

## 1. Anatomy of a plugin

Every plugin is a directory under `/plugins/<slug>/` with this layout:

```
plugins/<slug>/
├── plugin.json           ← required: manifest
├── install.sql           ← required: CREATE TABLE statements
├── uninstall.sql         ← required: DROP TABLE statements
├── <ClassName>.php       ← required: bootstrap, extends Slate\Plugin
├── admin/                ← optional: admin pages
├── customer/             ← optional: customer-facing pages
├── api/                  ← optional: JSON endpoints
├── includes/             ← optional: plugin-private classes
├── templates/            ← optional: email + page templates
└── assets/               ← optional: CSS, JS, images
    ├── css/
    ├── js/
    └── img/
```

The `<slug>` directory name must match `plugin.json`'s `slug` field
exactly. The `<ClassName>.php` filename is PascalCase derived from slug:
`hello-world` → `HelloWorld.php`, `contact-form` → `ContactForm.php`.

---

## 2. `plugin.json` — the manifest

```json
{
  "slug": "hello-world",
  "name": "Hello World",
  "version": "1.0.0",
  "description": "A sample plugin that proves the loader works.",
  "author": "Slate Team",
  "author_url": "https://example.com",
  "requires_core": ">=1.0.0",
  "works_better_with": ["passes"],
  "permissions": [
    "hello.view",
    "hello.manage"
  ]
}
```

**Required fields:**

| Field | Type | Notes |
|---|---|---|
| `slug` | string | `^[a-z][a-z0-9-]{2,63}$` — lowercase, digits, hyphens; starts with letter; 3–64 chars |
| `name` | string | Human-readable, shown in admin |
| `version` | string | Semver `MAJOR.MINOR.PATCH` |
| `description` | string | One-line summary |
| `author` | string | Author or organization name |
| `requires_core` | string | Semver range — minimum Slate core version |

**Optional fields:**

| Field | Type | Notes |
|---|---|---|
| `author_url` | string | URL — author's site |
| `works_better_with` | string[] | Soft hints — slugs of plugins that enhance this one. Not enforced. |
| `permissions` | string[] | Permission keys this plugin defines. Auto-registered to `role_permissions` on activate. Format: `slug.action` |

**Banned fields:** anything not in the table above. The loader rejects
manifests with unknown top-level keys to prevent typos becoming silent
no-ops.

---

## 3. The bootstrap class

Every plugin extends `Slate\Plugin`. The file is named after the slug
in PascalCase and lives at the plugin root.

```php
<?php
// plugins/hello-world/HelloWorld.php

use Slate\Plugin;
use Slate\Hook;

class HelloWorld extends Plugin {

    /**
     * Called once per request when the plugin is active.
     * Register hooks, nav items, and routes here. Do NOT do heavy work.
     */
    public function boot(): void {
        Hook::addFilter('admin_nav_items', [$this, 'addNavItem']);
        Hook::addFilter('customer_nav_items', [$this, 'addCustomerTab']);
    }

    public function addNavItem(array $items): array {
        $items[] = [
            'slug'  => 'hello-world',
            'label' => 'Hello World',
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'star',
            'perm'  => 'hello.view',
        ];
        return $items;
    }

    public function addCustomerTab(array $tabs): array {
        // ...
        return $tabs;
    }
}
```

### What `boot()` may do

- Call `Hook::addFilter()` / `Hook::addAction()` to register listeners
- Call `$this->enqueueStyle('main.css')` to queue a CSS file (lazy)
- Call `$this->enqueueScript('app.js')` to queue a JS file (lazy)
- Read settings via `Database::setting()`
- Cache stuff in `$this->` properties

### What `boot()` must NOT do

- Run DB queries that scan rows (defer to actual request handling)
- Emit any HTML or headers
- `require` files outside the plugin's own directory
- Touch other plugins' tables directly (use their public API class)
- `exit` or `die` for any reason
- Throw uncaught exceptions (the loader will catch and log, but the
  plugin is auto-deactivated on repeated failures)

---

## 4. Database ownership

**A plugin owns its own tables. No other plugin (and no core code) may
read or write them directly.**

### Naming

Plugin tables are prefixed with the plugin slug, hyphens to underscores:

- `hello-world` plugin → tables `helloworld_messages`, `helloworld_logs`
- `booking` plugin → tables `booking_appointments`, `booking_staff`
- `products` plugin → tables `products_items`, `products_orders`

This is **enforced at upload time**: the loader scans `install.sql` and
rejects `CREATE TABLE` statements whose name doesn't start with the
plugin's table prefix.

### `install.sql`

Idempotent `CREATE TABLE IF NOT EXISTS` only. Runs at activate.

```sql
CREATE TABLE IF NOT EXISTS `helloworld_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `uninstall.sql`

`DROP TABLE IF EXISTS` for every table the plugin owns. Runs at uninstall.

```sql
DROP TABLE IF EXISTS `helloworld_messages`;
```

**No `DROP DATABASE`. No `TRUNCATE` of core tables. No `ALTER` on core
tables.** The loader checks for these patterns and rejects the SQL.

---

## 5. Cross-plugin communication

Plugins talk to each other through **runtime detection**, not declared
dependencies. Three patterns, in increasing isolation:

### 5a. Public API class (preferred)

Every plugin that other plugins might want to talk to exposes a class
named `<Slug>API` at the plugin root. The class has only static methods
and only takes/returns primitives or arrays.

```php
// plugins/passes/PassesAPI.php
class PassesAPI {
    public static function listAvailableForCustomer(int $customerId): array { ... }
    public static function isAvailable(int $passId): bool { ... }
    public static function getInsuranceFee(int $passId): float { ... }
}

// In another plugin (e.g. membership):
if (class_exists('PassesAPI')) {
    $passes = PassesAPI::listAvailableForCustomer($customerId);
    // ...
}
```

`class_exists` is the dependency check. If Passes is inactive, the class
doesn't exist, and the calling plugin gracefully skips the feature.

### 5b. Hooks (for behavior modification)

Plugins emit hooks at extension points; other plugins listen.

```php
// In Booking plugin, before computing price:
$price = Hook::applyFilters('booking.price', $price, ['service_id' => $serviceId]);

// In Membership plugin, on boot:
Hook::addFilter('booking.price', function($price, $ctx) {
    // apply member discount
    return $price * 0.9;
}, 20);
```

### 5c. Direct DB queries on another plugin's tables — BANNED

Never. If you need data from another plugin's tables, that plugin must
expose an API method. If it doesn't, file a feature request.

This is the contract that makes uninstalling a plugin safe.

---

## 6. URLs and routing

Plugins don't have routes in the framework sense. They have PHP files
under `admin/`, `customer/`, and `api/` that are reached via standard
URLs:

- `/plugins/<slug>/admin/<page>.php` — admin pages
- `/plugins/<slug>/customer/<page>.php` — customer pages
- `/plugins/<slug>/api/<endpoint>.php` — JSON endpoints

The `admin/<page>.php` files must start with:

```php
<?php
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('hello.view');
```

The loader **does not auto-route** anything. URLs map to filesystem
paths. This is intentional: simpler, debuggable, works on any shared
host, no rewrite rules required.

A plugin can register a friendlier URL by adding an `.htaccess` rewrite
in its own directory, but only its own.

---

## 7. Assets

CSS and JS are queued, not auto-loaded:

```php
public function boot(): void {
    if ($this->isOnPluginAdminPage()) {
        $this->enqueueStyle('main.css');
        $this->enqueueScript('app.js');
    }
}
```

`enqueueStyle('main.css')` resolves to `/plugins/<slug>/assets/css/main.css`.
The admin/customer layout emits a `<link>` for every queued style and a
`<script>` for every queued script.

A plugin loading 200KB of CSS on every admin page when its admin page is
nowhere near is a bug. Use route guards.

---

## 8. Permissions

A plugin declares its permissions in `plugin.json`'s `permissions[]`
array. On activate, those rows are written to `role_permissions` and
become available to the Roles admin page.

Code uses them via `Auth::can('hello.manage')` or
`Auth::requirePerm('hello.manage')` for page-level gating.

Permissions are not auto-granted to any role. The Super Admin role gets
everything via short-circuit in `Auth::can()`. Other roles must be
granted explicitly via the Roles admin page after activate.

---

## 9. i18n

Strings are wrapped in `__('snake_case_key', 'English fallback')` and
optionally translated via `plugins/<slug>/lang/<locale>.php`. The
loader registers each plugin's `lang/` path automatically via the
`i18n_lang_paths` hook.

---

## 10. Settings

A plugin may store its own settings in the core `settings` table by
using a `<slug>.` prefix:

```php
Database::setting('hello.greeting');           // read
Database::setSetting('hello.greeting', 'Hi'); // write
```

This is by convention, not enforced. Don't write to keys outside your
namespace.

---

## 11. The plugin lifecycle

```
┌──────────┐   upload    ┌──────────┐  activate  ┌────────┐
│  (none)  │ ──────────▶ │ installed│ ─────────▶ │ active │
└──────────┘             └──────────┘            └────────┘
                              ▲                       │
                              │                       │ deactivate
                              │                       ▼
                              │                  ┌────────┐
                              │   uninstall ◀────│inactive│
                              └──────────────────└────────┘
```

| State | Means | What runs |
|---|---|---|
| `installed` | Files extracted to `plugins/<slug>/`, manifest validated, row in `plugins` table with status `installed` | Nothing. Plugin is dormant. |
| `active` | `install.sql` has run; permissions registered; loader boots this plugin every request | `boot()` once per request; hooks fire when relevant |
| `inactive` | Plugin was active, then deactivated. `install.sql` is NOT rolled back; data is preserved | Nothing. Plugin is dormant; data stays for reactivation. |
| (deleted) | `uninstall.sql` ran, files removed, row deleted | — |

**`activate` is rerunnable.** Activating an already-active plugin is a
no-op. Activating an `installed` or `inactive` plugin moves it to
`active`. The `install.sql` always uses `IF NOT EXISTS`, so reruns are
safe.

**`uninstall` requires `inactive` first.** You can't uninstall an active
plugin. Deactivate, then uninstall.

---

## 12. What's banned

Auto-fail-at-upload:

- `plugin.json` missing or invalid JSON
- `plugin.json` slug doesn't match folder name
- `plugin.json` has unknown top-level keys (typo guard)
- Required fields missing
- ZIP contains files outside its slug folder (path traversal)
- ZIP contains files with `..` in path
- `install.sql` contains `CREATE TABLE` for a name not matching the
  plugin's table prefix
- `install.sql` or `uninstall.sql` contains `DROP DATABASE`, `ALTER`
  on a non-plugin table, or `TRUNCATE` of a core table

Auto-fail-at-runtime (silent or logged):

- Plugin's bootstrap throws an uncaught exception → logged, plugin is
  not loaded for the rest of the request
- Plugin's bootstrap takes more than 250ms → logged warning
- Plugin enqueues an asset that doesn't exist → silent skip + log

---

## 13. The `Slate\Plugin` base class

Every plugin extends `Slate\Plugin`. The base class provides:

| Method | Returns | Notes |
|---|---|---|
| `boot()` | void | Override in your plugin. Required. |
| `$this->slug()` | string | Plugin slug from manifest |
| `$this->version()` | string | Plugin version from manifest |
| `$this->url(string $path = '')` | string | Resolves to `/plugins/<slug>/<path>` |
| `$this->dir(string $path = '')` | string | Resolves to absolute filesystem path inside the plugin |
| `$this->setting(string $key, $default = null)` | mixed | Reads `<slug>.<key>` from the settings table |
| `$this->setSetting(string $key, $value)` | void | Writes `<slug>.<key>` to the settings table |
| `$this->enqueueStyle(string $relPath)` | void | Queues a CSS file for this request |
| `$this->enqueueScript(string $relPath)` | void | Queues a JS file for this request |

---

## 14. Versioning and core compatibility

`requires_core` in `plugin.json` is a semver range against the core
`SLATE_VERSION` constant. The loader refuses to activate a plugin if
`SLATE_VERSION` doesn't satisfy the range.

Plugin versions move independently of core. Plugin upgrades go through
the same upload flow — uploading a new ZIP for a plugin that's already
installed replaces the files, runs `install.sql` (idempotent), and
keeps the data.

---

## 15. Design system — CSS classes plugins should use

Plugins inherit Slate's dark-sidebar / light-canvas design system.
Off-white canvas (`--bg`), white cards on 1px hairline borders (no
shadow), blue accent (`--accent` = `#2563EB`) reserved for primary
actions and active states. DM Sans body, Syne display headings,
DM Mono code — loaded automatically via Google Fonts from
`ui_components.php`.

Don't invent parallel CSS. The following classes are stable and
safe to use in plugin templates:

**Cards**
- `.card` — base surface: white, 1px hairline border, 12px radius, no shadow
- `.card-header` — flex row inside a card for title + actions
- `.card-link` — make a whole card a clickable link (hover: stronger border)

**Page header**
- `.page-header` — page title + subtitle + actions row (H1 uses Syne)
- `.page-header-sub` — the small grey subtitle under the H1

**Page layout (right-rail variant)**
- `slate_page_layout('with-aside')` opens a CSS grid: main column +
  320px right rail. Pair with `slate_page_layout_end()`.
- Inside, wrap primary content in `<div class="page-main">` and
  metadata in `<aside class="page-aside">`.
- `.aside-card` is the rail-tuned card variant; `.aside-card-title`
  is its uppercase 11px label.
- See §15a below for a worked example.

**KV list (label/value pairs with hairline dividers)**
- `.kv-list` (wrapper, `<ul>`) — flex column, no bullets
- `.kv-row` — one row containing `.kv-label` + `.kv-value`, separated
  from the next row by a bottom hairline
- Add `.kv-mono` to a `.kv-value` for fixed-width numbers/IDs

**Audit trail (vertical timeline)**
- `.audit-trail` (wrapper) — list with a left rail and dot markers
- `.audit-trail-item` — one event; inside, use `.audit-trail-action`,
  `.audit-trail-meta`, `.audit-trail-detail` for the labelled lines
- Add `.is-muted` on an item to render its dot as a faint outline
  rather than blue (use for past/inactive events)

**Stats**
- `.stat-grid` — auto-fitting grid of stat tiles
- `.stat` / `.stat-label` / `.stat-value` (Syne) / `.stat-sub`

**Buttons**
- `.btn` — default: white background, hairline border, ink text
- `.btn-primary` — solid blue (`--accent`), white text
- `.btn-danger` — outline red on transparent
- `.btn-ghost` — transparent, hover fills with `--surface-2`
- `.btn-link` — inline text-style button
- Sizes: `.btn-sm` · `.btn-lg` · `.btn-block`

**Forms**
- `.field` (wraps each input)
- `.field-label` (the label, 12px)
- `.field-required` (red asterisk span)
- `.field-hint` (small grey help below input)
- `.field-error` (small red error below input)
- `.field-row.field-row-2` / `.field-row-3` — multi-column layout
- Inputs: 1px border (`--border-strong`), 8px radius, blue focus ring

**Tables**
- `.table-wrap` — wraps `.table` for horizontal scroll on mobile
- `.table` — striped/hovered rows
- Prefer the data-row pattern (§16) for record lists; reserve tables
  for fixed-column data (rate tables, schedules, etc.)

**Alerts**
- `.alert.alert-success` · `.alert-error` · `.alert-warning` · `.alert-info`

**Badges**
- `.badge` + modifier: `.badge-active`, `.badge-inactive`,
  `.badge-installed`, `.badge-warning`, `.badge-danger`, `.badge-accent`

**Empty states**
- `.empty` (wrapper) · `.empty-icon` · `.empty-title`

**Section divider**
- `.section-divider` — 1px hairline rule used between row groups
  inside detail panels

**Layout utilities** (sparingly)
- `.flex` · `.flex-col` · `.flex-between` · `.items-center`
- `.gap-1` through `.gap-4`
- `.mt-{1,2,3,4,6}` · `.mb-{1,2,3,4,6}`
- `.text-muted` · `.text-sub` · `.text-danger` · `.text-success`
- `.text-sm` · `.text-xs` · `.text-right` · `.text-center`
- `.hide-mobile` (hidden < 768px) · `.show-mobile` (hidden ≥ 768px)

**Design tokens** (CSS variables — use these for any custom CSS)

Canvas & surfaces
- `--bg` — off-white page canvas (`#F5F4F1`)
- `--surface` — card / panel white (`#FFFFFF`)
- `--surface-2` — subtle panel (`#FAFAF7`)
- `--card` — alias for `--surface` (kept for back-compat)

Sidebar (dark) — only meaningful inside the shell sidebar
- `--sidebar-bg` (`#0E1117`), `--sidebar-border`, `--sidebar-hover`,
  `--sidebar-active`, `--sidebar-text`, `--sidebar-muted`, `--sidebar-subtle`

Ink ladder (on light surfaces)
- `--text` · `--text-2` · `--muted` · `--subtle` · `--faint`

Lines
- `--border` (8% black) · `--border-strong` (13%) · `--border-stronger` (20%)

Accent (blue)
- `--accent` (`#2563EB`) · `--accent-deep` (`#1D4ED8`) ·
  `--accent-soft` (`#EFF6FF`) · `--ring`

Semantic
- `--success`/`-soft` · `--warning`/`-soft` · `--danger`/`-soft` · `--info`/`-soft`

Radii — `--radius-sm` 6px, `--radius` 8px, `--radius-lg` 12px,
`--radius-xl` 16px, `--radius-2xl` 20px

Shadows — `--shadow-xs` (none), `--shadow-sm`, `--shadow`,
`--shadow-md`, `--shadow-lg`. The default look uses borders, not
shadows; shadows are reserved for floating overlays (sheets, modals).

Typography
- `--font-sans` — DM Sans
- `--font-display` — Syne (h1, h2, stat values, page header)
- `--font-mono` — DM Mono

Spacing — `--space-1` (4px) through `--space-12` (48px)

Layout
- `--sidebar-width` (232px) · `--topbar-height` (56px) ·
  `--tabbar-height` (58px) · `--content-max` (1280px) ·
  `--right-rail-width` (320px)

### 15a. Right-rail layout — worked example

Detail pages with metadata (orders, submissions, settings, etc.)
use the right-rail layout. It's a CSS grid that puts a 320px aside
next to the primary column at ≥1024px and stacks on smaller
viewports.

```php
require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1>Order #ORD-00042</h1>
        <p class="page-header-sub">Placed 3 May 2026 · Paid</p>
    </div>
</div>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">
        <div class="card">
            <!-- order line items, payment, fulfilment, etc. -->
        </div>
    </div>

    <aside class="page-aside">

        <div class="aside-card">
            <div class="aside-card-title">Submission Info</div>
            <ul class="kv-list">
                <li class="kv-row">
                    <span class="kv-label">Status</span>
                    <span class="kv-value"><span class="badge badge-active">Paid</span></span>
                </li>
                <li class="kv-row">
                    <span class="kv-label">Customer</span>
                    <span class="kv-value">Mariko Osborne</span>
                </li>
                <li class="kv-row">
                    <span class="kv-label">Total</span>
                    <span class="kv-value kv-mono">USD 124.00</span>
                </li>
            </ul>
        </div>

        <div class="aside-card">
            <div class="aside-card-title">Audit Trail</div>
            <ol class="audit-trail">
                <li class="audit-trail-item">
                    <div class="audit-trail-action">Order paid</div>
                    <div class="audit-trail-meta">via Stripe · 3 May 2026 14:22</div>
                </li>
                <li class="audit-trail-item is-muted">
                    <div class="audit-trail-action">Order created</div>
                    <div class="audit-trail-meta">customer checkout · 3 May 2026 14:20</div>
                </li>
            </ol>
        </div>

    </aside>

<?php slate_page_layout_end(); ?>
```

### 15b. Sidebar nav grouping (`group` field)

Nav items registered via the `admin_nav_items` filter may carry an
optional `group` key. Items with the same group render under a
single uppercase section label in the sidebar.

Core groups are: `overview` (Dashboard), `content` (Forms),
`system` (Users, Roles, Plugins, Settings). Plugin items without a
`group` default to `plugins`. Plugins are free to define their own
groups — `'group' => 'shop'` renders the item under a SHOP section.

The legacy `'parent'` field is **not recognised** and is ignored
silently. Use `'group'` instead.

```php
$items[] = [
    'slug'  => 'shipping-flat-rate',
    'label' => __('shipping_rates', 'Shipping rates'),
    'href'  => $this->url('admin/index.php'),
    'icon'  => 'truck',
    'order' => 520,
    'group' => 'shop',     // renders under the SHOP section label
];
```

Group section order is determined by the lowest `order` value
amongst its members. Items within a group are sorted by `order`.

**Plugin admin page boilerplate**

```php
<?php
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('myplugin.view');

$pageTitle  = 'My Plugin';
$currentNav = 'my-plugin'; // matches plugin's nav slug

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'My Plugin'],
]); ?>

<div class="page-header">
    <h1>My Plugin</h1>
</div>

<div class="card">
    <!-- Your content -->
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
```

The shell handles sidebar nav (desktop), bottom tab bar (mobile),
topbar, breadcrumbs styling, and all responsive behavior. Plugins
should NOT add their own outer layout chrome.

---

## 16. Data lists — the card-row pattern (use this, not tables)

Slate does not use HTML `<table>` for data lists. Tables cause
horizontal scroll on mobile and force every column to be the same
width as the widest cell, which looks broken on phones.

Instead, every list of records is rendered as a **vertical stack of
expandable card-rows**. The collapsed row shows the essentials
(avatar, title, secondary line, optional value pill, status badge).
Tapping expands to reveal a labeled key/value grid with optional
actions at the bottom. One row open at a time by default.

This is provided by two helpers in `includes/ui_components.php`,
loaded automatically by `config.php`:

```php
slate_data_row([
    'avatar'       => 'OK',                // 1-2 chars
    'avatar_color' => 'success',           // accent|success|warning|danger|info|muted
    'title'        => 'OK',
    'meta'         => 'Mariko Osborne · rakib0492@gmail.com',
    'value'        => '$5.00',             // optional pill on right
    'badge'        => ['Active', 'active'],// [label, modifier]
    'detail'       => [
        'Initial'   => '$5.00',
        'Remaining' => '$5.00',
        'Recipient' => 'Mariko Osborne',
        'Email'     => 'rakib0492@gmail.com',
        ['label' => 'Status', 'html' => '<span class="badge badge-active">Active</span>'],
    ],
    'actions' => '<button class="btn btn-danger">Cancel</button>',
]);
```

Wrap rows in a `.data-list`. Add `data-single-open` to enforce one
open at a time (recommended). Always call `slate_data_list_script()`
once on the page so the click handler is bound:

```php
<div class="data-list" data-single-open>
    <?php foreach ($items as $item):
        slate_data_row([ /* ... */ ]);
    endforeach; ?>
</div>
<?php slate_data_list_script(); ?>
```

**Detail entries.** Three shapes are accepted per entry:

- `'Label' => 'string value'` — plain text
- `'Label' => ['label' => 'X', 'value' => 'Y', 'muted' => true]` — muted text
- `'Label' => ['label' => 'X', 'html' => '<raw>HTML</raw>']` — raw HTML
  (badges, status pills, links). HTML is NOT escaped; pass safe markup.

**Avatar colors** map to the standard semantic palette:
- `accent` — blue (the default for "this is the thing")
- `success` — green (active, completed, paid)
- `warning` — amber (pending, expiring, attention)
- `danger`  — red (cancelled, failed, blocked)
- `info`    — blue (informational, neutral state)
- `muted`   — grey (inactive, archived)

**Rows without details.** Omit both `detail` and `actions` to make a
non-expandable row. The chevron disappears and the button is
disabled (no hover, no expand affordance).

**Actions.** The `actions` value is raw HTML, rendered in a flex row
at the bottom of the expanded panel. Use `.btn` classes from the
design system. For destructive actions wrap them in a `<form method="post">`
with CSRF — never use GET for state changes:

```php
'actions' => '<form method="post" style="margin:0">'
           . csrf_field()
           . '<button name="_action" value="cancel" '
           . 'class="btn btn-danger btn-sm">Cancel</button></form>',
```

**Responsive shape.** The component is mobile-first:
- ≥640px: 6+ detail columns, value pill visible
- <640px: 2 detail columns, value pill drops into detail panel
- <380px: 1 detail column

You do not need to write any CSS for this — the design system
handles it. Just pass the data.

---

## 17. Packaging a plugin for distribution

Slate ships with a CLI packager that turns a plugin directory into
the `<slug>-v<version>.zip` artifact uploaded through
`/admin/plugins.php`:

```bash
php bin/package-plugin.php plugins/<slug>
```

By default the ZIP is written next to the plugin directory. To
write it into the standard distribution folder
(`plugins/_dist/`, the place from which `/admin/plugins.php`
serves "Download example plugin"):

```bash
php bin/package-plugin.php plugins/<slug> --dist
```

You can also target an arbitrary directory:

```bash
php bin/package-plugin.php plugins/<slug> --out=/tmp
```

The packager:

- Validates the manifest with `PluginLoader::validateManifest()`
- Confirms the directory name matches the manifest slug
- Confirms `<Slug>.php` (the bootstrap class) is present
- Confirms `install.sql` and `uninstall.sql` are present
- Validates SQL with `PluginLoader::validatePluginSql()` —
  enforces table prefix, blocks banned patterns
- Skips development noise (`.git`, `node_modules`, `vendor`,
  `.DS_Store`, etc) so the resulting ZIP is clean
- Exits non-zero with an explanation if anything fails

If your plugin passes the packager, it will pass installation
through the admin UI.

---

## 18. Examples

The `hello-world` plugin shipped alongside Slate Core is the canonical
example. Read its source before writing your first plugin. It exercises
every contract point in this document.
