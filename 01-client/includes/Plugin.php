<?php
/**
 * Slate — Plugin base class.
 *
 * Every plugin's bootstrap class extends this. The base class
 * provides convenience methods for URL/path resolution, settings
 * scoped to the plugin's slug, and asset enqueuing.
 *
 * Subclasses MUST implement boot() to register hooks, nav items,
 * and any other request-time setup.
 */

abstract class Plugin {
    protected string $slug;
    protected string $version;
    protected array  $manifest;
    protected string $rootDir; // absolute filesystem path

    /** Assets queued for this request: [['file' => ..., 'url' => ...], ...] */
    protected array $queuedStyles  = [];
    protected array $queuedScripts = [];

    public function __construct(string $slug, array $manifest, string $rootDir) {
        $this->slug     = $slug;
        $this->version  = $manifest['version'] ?? '0.0.0';
        $this->manifest = $manifest;
        $this->rootDir  = rtrim($rootDir, '/');
    }

    /**
     * Override in your plugin. Called once per request, after the
     * plugin has been loaded, before any page rendering.
     */
    abstract public function boot(): void;

    // ── Identity ──────────────────────────────────────────────

    public function slug(): string {
        return $this->slug;
    }

    public function version(): string {
        return $this->version;
    }

    public function manifest(): array {
        return $this->manifest;
    }

    // ── Path / URL helpers ────────────────────────────────────

    /**
     * Absolute filesystem path inside the plugin.
     *   $this->dir()              → /path/to/plugins/hello-world
     *   $this->dir('admin')       → /path/to/plugins/hello-world/admin
     *   $this->dir('install.sql') → /path/to/plugins/hello-world/install.sql
     */
    public function dir(string $relPath = ''): string {
        return $relPath === '' ? $this->rootDir : $this->rootDir . '/' . ltrim($relPath, '/');
    }

    /**
     * Public-facing URL inside the plugin.
     *   $this->url()                       → /plugins/hello-world
     *   $this->url('admin/index.php')      → /plugins/hello-world/admin/index.php
     */
    public function url(string $relPath = ''): string {
        $base = SLATE_URL . '/plugins/' . $this->slug;
        return $relPath === '' ? $base : $base . '/' . ltrim($relPath, '/');
    }

    // ── Scoped settings ───────────────────────────────────────

    /**
     * Read a setting from the core `settings` table, automatically
     * prefixed with "<slug>." so plugins can't collide.
     */
    public function setting(string $key, $default = null) {
        $val = Database::setting($this->slug . '.' . $key);
        return $val !== null ? $val : $default;
    }

    public function setSetting(string $key, $value): void {
        Database::setSetting($this->slug . '.' . $key, $value);
    }

    // ── Asset queue ───────────────────────────────────────────

    /**
     * Queue a CSS file for this request. Path is relative to the
     * plugin's assets/css/ directory. The shell's layout calls
     * PluginLoader::renderQueuedStyles() to emit the <link> tags.
     */
    public function enqueueStyle(string $relPath): void {
        $disk = $this->dir('assets/css/' . ltrim($relPath, '/'));
        if (!file_exists($disk)) {
            slate_log("Plugin {$this->slug} enqueued missing style: $relPath", 'warning');
            return;
        }
        $this->queuedStyles[] = [
            'file' => $disk,
            'url'  => $this->url('assets/css/' . ltrim($relPath, '/')),
        ];
    }

    public function enqueueScript(string $relPath): void {
        $disk = $this->dir('assets/js/' . ltrim($relPath, '/'));
        if (!file_exists($disk)) {
            slate_log("Plugin {$this->slug} enqueued missing script: $relPath", 'warning');
            return;
        }
        $this->queuedScripts[] = [
            'file' => $disk,
            'url'  => $this->url('assets/js/' . ltrim($relPath, '/')),
        ];
    }

    public function queuedStyles(): array {
        return $this->queuedStyles;
    }

    public function queuedScripts(): array {
        return $this->queuedScripts;
    }

    // ── Capabilities ──────────────────────────────────────────

    /**
     * Return all capabilities declared by this plugin in its manifest.
     * Format: ['key' => ['name' => '...', 'description' => '...', 'default' => bool], ...]
     */
    public function capabilities(): array {
        return (array)($this->manifest['capabilities'] ?? []);
    }

    /**
     * Does this plugin declare the given capability?
     */
    public function hasCapability(string $cap): bool {
        return isset($this->manifest['capabilities'][$cap]);
    }

    /**
     * Is the capability declared and enabled for the current tenant?
     * Falls back to manifest default if no setting exists yet.
     */
    public function isCapabilityEnabled(string $cap): bool {
        if (!$this->hasCapability($cap)) return false;
        $val = $this->setting('cap.' . $cap);
        if ($val !== null) {
            return (bool)$val;
        }
        return (bool)($this->manifest['capabilities'][$cap]['default'] ?? true);
    }

    /**
     * Enable or disable a capability for the current tenant.
     */
    public function setCapabilityEnabled(string $cap, bool $enabled): void {
        $this->setSetting('cap.' . $cap, $enabled ? '1' : '0');
    }

    /**
     * Returns a list of capability keys that are currently active.
     */
    public function enabledCapabilities(): array {
        $active = [];
        foreach ($this->capabilities() as $key => $def) {
            if ($this->isCapabilityEnabled((string)$key)) {
                $active[] = (string)$key;
            }
        }
        return $active;
    }
}
