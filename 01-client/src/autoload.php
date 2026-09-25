<?php
/**
 * Slate — hand-rolled PSR-4 autoloader.
 *
 * Maps the `Slate\` root namespace to this `src/` directory, per the Core
 * Platform Foundation Standard (docs/03-Standards/platform-foundation.md §4).
 *
 * WHY hand-rolled (not Composer's): Slate deploys by upload to shared hosting
 * with no `composer install` step (ADR-0001). We follow the PSR-4 *standard*
 * without depending on Composer's generated autoloader at runtime.
 *
 * ADDITIVE + COEXISTENT: this only registers a way to load `Slate\*` classes
 * (which are new). It does not replace or interfere with the existing
 * `require_once` chain or any global class. Registered near the top of the
 * bootstrap; `src/compat/aliases.php` (loaded right after) bridges old global
 * names to migrated `Slate\*` classes so existing code and plugins keep working.
 *
 * Phase 1 A1. Safe to include more than once (idempotent registration guard).
 */

declare(strict_types=1);

(static function (): void {
    if (defined('SLATE_AUTOLOAD_REGISTERED')) {
        return;
    }
    define('SLATE_AUTOLOAD_REGISTERED', true);

    $prefix  = 'Slate\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;
    $len     = strlen($prefix);

    spl_autoload_register(static function (string $class) use ($prefix, $baseDir, $len): void {
        // Only handle classes in the Slate\ namespace; ignore everything else.
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);                       // e.g. Kernel\Ping
        $path     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
})();
