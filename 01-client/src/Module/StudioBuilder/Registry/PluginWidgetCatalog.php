<?php
/**
 * Kohevo Studio (studio-builder) — widgets that Kohevo plugins bring.
 *
 * A plugin offers Studio widgets by shipping `studio-widgets.php` in its folder. The file returns
 *
 *   ['blocks' => list<BlockDefinitionInterface>, 'renderers' => list<BlockRendererInterface>,
 *    'providers' => list<DataProviderInterface>]
 *
 * and may add
 *
 *   'parts'   => [block type => [part => ['label' => …, 'selectors' => […]]]]  the parts an author can style (BlockParts),
 *                only for the plugin's own block types;
 *   'singles' => list<PluginSingleInterface>  content types the plugin keeps in its own tables and Studio shows through
 *                a Single template at `/{prefix}/{slug}` (Shop's products).
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

    /** A part key, as BlockParts names them. */
    private const PART_KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /** @var array<string, array{blocks: list<BlockDefinitionInterface>, renderers: list<BlockRendererInterface>, providers: list<DataProviderInterface>, parts: array<string, array<string, array{label: string, selectors: list<string>}>>, singles: list<PluginSingleInterface>}> */
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

    /** @return array<string, array<string, array{label: string, selectors: list<string>}>> block type => its parts */
    public static function parts(?string $pluginsDir = null): array
    {
        return self::load($pluginsDir)['parts'];
    }

    /** @return list<PluginSingleInterface> */
    public static function singles(?string $pluginsDir = null): array
    {
        return self::load($pluginsDir)['singles'];
    }

    /** The plugin single of a content type, or null. */
    public static function single(string $contentType, ?string $pluginsDir = null): ?PluginSingleInterface
    {
        foreach (self::singles($pluginsDir) as $single) {
            if ($single->contentType() === $contentType) {
                return $single;
            }
        }
        return null;
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

    /** @return array{blocks: list<BlockDefinitionInterface>, renderers: list<BlockRendererInterface>, providers: list<DataProviderInterface>, parts: array<string, array<string, array{label: string, selectors: list<string>}>>, singles: list<PluginSingleInterface>} */
    private static function load(?string $pluginsDir): array
    {
        $dir = $pluginsDir ?? self::pluginsDir();
        if (isset(self::$cache[$dir])) {
            return self::$cache[$dir];
        }

        $out = ['blocks' => [], 'renderers' => [], 'providers' => [], 'parts' => [], 'singles' => []];
        $files = glob(rtrim($dir, '/') . '/*/' . self::FILE) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            try {
                $declared = (static fn(string $path): mixed => include $path)($file);
                if (!is_array($declared)) {
                    throw new \UnexpectedValueException('it must return an array');
                }
                $found = ['blocks' => [], 'renderers' => [], 'providers' => [], 'singles' => []];
                foreach (['blocks' => BlockDefinitionInterface::class, 'renderers' => BlockRendererInterface::class, 'providers' => DataProviderInterface::class, 'singles' => PluginSingleInterface::class] as $key => $class) {
                    foreach ((array) ($declared[$key] ?? []) as $item) {
                        if (!$item instanceof $class) {
                            throw new \UnexpectedValueException("every '{$key}' entry must be a {$class}");
                        }
                        $found[$key][] = $item;
                    }
                }
                $ownTypes = array_map(static fn(BlockDefinitionInterface $b): string => $b->type(), $found['blocks']);
                $parts = self::cleanParts($declared['parts'] ?? [], $ownTypes);
                foreach ($found['singles'] as $single) {
                    if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $single->contentType()) !== 1) {
                        throw new \UnexpectedValueException('a single content type must be a lowercase key');
                    }
                }
                foreach ($found as $key => $items) {
                    array_push($out[$key], ...$items);
                }
                $out['parts'] += $parts;
            } catch (\Throwable $e) {
                StudioLog::failure('plugin_widgets', 'load ' . basename(dirname($file)), $e);
            }
        }

        return self::$cache[$dir] = $out;
    }

    /**
     * A plugin's `parts`, checked: only its own block types, part keys BlockParts would accept, a label and one or more
     * selectors made of class names, attribute tests and combinators (they are scoped to the block when written).
     *
     * @param list<string> $ownTypes
     * @return array<string, array<string, array{label: string, selectors: list<string>}>>
     */
    private static function cleanParts(mixed $parts, array $ownTypes): array
    {
        if (!is_array($parts)) {
            throw new \UnexpectedValueException("'parts' must be an array");
        }
        $out = [];
        foreach ($parts as $type => $defs) {
            if (!in_array($type, $ownTypes, true) || !is_array($defs)) {
                throw new \UnexpectedValueException("'parts' may only name the plugin's own block types");
            }
            foreach ($defs as $key => $def) {
                $selectors = is_array($def) ? ($def['selectors'] ?? null) : null;
                if (!is_string($key) || preg_match(self::PART_KEY, $key) !== 1 || !is_string($def['label'] ?? null) || !is_array($selectors) || $selectors === []) {
                    throw new \UnexpectedValueException("part '{$type}.{$key}' needs a key, a label and selectors");
                }
                foreach ($selectors as $selector) {
                    if (!is_string($selector) || preg_match('/^[a-zA-Z0-9_\-\.\s>\[\]="~:()+*]{1,200}$/', $selector) !== 1 || str_contains($selector, '{')) {
                        throw new \UnexpectedValueException("part '{$type}.{$key}' has a selector Studio cannot scope");
                    }
                }
                $out[$type][$key] = ['label' => $def['label'], 'selectors' => array_values($selectors)]
                    + (($def['states'] ?? true) === false ? ['states' => false] : []);
            }
        }
        return $out;
    }
}
