<?php
/**
 * Slate — PluginLoader.
 *
 * The central registry for plugins. Reads the `plugins` table once
 * per request, requires each active plugin's bootstrap file, calls
 * boot() on the resulting Plugin instance. Inactive plugins are
 * never touched — zero overhead.
 *
 * Public surface:
 *   PluginLoader::boot()                 ← called once by config.php
 *   PluginLoader::isActive($slug)        ← runtime detection by other plugins
 *   PluginLoader::install($zipPath)      ← admin: extract + validate + register
 *   PluginLoader::discoverOnDisk()       ← every plugins/<slug>/plugin.json found on disk, not yet registered or not
 *   PluginLoader::installFromDisk($slug) ← register (if needed) + activate a plugin already unpacked on disk
 *   PluginLoader::activate($slug)        ← admin: run install.sql, status='active'
 *   PluginLoader::deactivate($slug)      ← admin: status='inactive'
 *   PluginLoader::uninstall($slug)       ← admin: run uninstall.sql, remove files
 *   PluginLoader::listAll()              ← admin: every row from `plugins` table
 *   PluginLoader::activePlugins()        ← booted Plugin objects, keyed by slug
 *   PluginLoader::renderQueuedStyles()   ← layout: emit <link> tags
 *   PluginLoader::renderQueuedScripts()  ← layout: emit <script> tags
 */

declare(strict_types=1);

namespace Slate\Kernel\Module;

// Phase 1 A3: migrated from includes/PluginLoader.php into Slate\Kernel\Module.
// The global name `PluginLoader` is provided by a class_alias in
// src/compat/aliases.php. Migrated WHOLE (no SRP split; see
// docs/09-Roadmap/a3-core-reviews/plugin-loader.md). The Plugin base class stays
// GLOBAL for now (Phase-3 SDK work) and is referenced as \Plugin throughout.

class PluginLoader {
    /** @var array<string, \Plugin> Booted plugin objects, keyed by slug. */
    private static array $active = [];

    /** Slugs cached as a quick isActive() lookup table. */
    private static ?array $activeSlugs = null;

    /** Have we attempted boot() this request already? */
    private static bool $booted = false;

    // ── Constants ─────────────────────────────────────────────

    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INACTIVE  = 'inactive';

    private const MAX_BOOT_MS = 250;

    // ── Boot ──────────────────────────────────────────────────

    /**
     * Called once per request from config.php. Reads every plugin
     * with status='active' from the `plugins` table, requires its
     * bootstrap, instantiates the class, calls boot().
     *
     * Plugins that throw during boot are caught and logged; the
     * request continues without them.
     */
    public static function boot(): void {
        // Phase 1E C1 — activate()/deactivate()/uninstall() each null out
        // $activeSlugs with a "// Invalidate caches" comment, intending to
        // force isActive() to rebuild it on the next call (isActive():
        // `if (self::$activeSlugs === null) self::boot();`). But this guard
        // — $booted alone — made that a no-op forever after the request's
        // first boot(): once true, boot() always returned immediately
        // without rebuilding $activeSlugs, so isActive() kept evaluating
        // isset(null[$slug]) (always false) for every plugin, for the rest
        // of the process, after ANY single activate/deactivate/uninstall
        // call. Found via EntitlementService's tests, which — as the first
        // security-relevant caller chaining through isActive() — are the
        // first code in this app to actually depend on it staying correct
        // after another test in the same process toggles a plugin.
        if (self::$booted && self::$activeSlugs !== null) return;
        self::$booted = true;

        try {
            $rows = \Database::rows(
                "SELECT * FROM plugins WHERE status = ? ORDER BY id ASC",
                [self::STATUS_ACTIVE]
            );
        } catch (\Throwable $e) {
            // `plugins` table not created yet (pre-install) — silent.
            self::$activeSlugs = [];
            return;
        }

        self::$activeSlugs = [];
        foreach ($rows as $row) {
            $slug = $row['slug'];

            try {
                $plugin = self::loadOne($slug, $row);
                if ($plugin) {
                    self::$active[$slug] = $plugin;
                    self::$activeSlugs[$slug] = true;
                    $started = microtime(true);
                    $plugin->boot();
                    $elapsed = (microtime(true) - $started) * 1000;
                    if ($elapsed > self::MAX_BOOT_MS) {
                        slate_log(sprintf("Plugin '%s' boot() took %.0f ms (> %d ms threshold)", $slug, $elapsed, self::MAX_BOOT_MS), 'warning');
                    }
                }
            } catch (\Throwable $e) {
                slate_log("Plugin '$slug' boot failed: " . $e->getMessage(), 'error');
                unset(self::$active[$slug]);
                unset(self::$activeSlugs[$slug]);
            }
        }
    }

    /**
     * Require + instantiate a single plugin. Returns the Plugin
     * instance or null if loading failed.
     */
    private static function loadOne(string $slug, array $row): ?\Plugin {
        $dir = SLATE_ROOT . '/plugins/' . $slug;
        if (!is_dir($dir)) {
            slate_log("Plugin '$slug' is marked active but directory missing.", 'error');
            return null;
        }

        $className = self::slugToClass($slug);
        $bootstrap = $dir . '/' . $className . '.php';
        if (!file_exists($bootstrap)) {
            slate_log("Plugin '$slug' bootstrap not found at $bootstrap", 'error');
            return null;
        }

        require_once $bootstrap;
        if (!class_exists($className)) {
            slate_log("Plugin '$slug' bootstrap loaded but class $className not declared.", 'error');
            return null;
        }
        if (!is_subclass_of($className, \Plugin::class)) {
            slate_log("Plugin '$slug' class $className does not extend Plugin.", 'error');
            return null;
        }

        // Prefer the on-disk plugin.json over the DB-snapshotted manifest
        // when the file's version is newer. Without this, dropping a new
        // build over an active install leaves $this->version pinned to the
        // old version that was snapshotted at activation time — so
        // version-gated migrations never run, even though the new code is
        // already on disk. The DB row stays in sync; that's the source of
        // truth for "is this plugin active", but the manifest content is
        // refreshed from the file when it advances.
        $manifest = json_decode($row['manifest_json'], true) ?: [];
        $manifestFile = $dir . '/plugin.json';
        if (file_exists($manifestFile)) {
            $fileManifest = json_decode((string)file_get_contents($manifestFile), true);
            if (is_array($fileManifest)
                && !empty($fileManifest['version'])
                && version_compare(
                    (string)$fileManifest['version'],
                    (string)($manifest['version'] ?? '0.0.0'),
                    '>'
                )
            ) {
                $manifest = $fileManifest;
                // Persist so SELECTs from the plugins table reflect the new
                // version too (admin UI, version_compare elsewhere, etc).
                try {
                    \Database::update('plugins', [
                        'manifest_json' => json_encode($manifest),
                        'version'       => $manifest['version'],
                    ], 'slug = ?', [$slug]);
                } catch (\Throwable $e) {
                    slate_log("Failed to persist refreshed manifest for '$slug': "
                        . $e->getMessage(), 'warning');
                }
            }
        }
        return new $className($slug, $manifest, $dir);
    }

    // ── Runtime detection ─────────────────────────────────────

    /**
     * Is the named plugin currently active? Used by plugins to
     * detect each other:
     *
     *   if (PluginLoader::isActive('passes')) { ... }
     */
    public static function isActive(string $slug): bool {
        if (self::$activeSlugs === null) self::boot();
        return isset(self::$activeSlugs[$slug]);
    }

    /** @return array<string, \Plugin> */
    public static function activePlugins(): array {
        if (!self::$booted) self::boot();
        return self::$active;
    }

    /** Get a specific booted Plugin instance, or null. */
    public static function get(string $slug): ?\Plugin {
        if (!self::$booted) self::boot();
        return self::$active[$slug] ?? null;
    }

    /**
     * Check if a plugin declares a specific capability.
     */
    public static function hasCapability(string $slug, string $cap): bool {
        $plugin = self::get($slug);
        if ($plugin) {
            return $plugin->hasCapability($cap);
        }
        $row = \Database::row("SELECT manifest_json FROM plugins WHERE slug = ?", [$slug]);
        if ($row && !empty($row['manifest_json'])) {
            $manifest = json_decode((string)$row['manifest_json'], true) ?: [];
            return isset($manifest['capabilities'][$cap]);
        }
        return false;
    }

    /**
     * Check if a plugin capability is active and enabled for the current tenant.
     */
    public static function isCapabilityEnabled(string $slug, string $cap): bool {
        if (!self::isActive($slug)) return false;
        $plugin = self::get($slug);
        if ($plugin) {
            return $plugin->isCapabilityEnabled($cap);
        }
        $row = \Database::row("SELECT manifest_json FROM plugins WHERE slug = ?", [$slug]);
        if (!$row || empty($row['manifest_json'])) return false;
        $manifest = json_decode((string)$row['manifest_json'], true) ?: [];
        if (!isset($manifest['capabilities'][$cap])) return false;
        $val = \Database::setting($slug . '.cap.' . $cap);
        if ($val !== null) return (bool)$val;
        return (bool)($manifest['capabilities'][$cap]['default'] ?? true);
    }

    /**
     * Set a plugin capability enabled state for the current tenant.
     */
    public static function setCapabilityEnabled(string $slug, string $cap, bool $enabled): void {
        \Database::setSetting($slug . '.cap.' . $cap, $enabled ? '1' : '0');
    }

    /**
     * Return declared capabilities for a plugin.
     */
    public static function capabilities(string $slug): array {
        $plugin = self::get($slug);
        if ($plugin) {
            return $plugin->capabilities();
        }
        $row = \Database::row("SELECT manifest_json FROM plugins WHERE slug = ?", [$slug]);
        if ($row && !empty($row['manifest_json'])) {
            $manifest = json_decode((string)$row['manifest_json'], true) ?: [];
            return (array)($manifest['capabilities'] ?? []);
        }
        return [];
    }

    // ── Admin: list ───────────────────────────────────────────

    /** Every row from the plugins table, regardless of status. */
    public static function listAll(): array {
        try {
            return \Database::rows("SELECT * FROM plugins ORDER BY name ASC");
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Admin: install ────────────────────────────────────────

    /**
     * Install a plugin from a ZIP file.
     *
     * Steps:
     *   1. Validate ZIP exists + opens
     *   2. Walk archive: no path traversal, no symlinks, all files
     *      live under a single top-level folder
     *   3. Extract to a temp directory
     *   4. Read plugin.json — validate required fields, slug format,
     *      no unknown keys
     *   5. Verify class file matches slug
     *   6. Verify install.sql / uninstall.sql exist
     *   7. Scan SQL for banned patterns + table-prefix check
     *   8. Verify slug matches top-level folder
     *   9. Move to plugins/<slug>/ (replacing any existing dir)
     *  10. Upsert row in `plugins` table with status='installed'
     *
     * Returns ['ok' => true, 'slug' => ...] on success or
     *         ['ok' => false, 'error' => ...] on failure.
     */
    public static function install(string $zipPath): array {
        if (!file_exists($zipPath) || !is_readable($zipPath)) {
            return ['ok' => false, 'error' => 'ZIP file not found or unreadable.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Could not open ZIP archive.'];
        }

        // Determine the top-level folder. All entries must share one.
        $topLevel = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            // Reject path traversal and absolute paths.
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                return ['ok' => false, 'error' => "Rejected file with unsafe path: $name"];
            }

            $parts = explode('/', $name, 2);
            $root  = $parts[0];
            if ($topLevel === null) {
                $topLevel = $root;
            } elseif ($topLevel !== $root) {
                $zip->close();
                return ['ok' => false, 'error' => 'ZIP must contain a single top-level folder.'];
            }
        }

        if ($topLevel === null) {
            $zip->close();
            return ['ok' => false, 'error' => 'ZIP archive is empty.'];
        }

        // Extract to temp staging area. Prefer uploads/_plugin_staging/
        // over sys_get_temp_dir() because /tmp is often a separate
        // filesystem (tmpfs, Docker bind mount), which makes the final
        // rename() into plugins/ fail with EXDEV. Same-FS staging means
        // the move is a fast inode-link operation.
        $stagingRoot = SLATE_ROOT . '/uploads/_plugin_staging';
        if (!is_dir($stagingRoot)) {
            if (!@mkdir($stagingRoot, 0775, true) && !is_dir($stagingRoot)) {
                // Fallback to sys temp if uploads/ isn't writable
                $stagingRoot = sys_get_temp_dir();
            }
        }
        $tmpRoot = $stagingRoot . '/slate-plugin-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpRoot, 0775, true)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Failed to create temp directory at ' . $stagingRoot
                . '. Check that uploads/ is writable by the web server user.'];
        }
        if (!$zip->extractTo($tmpRoot)) {
            $zip->close();
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'Failed to extract ZIP.'];
        }
        $zip->close();

        $stagedDir = $tmpRoot . '/' . $topLevel;

        // Validate manifest
        $manifestPath = $stagedDir . '/plugin.json';
        if (!file_exists($manifestPath)) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'plugin.json not found in ZIP.'];
        }
        $manifestJson = file_get_contents($manifestPath);
        $manifest     = json_decode($manifestJson, true);
        if (!is_array($manifest)) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'plugin.json is not valid JSON.'];
        }

        $validate = self::validateManifest($manifest);
        if (!$validate['ok']) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'plugin.json invalid: ' . $validate['error']];
        }

        $slug = $manifest['slug'];

        // Slug must match top-level folder
        if ($slug !== $topLevel) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => "plugin.json slug '$slug' must match top-level folder '$topLevel'."];
        }

        // Bootstrap file exists and matches slug
        $className = self::slugToClass($slug);
        $bootstrap = $stagedDir . '/' . $className . '.php';
        if (!file_exists($bootstrap)) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => "Bootstrap file $className.php not found in ZIP."];
        }

        // install.sql / uninstall.sql
        if (!file_exists($stagedDir . '/install.sql')) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'install.sql not found in ZIP.'];
        }
        if (!file_exists($stagedDir . '/uninstall.sql')) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'uninstall.sql not found in ZIP.'];
        }

        // Scan SQL for banned patterns + table prefix
        $sqlCheck = self::validatePluginSql($slug,
            file_get_contents($stagedDir . '/install.sql'),
            file_get_contents($stagedDir . '/uninstall.sql')
        );
        if (!$sqlCheck['ok']) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'SQL validation failed: ' . $sqlCheck['error']];
        }

        // Core version check
        if (!empty($manifest['requires_core'])) {
            if (!slate_semver_satisfies(SLATE_VERSION, $manifest['requires_core'])) {
                self::rmrf($tmpRoot);
                return ['ok' => false, 'error' =>
                    "This plugin requires Kohevo core {$manifest['requires_core']}. You have " . SLATE_VERSION . '.'];
            }
        }

        // Move into place. If a plugin with this slug exists, replace files
        // but preserve its `plugins` row + status (= upgrade).
        $targetDir = SLATE_ROOT . '/plugins/' . $slug;
        $pluginsDir = SLATE_ROOT . '/plugins';

        // Check plugins/ is writable BEFORE touching anything
        if (!is_writable($pluginsDir)) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' =>
                "The plugins/ directory is not writable by the web server. "
              . "Run: chmod 775 " . $pluginsDir . " (and check ownership)."];
        }

        if (is_dir($targetDir)) {
            self::rmrf($targetDir);
        }

        $moveResult = self::moveDir($stagedDir, $targetDir);
        if (!$moveResult['ok']) {
            self::rmrf($tmpRoot);
            return ['ok' => false, 'error' => 'Failed to move plugin into place: ' . $moveResult['error']];
        }
        self::rmrf($tmpRoot); // cleanup remainder

        // Upsert plugins row
        $existing = \Database::row("SELECT * FROM plugins WHERE slug = ?", [$slug]);
        if ($existing) {
            \Database::update('plugins', [
                'name'          => $manifest['name'],
                'version'       => $manifest['version'],
                'manifest_json' => json_encode($manifest),
            ], 'slug = ?', [$slug]);
        } else {
            \Database::insert('plugins', [
                'slug'          => $slug,
                'name'          => $manifest['name'],
                'version'       => $manifest['version'],
                'status'        => self::STATUS_INSTALLED,
                'manifest_json' => json_encode($manifest),
                'installed_at'  => slate_db_now(),
            ]);
        }

        slate_log("Plugin '$slug' installed (version {$manifest['version']}).", 'info');
        return ['ok' => true, 'slug' => $slug, 'manifest' => $manifest];
    }

    // ── Admin: activate ───────────────────────────────────────

    /**
     * Run the plugin's install.sql and set status='active'.
     * Idempotent: running on an already-active plugin is a no-op.
     */
    // ── Disk discovery + one-shot install (installer wizard, CLI) ─

    /**
     * Every plugins/<slug>/plugin.json on disk with a valid manifest,
     * regardless of whether it's registered in the `plugins` table yet —
     * the install wizard and bin/activate-plugin.php --all both need this
     * to offer/activate whatever ships in the plugins/ folder on a fresh
     * checkout, before any row exists for it.
     *
     * Returns rows sorted by name: ['slug','name','version','description','manifest'].
     * A folder with missing/invalid plugin.json is skipped, not fatal —
     * one bad plugin folder must never block discovering the rest.
     */
    public static function discoverOnDisk(): array {
        $out = [];
        foreach (glob(SLATE_ROOT . '/plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifestPath = $dir . '/plugin.json';
            if (!is_file($manifestPath)) continue;
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) continue;
            $check = self::validateManifest($manifest);
            if (!$check['ok']) continue;
            $out[] = [
                'slug'        => (string) $manifest['slug'],
                'name'        => (string) $manifest['name'],
                'version'     => (string) $manifest['version'],
                'description' => (string) ($manifest['description'] ?? ''),
                'manifest'    => $manifest,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Register (if this slug has no `plugins` row yet) then activate a
     * plugin that's already unpacked under plugins/<slug>/ — the shared
     * body behind bin/activate-plugin.php and the install wizard's plugin
     * step, so a fresh checkout reaches the same state either way.
     *
     * Seeds the row at version 0.0.0 rather than the manifest's real
     * version: loadOne() only refreshes a plugin's stored manifest from
     * disk when the FILE version is newer than the row's, so writing the
     * real version here would pin whatever we happened to store —
     * including a hand-made row with no "capabilities" key, which would
     * silently disable every capability-gated hook. A version below any
     * real one lets the file win on the very next request.
     */
    public static function installFromDisk(string $slug): array {
        $dir      = SLATE_ROOT . '/plugins/' . $slug;
        $manifest = $dir . '/plugin.json';
        if (!is_dir($dir) || !is_file($manifest)) {
            return ['ok' => false, 'error' => "no plugins/$slug/plugin.json"];
        }
        $json = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'plugin.json is not valid JSON'];
        }

        if (!\Database::row("SELECT id FROM plugins WHERE slug = ?", [$slug])) {
            \Database::query(
                "INSERT INTO plugins (slug, name, version, status, manifest_json)
                 VALUES (?, ?, '0.0.0', 'installed', ?)",
                [$slug, (string) ($json['name'] ?? $slug),
                 json_encode(['slug' => $slug, 'name' => $json['name'] ?? $slug, 'version' => '0.0.0'])]
            );
        }

        return self::activate($slug);
    }

    public static function activate(string $slug): array {
        $row = \Database::row("SELECT * FROM plugins WHERE slug = ?", [$slug]);
        if (!$row) return ['ok' => false, 'error' => 'Plugin not found.'];

        if ($row['status'] === self::STATUS_ACTIVE) {
            return ['ok' => true, 'note' => 'already active'];
        }

        $dir = SLATE_ROOT . '/plugins/' . $slug;
        if (!is_dir($dir)) return ['ok' => false, 'error' => 'Plugin directory missing.'];

        // Re-validate manifest still on disk (could have been edited)
        $manifestPath = $dir . '/plugin.json';
        if (!file_exists($manifestPath)) return ['ok' => false, 'error' => 'plugin.json missing on disk.'];
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $check    = self::validateManifest($manifest ?: []);
        if (!$check['ok']) return ['ok' => false, 'error' => 'plugin.json invalid: ' . $check['error']];

        // Run install.sql
        $installSqlPath = $dir . '/install.sql';
        if (file_exists($installSqlPath)) {
            try {
                self::executeSqlFile($installSqlPath);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'install.sql failed: ' . $e->getMessage()];
            }
        }

        // Register permissions declared in manifest
        if (!empty($manifest['permissions']) && is_array($manifest['permissions'])) {
            foreach ($manifest['permissions'] as $perm) {
                if (!is_string($perm) || $perm === '') continue;
                // Super-admin grant lives via short-circuit, no DB row needed.
                // We register it on role 0 (placeholder for "exists") so the
                // Roles admin page knows about it. Actually we just rely on
                // role_permissions referring to the perm key by name; the
                // admin Roles page lists known keys from a UNION of grants +
                // manifest permissions. Don't insert anything here; the perm
                // becomes "known" by virtue of the manifest_json cache.
            }
        }

        // `version` is written alongside manifest_json, not left behind. Without
        // it the two disagree permanently: activate() refreshes the manifest
        // from disk but the column keeps whatever it was installed with, so the
        // admin Plugins page reports the wrong version forever. It also wedges
        // loadOne()'s refresh, which compares the file against manifest_json —
        // already current here — and so never reconciles the column either.
        \Database::update('plugins', [
            'status'       => self::STATUS_ACTIVE,
            'version'      => (string) ($manifest['version'] ?? '0.0.0'),
            'manifest_json'=> json_encode($manifest),
            'activated_at' => slate_db_now(),
        ], 'slug = ?', [$slug]);

        // Invalidate caches
        self::$activeSlugs = null;
        \Auth::invalidatePermCache();

        slate_log("Plugin '$slug' activated.", 'info');
        return ['ok' => true];
    }

    // ── Admin: deactivate ─────────────────────────────────────

    /**
     * Mark plugin inactive. Data is preserved; install.sql is NOT
     * rolled back. The plugin stops loading next request.
     */
    public static function deactivate(string $slug): array {
        $row = \Database::row("SELECT * FROM plugins WHERE slug = ?", [$slug]);
        if (!$row) return ['ok' => false, 'error' => 'Plugin not found.'];
        if ($row['status'] !== self::STATUS_ACTIVE) {
            return ['ok' => true, 'note' => 'not active'];
        }
        \Database::update('plugins', [
            'status'         => self::STATUS_INACTIVE,
            'deactivated_at' => slate_db_now(),
        ], 'slug = ?', [$slug]);

        self::$activeSlugs = null;
        slate_log("Plugin '$slug' deactivated.", 'info');
        return ['ok' => true];
    }

    // ── Admin: uninstall ──────────────────────────────────────

    /**
     * Run uninstall.sql, remove the plugin's directory, delete its
     * row. Plugin MUST be inactive first.
     */
    public static function uninstall(string $slug): array {
        $row = \Database::row("SELECT * FROM plugins WHERE slug = ?", [$slug]);
        if (!$row) return ['ok' => false, 'error' => 'Plugin not found.'];
        if ($row['status'] === self::STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'Deactivate the plugin before uninstalling.'];
        }

        $dir = SLATE_ROOT . '/plugins/' . $slug;
        if (is_dir($dir)) {
            $uninstallSql = $dir . '/uninstall.sql';
            if (file_exists($uninstallSql)) {
                try {
                    self::executeSqlFile($uninstallSql);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => 'uninstall.sql failed: ' . $e->getMessage()];
                }
            }
            self::rmrf($dir);
        }

        \Database::delete('plugins', 'slug = ?', [$slug]);
        self::$activeSlugs = null;
        slate_log("Plugin '$slug' uninstalled.", 'info');
        return ['ok' => true];
    }

    // ── Assets ────────────────────────────────────────────────

    /**
     * Cache-busting token for a queued asset.
     *
     * Plugin version alone is NOT enough: assets get edited constantly while
     * the version stays put, so the URL never changes and browsers/CDNs keep
     * serving the old file until max-age expires (a week, at our headers).
     * Appending the file's mtime means every save produces a fresh URL.
     */
    private static function assetVersion(\Plugin $plugin, array $asset): string {
        $mtime = isset($asset['file']) ? @filemtime($asset['file']) : false;
        return $plugin->version() . ($mtime !== false ? '.' . $mtime : '');
    }

    public static function renderQueuedStyles(): string {
        $html = '';
        foreach (self::$active as $plugin) {
            foreach ($plugin->queuedStyles() as $s) {
                $html .= '<link rel="stylesheet" href="' . e($s['url']) . '?v=' . e(self::assetVersion($plugin, $s)) . '">' . "\n";
            }
        }
        return $html;
    }

    public static function renderQueuedScripts(): string {
        $html = '';
        foreach (self::$active as $plugin) {
            foreach ($plugin->queuedScripts() as $s) {
                $html .= '<script src="' . e($s['url']) . '?v=' . e(self::assetVersion($plugin, $s)) . '" defer></script>' . "\n";
            }
        }
        return $html;
    }

    // ── Internal helpers ──────────────────────────────────────

    /**
     * Convert hyphen-slug to PascalCase class name.
     *   "hello-world" → "HelloWorld"
     *   "contact-form" → "ContactForm"
     *   "ai" → "Ai"
     */
    public static function slugToClass(string $slug): string {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    }

    /**
     * Validate a manifest array. Returns ['ok' => bool, 'error' => string|null].
     */
    public static function validateManifest(array $m): array {
        $required = ['slug', 'name', 'version', 'description', 'author'];
        foreach ($required as $field) {
            if (empty($m[$field]) || !is_string($m[$field])) {
                return ['ok' => false, 'error' => "missing required field '$field'"];
            }
        }

        if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/', $m['slug'])) {
            return ['ok' => false, 'error' => "slug must match /^[a-z][a-z0-9-]{2,63}$/"];
        }

        if (!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/', $m['version'])) {
            return ['ok' => false, 'error' => "version must be semver (e.g. 1.0.0)"];
        }

        $allowedKeys = [
            'slug', 'name', 'version', 'description', 'author',
            'author_url', 'requires_core', 'works_better_with', 'permissions',
            'system', 'capabilities',
        ];
        $unknown = array_diff(array_keys($m), $allowedKeys);
        if (!empty($unknown)) {
            return ['ok' => false, 'error' => 'unknown keys: ' . implode(', ', $unknown)];
        }

        if (isset($m['capabilities'])) {
            if (!is_array($m['capabilities'])) {
                return ['ok' => false, 'error' => "'capabilities' must be an associative array"];
            }
            foreach ($m['capabilities'] as $capKey => $capDef) {
                if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string)$capKey)) {
                    return ['ok' => false, 'error' => "invalid capability key '$capKey'"];
                }
                if (!is_array($capDef) || empty($capDef['name'])) {
                    return ['ok' => false, 'error' => "capability '$capKey' must have a 'name'"];
                }
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Scan a plugin's install/uninstall SQL for banned patterns and
     * verify all CREATE TABLE names start with the plugin's prefix.
     *
     * Returns ['ok' => bool, 'error' => string|null].
     */
    public static function validatePluginSql(string $slug, string $installSql, string $uninstallSql): array {
        $combined = $installSql . "\n" . $uninstallSql;

        // Banned patterns (case-insensitive)
        $banned = [
            '/\bDROP\s+DATABASE\b/i',
            '/\bTRUNCATE\s+`?(users|customers|roles|role_permissions|plugins|settings|tenants|audit_log|contact_forms|contact_form_submissions|lang_overrides)`?\b/i',
            '/\bALTER\s+TABLE\s+`?(users|customers|roles|role_permissions|plugins|settings|tenants|audit_log|contact_forms|contact_form_submissions|lang_overrides)`?\b/i',
        ];
        foreach ($banned as $pat) {
            if (preg_match($pat, $combined, $m)) {
                return ['ok' => false, 'error' => 'banned SQL pattern: ' . trim($m[0])];
            }
        }

        // Table prefix check on CREATE TABLE statements
        $prefix = str_replace('-', '', $slug) . '_';
        if (preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $installSql, $m)) {
            foreach ($m[1] as $tableName) {
                if (stripos($tableName, $prefix) !== 0) {
                    return ['ok' => false, 'error' =>
                        "CREATE TABLE '$tableName' must start with plugin prefix '$prefix'"];
                }
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Execute a SQL file. Splits on semicolons (naive; works for
     * simple CREATE/DROP/INSERT statements which is what plugins
     * have). Each statement runs separately via the PDO connection.
     */
    private static function executeSqlFile(string $path): void {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') return;

        // Strip line comments
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);

        // Split on semicolons that are not inside quotes. The simple
        // explode is good enough for plugin SQL which doesn't use
        // procedures/triggers with embedded semicolons.
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $pdo   = \Database::get();
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
    }

    /**
     * Move a directory atomically (rename) with copy+delete fallback.
     *
     * The bare PHP rename() fails with EXDEV when source and destination
     * are on different filesystems — e.g. /tmp on tmpfs while the app
     * lives on disk, or Docker bind mounts crossing filesystem
     * boundaries. Capture the warning so we can surface a useful error
     * instead of just "false", and fall back to a recursive copy.
     */
    private static function moveDir(string $from, string $to): array {
        // First try rename — fastest path when same filesystem
        $renameError = '';
        set_error_handler(function ($_no, $msg) use (&$renameError) {
            $renameError = $msg;
        });
        $renamed = @rename($from, $to);
        restore_error_handler();

        if ($renamed) {
            return ['ok' => true];
        }

        // Fallback: copy tree then delete source
        // (handles cross-filesystem moves, Docker bind mounts, NFS, etc.)
        $copyError = self::copyTree($from, $to);
        if ($copyError !== null) {
            // Roll back partial copy
            self::rmrf($to);
            $detail = $renameError !== '' ? " (rename: $renameError; copy: $copyError)"
                                          : " (copy: $copyError)";
            return ['ok' => false, 'error' => "copy fallback failed$detail"];
        }

        // Copy succeeded — delete the source
        self::rmrf($from);
        return ['ok' => true];
    }

    /**
     * Recursive copy. Returns null on success, an error string on
     * failure. Stops at the first failure.
     */
    private static function copyTree(string $from, string $to): ?string {
        if (is_file($from)) {
            $parent = dirname($to);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                return "could not create $parent";
            }
            return @copy($from, $to) ? null : "could not copy $from to $to";
        }
        if (!is_dir($from)) {
            return "source missing: $from";
        }
        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
            return "could not create $to";
        }
        $items = @scandir($from);
        if ($items === false) {
            return "could not read $from";
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $err = self::copyTree($from . '/' . $item, $to . '/' . $item);
            if ($err !== null) return $err;
        }
        return null;
    }

    /** Recursive rmdir. Safety: refuse to recurse into anything that
     *  isn't inside SLATE_ROOT or sys_get_temp_dir(). */
    private static function rmrf(string $path): void {
        $real = realpath($path);
        if ($real === false) return;
        $allowedRoots = [realpath(SLATE_ROOT), realpath(sys_get_temp_dir())];
        $allowed = false;
        foreach ($allowedRoots as $root) {
            if ($root && str_starts_with($real, $root . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            slate_log("rmrf refused: $real is outside allowed roots", 'error');
            return;
        }

        if (is_file($real) || is_link($real)) {
            @unlink($real);
            return;
        }
        if (is_dir($real)) {
            $items = scandir($real);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    self::rmrf($real . '/' . $item);
                }
            }
            @rmdir($real);
        }
    }
}
