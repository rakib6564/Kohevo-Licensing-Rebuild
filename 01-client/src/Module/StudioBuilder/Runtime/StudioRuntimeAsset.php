<?php
/**
 * Kohevo Studio (studio-builder) — the public interaction runtime asset.
 *
 * ONE question: is `assets/public/studio-runtime.js` available, and if so what
 * cache-busting version does it carry? Everything about WHICH script tag to emit
 * lives in `PageDocumentAssembler`; this class only resolves the asset so that
 * resolution can be tested without a plugin loader or a filesystem.
 *
 * `version()` is content-derived (sha256 of the file, truncated) rather than
 * `filemtime()`: two installs that ship the same runtime get the same version,
 * so a shared cache is shared, and an edited file always changes it — which
 * `filemtime` also gives but ties the value to checkout noise.
 *
 * `exists()` is deliberately independent of `url()`: a missing asset must not
 * produce a script tag pointing at a 404, and must never be fatal.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class StudioRuntimeAsset
{
    public const RELATIVE_PATH = 'plugins/studio-builder/assets/public/studio-runtime.js';
    private const VERSION_LENGTH = 12;

    /** @var array<string, string|null> memoised per root+relative path */
    private static array $versionCache = [];

    /**
     * The public URL of the runtime, or null when the file is not deployed.
     * `plugin_url()` is used when available so the asset follows the install's
     * configured plugin base URL on shared hosting.
     */
    public static function url(): ?string
    {
        if (!self::exists()) {
            return null;
        }
        try {
            if (\function_exists('plugin_url')) {
                $url = \plugin_url('studio-builder', 'assets/public/studio-runtime.js');
                if (\is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $ignored) {
            // Fall through to the relative path below.
        }
        return '/' . self::RELATIVE_PATH;
    }

    /** Whether the runtime file is actually present on disk. */
    public static function exists(): bool
    {
        return self::filePath() !== null;
    }

    /** Cache-busting token derived from file contents, or null when absent. */
    public static function version(): ?string
    {
        $path = self::filePath();
        if ($path === null) {
            return null;
        }
        if (isset(self::$versionCache[$path])) {
            return self::$versionCache[$path];
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            return null;
        }
        return self::$versionCache[$path] = substr($hash, 0, self::VERSION_LENGTH);
    }

    /** The full `<script>` tag, or '' when the runtime must not be emitted. */
    public static function scriptTag(): string
    {
        $url = self::url();
        $version = self::version();
        if ($url === null || $version === null) {
            return '';
        }
        $href = \Slate\Module\StudioBuilder\Render\Html::e($url . '?v=' . $version);

        // `defer` keeps the runtime off the critical path and guarantees it runs
        // after the document is parsed. There is deliberately NO inline script
        // and no `on*` attribute anywhere in this integration: the runtime is a
        // same-origin file so it needs no inline bootstrap, and an inline block
        // would be the one thing on the page a strict CSP could reject.
        return '<script src="' . $href . '" defer></script>';
    }

    /** Absolute path to the runtime, or null. Honours SLATE_ROOT when defined. */
    private static function filePath(): ?string
    {
        $candidates = [];
        if (\defined('SLATE_ROOT')) {
            $candidates[] = \SLATE_ROOT . '/' . self::RELATIVE_PATH;
        }
        // Fall back to walking up from this file: src/Module/StudioBuilder/Runtime
        $candidates[] = \dirname(__DIR__, 4) . '/' . self::RELATIVE_PATH;

        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Reset the memo (tests that swap files on disk). */
    public static function resetCache(): void
    {
        self::$versionCache = [];
    }

    private function __construct() {}
}