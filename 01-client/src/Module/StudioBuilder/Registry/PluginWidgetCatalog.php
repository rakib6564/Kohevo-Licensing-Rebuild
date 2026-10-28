<?php
/**
 * Kohevo Studio (studio-builder) — widgets that Kohevo plugins bring.
 *
 * A plugin offers Studio widgets by shipping `studio-widgets.php` in its folder. The file returns
 *
 *   ['blocks' => list<BlockDefinitionInterface>, 'renderers' => list<BlockRendererInterface>,
 *    'providers' => list<DataProviderInterface>]
 *
 * Every INSTALLED plugin's file is read, active or not: a page that holds a booking block must still
 * open, validate and save when Booking is switched off. Whether a widget can be used is the
 * entitlement's job — `EntitlementService::canAccess()` needs the plugin active and licensed, so an
 * inactive plugin's widgets are listed as locked in Add › Components and render nothing publicly.
 *
 * A broken file is logged and skipped; it never takes Studio down. The catalogue is read once per
 * request (definitions, renderers and providers are immutable/stateless).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Provider\DataProviderInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Runtime\StudioLog;

final class PluginWidgetCatalog
{
    public const FILE = 'studio-widgets.php';

    /** @var array<string, array{blocks: list<BlockDefinitionInterface>, renderers: list<BlockRendererInterface>, providers: list<DataProviderInterface>}> */
    private static array $cache = [];

    /** @return list<BlockDefinitionInterface> */
    public static function blocks(?string $pluginsDir = null): array
    {
        return self::load($pluginsDir)['blocks'];
    }

    /** @return list<BlockRendererInterface> */
    public static function renderers(?string $pluginsDir = null): array
    {
        return self::load($pluginsDir)['renderers'];
    }

    /** @return list<DataProviderInterface> */
    public static function providers(?string $pluginsDir = null): array
    {
        return self::load($pluginsDir)['providers'];
    }

    /** The folder plugins are installed in (next to `src/`). */
    public static function pluginsDir(): string
    {
        $root = defined('SLATE_ROOT') ? (string) SLATE_ROOT : dirname(__DIR__, 4);
        return rtrim($root, '/') . '/plugins';
    }

    /** Forget what was read (tests that add or remove a plugin folder). */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /** @return array{blocks: list<BlockDefinitionInterface>, renderers: list<BlockRendererInterface>, providers: list<DataProviderInterface>} */
    private static function load(?string $pluginsDir): array
    {
        $dir = $pluginsDir ?? self::pluginsDir();
        if (isset(self::$cache[$dir])) {
            return self::$cache[$dir];
        }

        $out = ['blocks' => [], 'renderers' => [], 'providers' => []];
        $files = glob(rtrim($dir, '/') . '/*/' . self::FILE) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            try {
                $declared = (static fn(string $path): mixed => include $path)($file);
                if (!is_array($declared)) {
                    throw new \UnexpectedValueException('it must return an array');
                }
                foreach (['blocks' => BlockDefinitionInterface::class, 'renderers' => BlockRendererInterface::class, 'providers' => DataProviderInterface::class] as $key => $class) {
                    foreach ((array) ($declared[$key] ?? []) as $item) {
                        if (!$item instanceof $class) {
                            throw new \UnexpectedValueException("every '{$key}' entry must be a {$class}");
                        }
                        $out[$key][] = $item;
                    }
                }
            } catch (\Throwable $e) {
                StudioLog::failure('plugin_widgets', 'load ' . basename(dirname($file)), $e);
            }
        }

        return self::$cache[$dir] = $out;
    }
}
