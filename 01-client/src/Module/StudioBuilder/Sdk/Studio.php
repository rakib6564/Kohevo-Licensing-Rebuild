<?php
/**
 * Kohevo Studio (studio-builder) — Developer API Facade.
 *
 * Developer-facing entry point for Studio Builder extensions per Section 31:
 *
 * ```php
 * Studio::widgets()->register([...]);
 * ```
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

final class Studio
{
    /**
     * Access the developer Widget SDK.
     */
    public static function widgets(): WidgetSdk
    {
        return WidgetSdk::instance();
    }

    /**
     * Migrate a block or document using registered widget migrators.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        return WidgetSdk::instance()->migrate($data);
    }
}

// Global alias for third-party plugins and developer extensions
if (!class_exists('Studio', false)) {
    class_alias(Studio::class, 'Studio');
}
