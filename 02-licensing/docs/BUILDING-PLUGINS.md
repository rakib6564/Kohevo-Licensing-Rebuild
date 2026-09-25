# Building plugins for Slate

A practical guide. You'll build a complete plugin from scratch,
then learn the patterns for extending it. By the end you'll know
how to add admin pages, expose APIs, store data, declare
permissions, hook into core, and ship the result.

This guide complements `PLUGIN-API.md`, which is the contract
reference. Read this one first when you're learning; reach for
PLUGIN-API.md when you need exact field specifications.

---

## Table of contents

1. [What is a Slate plugin?](#1-what-is-a-slate-plugin)
2. [The tutorial — build a Notes plugin](#2-the-tutorial--build-a-notes-plugin)
3. [Recipes — common patterns](#3-recipes--common-patterns)
4. [Communicating with other plugins](#4-communicating-with-other-plugins)
5. [Hooks into the core](#5-hooks-into-the-core)
6. [Settings UI](#6-settings-ui)
7. [Custom permissions](#7-custom-permissions)
8. [Packaging and distribution](#8-packaging-and-distribution)
9. [Versioning your plugin](#9-versioning-your-plugin)
10. [Debugging](#10-debugging)
11. [Customer-facing plugins](#11-customer-facing-plugins)
12. [Taking payments](#12-taking-payments)
13. [What not to do](#13-what-not-to-do)

---

## 1. What is a Slate plugin?

A plugin is a folder containing PHP, optional SQL, and a manifest.
Drop the ZIP into Slate's plugin manager, click Activate, and you
get a self-contained feature — its own admin page, its own tables,
its own permissions, all isolated under one slug.

The contract:

- **Folder name = slug.** Folder named `notes` → slug `notes`. Use
  lowercase, hyphens allowed.
- **Bootstrap file = `<Slug>.php`** with class `<Slug>` extending
  `Plugin`. Slug `notes` → file `Notes.php` → class `Notes`.
- **`plugin.json`** declares the manifest.
- **`install.sql` / `uninstall.sql`** create and drop your tables.
  All tables must be prefixed with the slug-as-table (hyphens
  stripped, lowercase, suffixed with `_`).
- **The slug owns everything**: tables, settings keys, permissions,
  URLs.

Plugins are loaded by `PluginLoader::boot()` on every request that
runs `config.php`. Boot order is alphabetical by slug. Plugins
cannot hard-require other plugins; they discover each other at
runtime via `class_exists()` and `PluginLoader::isActive()`.

---

## 2. The tutorial — build a Notes plugin

We'll build a plugin called **Notes**: a small internal team-notes
tool. It demonstrates every common contract point. Total time:
~30 minutes. Total code: under 300 lines.

### 2.1 Specification

- One DB table for notes (`notes_items`)
- One admin page at `/plugins/notes/admin/index.php` — list + create
- One permission `notes.manage` (only super-admin + holders can use)
- One setting `notes.placeholder` for the default new-note placeholder
- One sidebar nav item with the perm gate
- One dashboard widget showing the count
- A public API class `NotesAPI` so other plugins can read/write
  notes without touching our table directly

### 2.2 Create the folder

```bash
cd /path/to/slate
mkdir -p plugins/notes/admin
cd plugins/notes
```

### 2.3 Write the manifest

`plugins/notes/plugin.json`:

```json
{
    "slug": "notes",
    "name": "Notes",
    "version": "1.0.0",
    "description": "Internal team notes. Quick, searchable, private to your staff.",
    "author": "Your Name",
    "author_url": "https://example.com",
    "requires_core": ">=1.0.0",
    "permissions": [
        {"key": "notes.manage", "label": "Create and view team notes"}
    ]
}
```

The `permissions` array accepts either plain strings or
`{key, label}` objects. The label shows up in the Roles editor
under a group named "Notes (plugin)" once the plugin is installed.

### 2.4 Schema

`plugins/notes/install.sql`:

```sql
-- Notes plugin schema.
-- Tables MUST be prefixed `notes_` (slug + underscore).

CREATE TABLE IF NOT EXISTS `notes_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `user_id`     INT UNSIGNED NOT NULL,
    `title`       VARCHAR(190) NOT NULL,
    `body`        TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_user_time` (`tenant_id`, `user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`plugins/notes/uninstall.sql`:

```sql
DROP TABLE IF EXISTS `notes_items`;
```

The loader validates these files: it rejects `DROP DATABASE`, `GRANT`,
queries against non-prefixed tables, and other dangerous patterns.
See PLUGIN-API.md §12 for the full ban list.

### 2.5 The bootstrap class

`plugins/notes/Notes.php`:

```php
<?php
/**
 * Notes — internal team notes plugin.
 */

class Notes extends Plugin {

    public function boot(): void {
        // Sidebar nav item, gated by the permission
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);

        // Dashboard widget
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);

        // Load the public API class so other plugins can call it
        $apiFile = $this->dir('NotesAPI.php');
        if (file_exists($apiFile)) {
            require_once $apiFile;
        }
    }

    public function addAdminNav(array $items): array {
        $items[] = [
            'slug'  => 'notes',
            'label' => __('notes', 'Notes'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'edit-3',
            'perm'  => 'notes.manage',
            'order' => 600,
        ];
        return $items;
    }

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('notes.manage')) return $widgets;

        $count = (int)Database::value(
            "SELECT COUNT(*) FROM notes_items WHERE tenant_id = ?",
            [current_tenant_id()]
        );

        ob_start();
        ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('notes', 'Notes') ?></h2>
                <span class="badge badge-active"><?= $count ?></span>
            </div>
            <p class="text-sub">
                <?= sprintf(__('notes_widget_summary',
                    'You have %d team note(s).'), $count) ?>
            </p>
            <a href="<?= e($this->url('admin/index.php')) ?>" class="btn btn-sm mt-2">
                <?= __('open_notes', 'Open Notes') ?> →
            </a>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
```

### 2.6 The public API class

`plugins/notes/NotesAPI.php`:

```php
<?php
/**
 * Notes public API.
 *
 * Other plugins call these methods instead of touching notes_items
 * directly. If we change the schema later, callers don't break as
 * long as we keep these signatures stable.
 *
 * Use as: \NotesAPI::recent($userId, 5);
 */

class NotesAPI {

    /** Recent notes for a user. */
    public static function recent(int $userId, int $limit = 10): array {
        return Database::rows(
            "SELECT id, title, body, created_at
               FROM notes_items
              WHERE tenant_id = ? AND user_id = ?
              ORDER BY created_at DESC
              LIMIT " . max(1, min(100, $limit)),
            [current_tenant_id(), $userId]
        );
    }

    /** Create a note. Returns the new row's id. */
    public static function create(int $userId, string $title, string $body = ''): int {
        return Database::insert('notes_items', [
            'tenant_id' => current_tenant_id(),
            'user_id'   => $userId,
            'title'     => mb_substr($title, 0, 190),
            'body'      => $body !== '' ? $body : null,
        ]);
    }

    /** Delete a note owned by this user. Returns rows affected. */
    public static function delete(int $userId, int $noteId): int {
        return Database::delete(
            'notes_items',
            'id = ? AND user_id = ? AND tenant_id = ?',
            [$noteId, $userId, current_tenant_id()]
        );
    }
}
```

### 2.7 The admin page

`plugins/notes/admin/index.php`:

```php
<?php
/**
 * Notes — admin page.
 * Single route: list + create on one page.
 */

// Bootstrap the core (find slate root by walking up)
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('notes.manage');

$pageTitle  = __('notes', 'Notes');
$currentNav = 'notes';

$flash    = null;
$tenantId = current_tenant_id();
$userId   = (int)Auth::userId();

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body  = trim((string)($_POST['body'] ?? ''));
            if ($title === '') {
                $flash = ['type' => 'error', 'msg' => __('title_required', 'Title is required.')];
            } else {
                NotesAPI::create($userId, $title, $body);
                AuditLog::record('notes.created', $title);
                $flash = ['type' => 'success', 'msg' => __('note_added', 'Note added.')];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['note_id'] ?? 0);
            NotesAPI::delete($userId, $id);
            AuditLog::record('notes.deleted', "note#$id");
            $flash = ['type' => 'success', 'msg' => __('note_deleted', 'Note deleted.')];
        }
    }
}

require $root . '/admin/partials/header.php';

$notes       = NotesAPI::recent($userId, 50);
$placeholder = Database::setting('notes.placeholder') ?: __('default_placeholder',
    'What\'s on your mind?');
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('notes', 'Notes')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('notes', 'Notes') ?></h1>
        <p class="page-header-sub">
            <?= __('notes_subtitle', 'Private team notes.') ?>
        </p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><?= __('new_note', 'New note') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create">

        <div class="field">
            <label class="field-label" for="title"><?= __('title', 'Title') ?>
                <span class="field-required">*</span></label>
            <input type="text" id="title" name="title" required maxlength="190"
                   placeholder="<?= e($placeholder) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="body"><?= __('body', 'Body') ?></label>
            <textarea id="body" name="body" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('add_note', 'Add note') ?></button>
    </form>
</div>

<?php if (empty($notes)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('no_notes', 'No notes yet') ?></div>
            <p><?= __('no_notes_intro', 'Write your first note above.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="card-header">
        <h2><?= __('your_notes', 'Your notes') ?></h2>
        <span class="text-muted text-sm"><?= count($notes) ?></span>
    </div>
    <div class="data-list" data-single-open>
        <?php foreach ($notes as $n):
            ob_start(); ?>
            <form method="post" style="margin:0"
                  onsubmit="return confirm(<?= e(json_encode(
                      __('confirm_delete_note', 'Delete this note?'))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">
                    <?= __('delete', 'Delete') ?>
                </button>
            </form>
            <?php $actions = ob_get_clean();

            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($n['title'], 0, 2)),
                'avatar_color' => 'accent',
                'title'        => $n['title'],
                'meta'         => $n['created_at'],
                'detail'       => [
                    'Title'   => $n['title'],
                    'Body'    => $n['body'] !== null ? ['label' => 'Body',
                        'value' => $n['body'], 'muted' => true] : '—',
                    'Created' => $n['created_at'],
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
```

### 2.8 Package and install

From the Slate root:

```bash
php bin/package-plugin.php plugins/notes
# → plugins/notes-v1.0.0.zip
```

Then in the admin: Plugins → Upload a plugin → select your zip →
Upload & install → click Activate. "Notes" appears in the sidebar.

That's it. You have a working plugin.

---

## 3. Recipes — common patterns

### 3.1 Read and write your own table

Use `Database::row()` for single rows, `Database::rows()` for sets,
`Database::insert()` / `update()` / `delete()` for mutations. Always
pass parameters separately; never interpolate user input into SQL.

```php
// Single row
$note = Database::row(
    "SELECT * FROM notes_items WHERE id = ? AND tenant_id = ?",
    [$id, current_tenant_id()]
);

// Multiple rows
$recent = Database::rows(
    "SELECT * FROM notes_items WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?",
    [current_tenant_id(), 10]
);

// Insert returns the new id
$id = Database::insert('notes_items', [
    'tenant_id' => current_tenant_id(),
    'user_id'   => Auth::userId(),
    'title'     => $title,
]);

// Update returns rows affected
Database::update('notes_items', ['title' => $newTitle], 'id = ?', [$id]);

// Delete returns rows affected
Database::delete('notes_items', 'id = ?', [$id]);

// Scalar
$count = (int)Database::value(
    "SELECT COUNT(*) FROM notes_items WHERE tenant_id = ?",
    [current_tenant_id()]
);
```

Always scope queries to `tenant_id` for multi-tenant safety, even
if Slate is currently single-tenant. The schema is ready for it.

### 3.2 Add an admin sidebar nav item

In your `boot()`:

```php
Hook::addFilter('admin_nav_items', function (array $items): array {
    $items[] = [
        'slug'  => 'notes',                          // unique, must match plugin slug
        'label' => __('notes', 'Notes'),             // display label
        'href'  => $this->url('admin/index.php'),    // your admin URL
        'icon'  => 'edit-3',                         // see PLUGIN-API.md §15 icon registry
        'perm'  => 'notes.manage',                   // gate by permission
        'order' => 600,                              // sort order (core items <500)
        'group' => 'content',                        // optional; see PLUGIN-API.md §15b
    ];
    return $items;
});
```

Plugin nav items render below core ones. Set `order` to 500+ to
keep that ordering predictable. If `perm` is set, users without
that permission won't see the item. The optional `group` key
controls which uppercase section label the item appears under in
the sidebar — core groups are `overview`, `content`, `system`;
items without a group default to `plugins`. The legacy `'parent'`
field is silently ignored.

### 3.3 Add a dashboard widget

```php
Hook::addFilter('admin_dashboard_widgets', function (array $widgets): array {
    // Optional: gate by permission
    if (!Auth::can('notes.manage')) return $widgets;

    ob_start();
    ?>
    <div class="card">
        <div class="card-header">
            <h2>My widget</h2>
        </div>
        <p>...content...</p>
    </div>
    <?php
    $widgets[] = ob_get_clean();
    return $widgets;
});
```

Widgets render in the dashboard grid; use one `.card` wrapper per
widget. They're injected in the order plugins are loaded.

### 3.4 Card-row data list

The card-row pattern replaces HTML tables. Single helper call per
row, expand/collapse handled by Slate's CSS+JS:

```php
echo '<div class="data-list" data-single-open>';
foreach ($items as $item) {
    slate_data_row([
        'avatar'       => 'AB',                   // 2-3 chars
        'avatar_color' => 'success',              // success/info/warning/danger/muted/accent
        'title'        => $item['name'],
        'meta'         => $item['email'] . ' · ' . $item['role'],
        'badge'        => [$item['status'], $item['status']],  // [label, color]
        'detail'       => [
            'Email'    => $item['email'],
            'Phone'    => $item['phone'] ?: '—',
            'Notes'    => ['label' => 'Notes', 'value' => $item['notes'], 'muted' => true],
            'Profile'  => ['label' => 'Profile', 'html' =>
                '<a href="...">View profile</a>'],
        ],
        'actions'      => '<button class="btn btn-sm">Edit</button>',
    ]);
}
echo '</div>';
slate_data_list_script();  // emits the toggle JS, once per page
```

`data-single-open` makes only one row open at a time. Drop it if
you want multiple rows open simultaneously.

See PLUGIN-API.md §16 for the full helper reference.

### 3.5 CSRF + flash messages

Every POST handler needs CSRF verification. Every form needs the
CSRF field:

```php
// In the form
<?= csrf_field() ?>

// In the POST handler
if (!csrf_verify()) {
    $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
} else {
    // process...
}

// In the page
if ($flash) {
    echo '<div class="alert alert-' . e($flash['type']) . '" role="status">'
        . e($flash['msg']) . '</div>';
}
```

### 3.6 Audit log

Whenever your plugin changes state, write an audit entry. Doesn't
have to be every read — just the writes that matter:

```php
AuditLog::record('notes.created', "note#$id", ['title' => $title]);
AuditLog::record('notes.deleted', "note#$id");
AuditLog::record('notes.settings_changed', null, ['key' => 'placeholder']);
```

The signature is `record(action, target, context = [])`. Audit
entries appear in `/admin/audit.php` (Stage 1 cleanup will surface
this UI).

### 3.7 Translations

Always wrap user-facing strings with `__('key', 'English fallback')`:

```php
__('save_changes', 'Save changes')
__('error_required', 'This field is required.')
sprintf(__('count_summary', 'You have %d notes'), $count)
```

The second argument is the source of truth. Slate doesn't require a
language file just to ship — the fallback works as the default.
Translations and admin-editable string overrides come later.

### 3.8 The design system

Use these classes, don't write parallel CSS. (Full inventory:
PLUGIN-API.md §15.)

| Class | Use |
|---|---|
| `.card` | Standard surface wrapper |
| `.card-header` | Header bar inside a card |
| `.page-header` | Top of a page (title + actions) |
| `.btn`, `.btn-primary`, `.btn-danger`, `.btn-ghost`, `.btn-sm` | Buttons |
| `.field`, `.field-label`, `.field-hint`, `.field-required` | Form fields |
| `.field-row.field-row-2` | Two-column form row |
| `.alert.alert-success`, `.alert-error`, `.alert-info`, `.alert-warning` | Banners |
| `.badge.badge-active`, `.badge-installed`, `.badge-inactive`, etc. | Status pills |
| `.empty.empty-title.empty-icon` | Empty state |
| `.text-muted`, `.text-sm`, `.text-danger` | Text utilities |
| `.flex`, `.gap-2`, `.items-center`, `.mt-3`, `.mb-2` | Layout utilities |

If you find yourself reaching for a custom CSS file, first check
whether the design system already has what you need.

### 3.9 Plugin-scoped settings

`$this->setting()` and `$this->setSetting()` automatically prefix
keys with your slug:

```php
// In the plugin bootstrap or any class with $this
$this->setSetting('placeholder', "What's on your mind?");
$value = $this->setting('placeholder', "Default");

// From outside the plugin (static), use the full key:
Database::setting('notes.placeholder');
```

Settings are stored in the core `settings` table as text/JSON.
They're per-tenant.

### 3.10 URLs to your own (or another) plugin

Two ways to build a URL pointing at a plugin's files:

```php
// From inside your Plugin class — use $this->url()
$href = $this->url('admin/index.php');
// → /plugins/notes/admin/index.php

// From an admin page or any other context — use plugin_url()
$href = plugin_url('notes', 'admin/index.php');
// → /plugins/notes/admin/index.php
```

`plugin_url()` is a global helper. It's what you reach for when
you're inside `admin/index.php` and need to link to another file
in the same plugin (since `$this` isn't available there), or when
one plugin wants to link to another's admin page.

`plugin_dir()` is the filesystem counterpart — same args, returns
an absolute path. Rare to need; mostly comes up when one plugin
needs to read another's static asset.

```php
plugin_url('shop')                       // → /plugins/shop
plugin_url('shop', 'admin/orders.php')   // → /plugins/shop/admin/orders.php
plugin_dir('shop', 'install.sql')        // → /var/www/slate/plugins/shop/install.sql
```

---

## 4. Communicating with other plugins

Slate plugins **never `require` or `include` each other's files**.
Instead they discover each other at runtime and call each other's
public APIs.

### 4.1 Calling another plugin from yours

```php
// In your Notes plugin, you want to call the Booking plugin if it's available
if (PluginLoader::isActive('booking') && class_exists('BookingAPI')) {
    $upcoming = \BookingAPI::upcomingFor($userId);
    // ... use $upcoming
}
```

Three checks before calling:

1. `PluginLoader::isActive('slug')` — installed AND activated
2. `class_exists('TargetAPI')` — the API class is loaded
3. Optionally `method_exists()` for a specific method if you're
   targeting a specific version

This is **soft coupling**: the Booking plugin can be uninstalled and
your plugin still works (just skips the integration).

### 4.2 Exposing your API to others

Convention: create `<Slug>API.php` in your plugin root, define a
class named `<Slug>API` with static methods. Load it in `boot()`
via `require_once`. Use the file pattern from the Notes example
above.

API methods should:

- Be `public static`
- Take primitive types and arrays, not your internal models
- Always scope to `current_tenant_id()` for tenant safety
- Return rows as plain associative arrays
- Throw `\RuntimeException` for unrecoverable errors, return
  `null`/`false` for "not found"

### 4.3 Don't reach across boundaries

Don't query another plugin's tables directly even if you can. If
the Booking plugin renames `booking_appointments` to
`booking_sessions`, every plugin that hard-coded the table breaks.
The API class is the contract.

---

## 5. Hooks into the core

Slate's `Hook` class provides filters (transform a value) and
actions (do something at a point in time).

### 5.1 Filters Slate fires

| Filter | When | What you get | What you return |
|---|---|---|---|
| `admin_nav_items` | Sidebar renders | Array of nav items | Array of nav items |
| `admin_dashboard_widgets` | Dashboard renders | Array of HTML strings | Array of HTML strings |
| `admin_topbar_actions` | Topbar renders | Array of action HTML | Array of action HTML |
| `admin_topbar_search` | Topbar renders | String (HTML for the centre slot) | String |
| `customer_dashboard_widgets` | `/customer/` renders | Array of HTML strings | Array of HTML strings |
| `public_routes` | A public URL is dispatched | Map of `prefix => ['handler' => …]` | Map (extended) |

Plugins extend these by registering callbacks in `boot()`. See §11
for the customer-facing filters in particular.

### 5.2 Actions Slate fires

| Action | When | Args |
|---|---|---|
| `user_logged_in` | Successful admin login | `(int $userId)` |
| `user_logged_out` | Admin logout | `(int $userId)` |
| `plugin_activated` | After a plugin's `install.sql` runs | `(string $slug)` |
| `plugin_deactivated` | After deactivation | `(string $slug)` |
| `daily_cron` | Once per day if cron is wired | `()` |
| `customer_registered` | After `Auth::registerCustomer` succeeds | `(int $customerId)` |
| `customer_logged_in` | Successful customer login | `(int $customerId)` |
| `customer_email_verified` | After a customer clicks their verify link | `(int $customerId)` |
| `stripe_webhook_event` | Signature-verified Stripe webhook arrived (any event type) | `(array $event)` |

```php
Hook::addAction('user_logged_in', function (int $userId) {
    // ... e.g. record last_seen, send a Slack message, etc.
});
```

### 5.3 Firing your own hooks

Other plugins can extend your plugin too. Wherever you'd allow
customization, fire a filter or action:

```php
// In NotesAPI::create()
$title = Hook::applyFilters('notes_pre_save_title', $title, $userId);
$id = Database::insert(...);
Hook::doAction('notes_created', $id, $userId);
```

Document any hooks you fire in your README so integrators know
what's available.

---

## 6. Settings UI

Slate doesn't auto-generate settings forms; plugins build their
own. The typical pattern is to add a small "Plugin settings" card
to your admin page:

```php
// In your admin page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_settings') {
    if (csrf_verify()) {
        $placeholder = trim((string)($_POST['placeholder'] ?? ''));
        Database::setSetting('notes.placeholder', mb_substr($placeholder, 0, 190));
        AuditLog::record('notes.settings_changed');
        $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
    }
}

$placeholder = Database::setting('notes.placeholder') ?: '';
?>
<div class="card">
    <div class="card-header"><h2>Settings</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_settings">
        <div class="field">
            <label class="field-label" for="placeholder">New-note placeholder</label>
            <input type="text" id="placeholder" name="placeholder" maxlength="190"
                   value="<?= e($placeholder) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
```

For sensitive settings (API keys, passwords), encrypt before
storing:

```php
Database::setSetting('notes.api_key', slate_encrypt_secret($plaintext));
// ... later
$key = slate_decrypt_secret(Database::setting('notes.api_key'));
```

`slate_encrypt_secret()` envelopes with AES-256-GCM under the
app's `APP_SECRET`. The result starts with `enc:v1:` so you can
tell encrypted values apart.

---

## 7. Custom permissions

Declare permissions in your manifest:

```json
{
    "permissions": [
        {"key": "notes.view",   "label": "View team notes"},
        {"key": "notes.manage", "label": "Create and delete team notes"},
        "notes.export"
    ]
}
```

The Roles editor automatically groups them under "<Plugin Name>
(plugin)" once you activate. Super-admins always have every
permission, including yours.

Check permissions in your code with `Auth::can()`:

```php
if (Auth::can('notes.manage')) {
    // show the delete button
}

// Or hard-gate the request:
Auth::requirePerm('notes.manage');  // sends 403 if not allowed
```

If you reference a permission key that no role has been granted,
only super-admins will pass the check. Add sensible defaults via
your `install.sql` if you want a default grant for, say, the
Manager role:

```sql
-- After CREATE TABLE statements
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`)
VALUES (2, 'notes.manage', 1);
```

This grants `notes.manage` to role_id=2 (Manager) by default. Use
`INSERT IGNORE` so re-running install.sql doesn't error on the
unique constraint.

---

## 8. Packaging and distribution

```bash
# From slate root
php bin/package-plugin.php plugins/notes
# → plugins/notes-v1.0.0.zip   (lives next to plugin source)

php bin/package-plugin.php plugins/notes --dist
# → plugins/_dist/notes-v1.0.0.zip   (the "shipping" location)
```

The packager validates everything the installer will validate:

- `plugin.json` is valid JSON with required fields
- Folder name matches manifest slug
- `<Slug>.php` bootstrap exists with `<Slug>` class
- `install.sql` and `uninstall.sql` both exist
- SQL uses only your prefixed tables
- No banned patterns (`DROP DATABASE`, `GRANT`, etc.)

It excludes development noise: `.git`, `node_modules`, `vendor`,
`.DS_Store`, `.gitkeep`, etc.

If the packager passes, the installer will pass.

### Distributing

Hand the resulting ZIP to anyone running Slate. They install via
Plugins → Upload a plugin → select the ZIP.

You can also host it for download somewhere; Slate doesn't have a
marketplace concept and probably never will. Each Slate install
owns its own plugin choices.

---

## 9. Versioning your plugin

Use semver. Bump the manifest `version` on every release.

```
1.0.0 → 1.0.1   bug fix, no contract changes
1.0.0 → 1.1.0   new feature, backward compatible
1.0.0 → 2.0.0   breaking change (renamed API method, table change)
```

### Upgrade installs

When a user uploads a ZIP for an already-installed plugin:

- The `plugins` table row keeps the same `status` (active stays
  active, etc.)
- The plugin directory is replaced with the new files
- `install.sql` is NOT automatically re-run (would duplicate data)
- You're responsible for any schema migrations

For schema migrations, the simplest pattern: ship `install.sql`
that's safe to re-run, and add a `migrations/` folder with files
named `2.0.0.sql`, `2.1.0.sql`, etc. In your `boot()`, compare
manifest version to a stored `<slug>.applied_version` setting and
run any missing migrations:

```php
public function boot(): void {
    $applied = $this->setting('applied_version', '0.0.0');
    if (version_compare($applied, $this->version, '<')) {
        $this->runMigrations($applied);
        $this->setSetting('applied_version', $this->version);
    }
    // ... normal boot
}

private function runMigrations(string $from): void {
    $dir = $this->dir('migrations');
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.sql') as $file) {
        $target = basename($file, '.sql');
        if (version_compare($target, $from, '>')) {
            $sql = file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') Database::query($stmt);
            }
        }
    }
}
```

### Core compatibility

`requires_core` in the manifest lets you refuse to install on
incompatible core versions:

```json
"requires_core": ">=1.2.0"
```

The installer checks this against `SLATE_VERSION` and refuses with
a clear error if the constraint isn't met. Supported operators:
`>=`, `>`, `<=`, `<`, `=`, `^` (caret = same major version),
`~` (tilde = same minor version).

---

## 10. Debugging

### Log helper

```php
slate_log("Notes: about to call BookingAPI with $userId", 'info');
slate_log("Notes: BookingAPI returned nothing", 'warning');
slate_log("Notes: bad input from form", 'error');
```

Goes to PHP's error_log. Level is one of `debug|info|warning|error`.

### Plugin not loading

Check, in order:

1. Plugin status in `plugins` table — must be `active`
2. Plugin directory exists at `plugins/<slug>/`
3. `plugin.json` is valid JSON
4. Bootstrap file exists with correct class name
5. PHP error log for syntax errors in your bootstrap

`PluginLoader::boot()` skips plugins that throw during construction
and writes the error to the log. It won't crash the entire admin.

### "Failed to extract ZIP"

The ZIP must contain exactly one top-level folder named after the
slug. Common mistakes:

- ZIP made by right-clicking the plugin folder → contents are at
  the root level instead of inside a folder. Use the packager.
- ZIP includes a `__MACOSX/` folder from macOS. The packager
  excludes this; manual ZIPs may not.
- The folder name doesn't match the manifest slug.

### Tables not created on activate

Check:

- `install.sql` syntax with `mysql --execute < install.sql` first
- That `Database::get()->exec(...)` errors aren't being silently
  swallowed; the loader logs them
- That you're checking for tables in the right tenant

### Permission denied on installs

`uploads/_plugin_staging/` and `plugins/` must both be writable by
the web server user. The installer surfaces this in the error
message if it can detect it. From the host:

```bash
chmod -R 775 uploads/ plugins/
chown -R www-data:www-data uploads/ plugins/  # adjust for your webserver user
```

---

## 11. Customer-facing plugins

Slate ships with a customer portal at `/customer/` and a public
router that maps clean URLs (e.g. `/forms/<slug>`, `/book/...`) to
plugin handlers. Plugins that need a customer-facing surface — a
booking widget, a public form, a portal page — wire into both.

### 11.1 Register a public route

In your plugin's `boot()`:

```php
Hook::addFilter('public_routes', function (array $routes): array {
    $routes['forms'] = [
        'handler' => plugin_dir('forms') . '/public/router.php',
        // Optional: 'methods' => ['GET', 'POST'],
    ];
    return $routes;
});
```

Now `/forms/contact-us`, `/forms/contact-us/thanks`, etc. all land
in your `public/router.php`. Inside the handler:

```php
require_once dirname(__DIR__, 3) . '/config.php';

$prefix    = $_GET['_route_prefix'] ?? '';   // 'forms'
$remainder = $_GET['_route_path']   ?? '';   // e.g. 'contact-us'

// Your routing logic here — match $remainder to a form slug,
// render the form, accept POST, etc.
```

Longest-prefix-match wins, so `forms/admin/...` registered by
the same plugin would intercept `forms/admin/foo` without breaking
the `forms/<slug>` route. Handlers must be absolute file paths.

The shell handles the 404 case for unmatched paths — you don't
need to render your own.

### 11.2 Customer auth — the helpers

Use `Auth::*` for everything customer-side. Never roll your own
sessions or password hashing.

```php
Auth::registerCustomer($email, $password, $name, $phone)
    // → ['ok'=>true, 'customer_id'=>int, 'email_sent'=>bool]
    // → ['ok'=>false, 'error'=>string]

Auth::attemptCustomerLogin($email, $password)        // bool
Auth::sendCustomerVerification($customerId)          // bool
Auth::verifyCustomerEmail($token)                    // ?int customerId
Auth::sendCustomerPasswordReset($email)              // bool (always true)
Auth::resetCustomerPassword($token, $newPassword)    // ['ok'=>…]

Auth::customer()         // ?array  current customer (id, email, name, tenant_id)
Auth::customerId()       // ?int
Auth::requireCustomer()  // redirect to /customer/login if not signed in
Auth::logoutCustomer()
```

All tokens are SHA-256 hashed, single-use, time-bounded; they live
in `customer_auth_tokens` (the table is created on first use).
Plaintext tokens only ever appear in email links.

Customer pages that require a sign-in start with:

```php
require_once dirname(__DIR__, 3) . '/config.php';
Auth::requireCustomer();
$cust = Auth::customer();
```

### 11.3 Add a customer dashboard widget

Mirror image of `admin_dashboard_widgets`. Return an array of HTML
strings; each renders as a card in the customer's `/customer/`
dashboard under "Your activity".

```php
Hook::addFilter('customer_dashboard_widgets', function (array $widgets): array {
    $cid = Auth::customerId();
    if ($cid === null) return $widgets;

    $bookings = Database::rows(
        "SELECT * FROM booking_appointments
          WHERE customer_id = ? AND starts_at > NOW()
          ORDER BY starts_at LIMIT 3",
        [$cid]
    );
    if (!$bookings) return $widgets;

    ob_start(); ?>
    <div class="card">
        <div class="card-header"><h2>Upcoming bookings</h2></div>
        <ul class="kv-list">
            <?php foreach ($bookings as $b): ?>
                <li class="kv-row">
                    <span class="kv-label"><?= e($b['service_name']) ?></span>
                    <span class="kv-value"><?= e(date('j M, H:i', strtotime($b['starts_at']))) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
    $widgets[] = ob_get_clean();
    return $widgets;
});
```

### 11.4 React to a customer signing up

```php
Hook::addAction('customer_registered', function (int $customerId) {
    // e.g. seed a default record, send a welcome bonus, notify Slack
});

Hook::addAction('customer_email_verified', function (int $customerId) {
    // e.g. unlock features that require a verified address
});
```

### 11.5 Customer page boilerplate

For a custom customer-side page rendered through the dashboard
chrome (topbar + signed-in user + sign out), use the shared
partials with `$customerPageVariant = 'dashboard'`:

```php
<?php
require_once dirname(__DIR__, 3) . '/config.php';

Auth::requireCustomer();
$cust = Auth::customer();

$pageTitle           = 'My Bookings';
$customerPageVariant = 'dashboard';
require SLATE_ROOT . '/customer/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1>My bookings</h1>
        <p class="page-header-sub">Upcoming and past appointments.</p>
    </div>
</div>

<div class="card">
    <!-- … -->
</div>

<?php require SLATE_ROOT . '/customer/partials/footer.php'; ?>
```

For embeddable pages (the iframe view of a booking widget, a form
embed, etc.) skip the partials entirely and render minimal chrome —
the host site provides its own header. Honour a `?embed=1` query
to strip the outer layout.

### 11.6 Sending email from a customer flow

```php
Mailer::send(
    $cust['email'],
    'Your booking is confirmed',
    '<p>See you at ' . e($when) . '.</p>',
    $cust['name'] ?? ''
);
```

The signature is `(to, subject, body, toName='', log=true)`. Use
`AuditLog::record('myplugin.email_sent', (string)$customerId)` so
deliveries show up in the audit log.

---

## 12. Taking payments

Slate has a generic Stripe checkout API. If your plugin needs to
charge money, you call into the Stripe Payment plugin instead of
owning your own Stripe wiring.

This means:

- No copies of cURL + HMAC logic in your plugin
- The `stripepayment_charges` admin page sees your charges alongside
  everyone else's (with refund button)
- The site-wide test/live mode toggle controls your charges too
- One webhook endpoint receives Stripe events; you subscribe via a
  hook

### 12.1 Trigger a hosted checkout

```php
require_once plugin_dir('stripe-payment') . '/StripePaymentAPI.php';

$session = StripePaymentAPI::createCheckout(
    lineItems: [
        ['name' => 'Strategy session',  'amount_cents' => 15000, 'quantity' => 1],
        ['name' => 'Booking deposit',   'amount_cents' =>  5000, 'quantity' => 1],
    ],
    opts: [
        'currency'       => 'usd',
        'customer_email' => $customer['email'],
        'success_url'    => SLATE_URL . '/book/thanks?ref=' . $ref,
        'cancel_url'     => SLATE_URL . '/book?cancelled=1',
        'metadata'       => [
            'source_plugin' => 'booking',           // your plugin slug
            'source_id'     => (string)$appointmentId,
            'ref'           => $ref,
        ],
    ]
);
// $session === ['session_id' => 'cs_test_…', 'url' => 'https://checkout.stripe.com/…']

header('Location: ' . $session['url']);
exit;
```

`amount_cents` is the integer amount in the currency's smallest unit
(cents for USD, yen for JPY — Stripe lists 16 zero-decimal currencies).
Anything you put in `metadata` comes back on the webhook event.

### 12.2 React to a successful charge

Stripe POSTs to `/plugins/stripe-payment/public/webhook.php`. After
verifying the signature, that endpoint fires the
`stripe_webhook_event` action with the parsed event payload.

```php
Hook::addAction('stripe_webhook_event', function (array $event) {
    if ($event['type'] !== 'checkout.session.completed') return;
    $session = $event['data']['object'] ?? [];
    $meta    = $session['metadata'] ?? [];

    // Only act on events your plugin initiated.
    if (($meta['source_plugin'] ?? '') !== 'booking') return;

    $appointmentId = (int)($meta['source_id'] ?? 0);
    if ($appointmentId <= 0) return;

    Database::update('booking_appointments',
        ['status' => 'confirmed', 'paid_at' => date('Y-m-d H:i:s')],
        'id = ?', [$appointmentId]);
});
```

The shared webhook endpoint also auto-inserts a
`stripepayment_charges` row for every paid event, so the Charges
admin page surfaces your transactions without extra wiring.

### 12.3 Programmatic refunds

Refunding from your plugin:

```php
$result = StripePaymentAPI::refundCharge($chargeId, /* cents, null = remainder */ null);
if ($result['ok']) {
    // $result['refund_id'] is the Stripe refund id
}
```

The Charges admin page has a refund button that does the same thing
visually.

### 12.4 Useful primitives

```php
StripePaymentAPI::mode()                     // 'test' | 'live'
StripePaymentAPI::isConfigured()             // both keys set?
StripePaymentAPI::createPaymentIntent($amountCents, $opts)
StripePaymentAPI::getSession($sessionId)
StripePaymentAPI::getPaymentIntent($piId)
StripePaymentAPI::listCharges($filters)      // for your own admin pages
StripePaymentAPI::getCharge($id)
StripePaymentAPI::recordCharge($args)        // idempotent on session/intent id
```

### 12.5 Plugin contract

If your plugin depends on Stripe Payment:

```json
{
    "works_better_with": ["stripe-payment"]
}
```

— and check at runtime before calling:

```php
if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
    // Render a "configure Stripe first" notice or fall back to manual payment.
}
```

Never hard-`require` the plugin; the user might intentionally not
install it (free-tier flows, alt-processor builds).

---

## 13. What not to do

- **Don't `require`/`include` files from another plugin.** Use the
  API class pattern.
- **Don't write to tables outside your prefix.** Use core APIs
  (`Database::setting`, `AuditLog::record`, etc.) for cross-cutting
  data.
- **Don't shadow core functions or classes.** `Auth`, `Database`,
  `Mailer`, `Hook`, `PluginLoader`, `Plugin`, `AuditLog`, `Uploads`,
  `I18n` are reserved.
- **Don't bypass `csrf_verify()` on POST endpoints**, even
  "internal" ones. Anything reachable by URL needs the check.
- **Don't bypass `Auth::requirePerm()` on admin pages.** Don't
  assume "if they're logged in, they're allowed."
- **Don't store secrets in plain text.** Use
  `slate_encrypt_secret()` for API keys, passwords, tokens.
- **Don't trust user input.** Use prepared statements always.
  Escape all output with `e()` for HTML.
- **Don't load assets from CDNs in admin pages.** Ship them with
  your plugin so it works offline and the admin doesn't leak
  referrer to third parties.
- **Don't write parallel CSS.** Use the design system. Plugins that
  look out of place are jarring; plugins that look native earn
  trust.
- **Don't ship debug code, `var_dump()`, or development URLs**
  in published plugins. The packager doesn't strip these.

---

## Quick reference

```
plugins/<slug>/
├── plugin.json              ← manifest (required)
├── <Slug>.php               ← bootstrap class (required)
├── <Slug>API.php            ← public API (optional, recommended)
├── install.sql              ← schema (required)
├── uninstall.sql            ← teardown (required)
├── admin/
│   └── index.php            ← your admin page(s)
├── migrations/              ← optional, see §9
│   ├── 1.1.0.sql
│   └── 2.0.0.sql
├── assets/
│   ├── css/your.css         ← enqueue with $this->enqueueStyle()
│   └── js/your.js           ← enqueue with $this->enqueueScript()
└── README.md                ← optional, for your future self
```

```php
// In your bootstrap
class <Slug> extends Plugin {
    public function boot(): void {
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        // ...
    }
}
```

```bash
# Package and ship
php bin/package-plugin.php plugins/<slug> --dist
```

For deeper contract questions, read `PLUGIN-API.md` next to this
file. Happy building.
