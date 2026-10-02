<?php
/**
 * Kohevo Studio (studio-builder) — Developer Widget SDK.
 *
 * Public-facing SDK for registering, versioning, and loading custom third-party widgets.
 * Allows extensions and plugins to register new widgets without modifying core Studio Builder files.
 *
 * Usage:
 * ```php
 * Studio::widgets()->register([
 *     'type'     => 'acme.testimonial',
 *     'version'  => 1,
 *     'label'    => 'Customer Testimonial',
 *     'category' => 'marketing',
 *     'icon'     => 'quote',
 *     'schema'   => [
 *         ['key' => 'author', 'type' => 'string', 'label' => 'Author Name', 'required' => true],
 *         ['key' => 'quote', 'type' => 'text', 'label' => 'Quote', 'required' => true],
 *     ],
 *     'renderer' => function (BlockRenderScope $scope): string {
 *         return '<div class="testimonial">' . Html::e($scope->string('author')) . '</div>';
 *     },
 * ]);
 * ```
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Kernel\Event\Hook;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\WidgetRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;

final class WidgetSdk
{
    private static ?self $instance = null;

    /** @var array<string, CustomWidgetDefinition> */
    private array $widgets = [];

    /** @var bool */
    private bool $hooksLoaded = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a new custom widget definition.
     *
     * @param CustomWidgetDefinition|array<string, mixed> $widget
     */
    public function register(CustomWidgetDefinition|array $widget): self
    {
        $definition = $widget instanceof CustomWidgetDefinition
            ? $widget
            : CustomWidgetDefinition::fromArray($widget);

        $type = $definition->type();
        $this->widgets[$type] = $definition;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->widgets[$type]);
    }

    public function get(string $type): ?CustomWidgetDefinition
    {
        return $this->widgets[$type] ?? null;
    }

    /**
     * @return array<string, CustomWidgetDefinition>
     */
    public function all(): array
    {
        return $this->widgets;
    }

    public function count(): int
    {
        return count($this->widgets);
    }

    /**
     * Trigger plugin extension hooks to discover third-party widgets.
     */
    public function loadFromHooks(): void
    {
        if ($this->hooksLoaded) {
            return;
        }
        $this->hooksLoaded = true;

        if (class_exists(Hook::class)) {
            Hook::applyFilters('studio_register_widgets', $this);
        }
    }

    /**
     * Populate registered custom widgets onto runtime registries.
     */
    public function populate(
        BlockRegistry $blockRegistry,
        BlockRendererRegistry $rendererRegistry,
        ?WidgetRegistry $widgetRegistry = null
    ): void {
        foreach ($this->widgets as $type => $widget) {
            if (!$blockRegistry->has($type)) {
                $blockRegistry->register($widget->toBlockDefinition());
            }
            if (!$rendererRegistry->has($type)) {
                $rendererRegistry->register($widget->toBlockRenderer());
            }
            if ($widgetRegistry !== null && !$widgetRegistry->has($type)) {
                $widgetRegistry->register($widget->toBlockDefinition());
            }
        }
    }

    /**
     * Migrate block or document using registered version migrators.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function migrate(array $data): array
    {
        if (isset($data['sections'])) {
            return WidgetMigrator::migrateDocument($data);
        }
        return WidgetMigrator::migrateBlock($data);
    }

    /**
     * Reset registry for clean test isolation.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->widgets = [];
            self::$instance->hooksLoaded = false;
        }
    }

    /**
     * Convenience static proxy for `WidgetSdk::instance()->all()`.
     *
     * @return array<string, CustomWidgetDefinition>
     */
    public static function registeredWidgets(): array
    {
        return self::instance()->all();
    }
}
