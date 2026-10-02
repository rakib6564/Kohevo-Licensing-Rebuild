<?php
/**
 * Kohevo Studio (studio-builder) — Widget Security & Capability Validator.
 *
 * Enforces fail-closed isolation rules for third-party widgets per Section 32:
 * - Namespaced type convention (e.g. `vendor.widget_name`)
 * - Forbids hijacking reserved core/system namespaces (`core`, `layout`, `theme`, `system`, `slate`, `studio`)
 * - Schema safety: only supported FieldSchema types, no collision with canonical block keys
 * - Permission bounds: cannot declare privileges beyond the canonical Studio permission matrix
 * - Renderer isolation: read-only BlockRenderScope execution, cannot mutate database or switch tenants
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class WidgetSecurityValidator
{
    public const RESERVED_NAMESPACES = [
        'core',
        'layout',
        'theme',
        'system',
        'slate',
        'studio',
    ];

    public const FORBIDDEN_PROPERTY_KEYS = [
        'id',
        'type',
        'version',
        'props',
        'style',
        'visibility',
        'bindings',
        'children',
        'tenant_id',
        'animation',
        'interactions',
        'responsive',
        'attributes',
        'classNames',
    ];

    public const ALLOWED_PERMISSIONS = [
        'studio-builder.view',
        'studio-builder.edit',
        'studio-builder.publish',
        'studio-builder.tokens',
        'studio-builder.admin',
    ];

    /**
     * Validate a custom widget definition array or object.
     *
     * @param array<string, mixed> $data
     * @param bool $allowReservedNamespace Used only for core testing shims
     * @throws StudioValidationException if any security invariant is violated
     */
    public static function validate(array $data, bool $allowReservedNamespace = false): void
    {
        // 1. Type validation
        $type = (string) ($data['type'] ?? $data['key'] ?? '');
        if (preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $type) !== 1) {
            throw new StudioValidationException([['path' => '$.type', 'code' => 'invalid_widget_type', 'message' => "Invalid widget type '{$type}'. Must match namespaced format 'vendor.widget_name'."]]);
        }

        $parts = explode('.', $type, 2);
        $namespace = $parts[0];
        if (!$allowReservedNamespace && in_array($namespace, self::RESERVED_NAMESPACES, true)) {
            throw new StudioValidationException([['path' => '$.type', 'code' => 'reserved_namespace', 'message' => "Namespace '{$namespace}' in '{$type}' is reserved for Studio core and cannot be registered by third-party extensions."]]);
        }

        // 2. Version validation
        $version = (int) ($data['version'] ?? 1);
        if ($version < 1) {
            throw new StudioValidationException([['path' => '$.version', 'code' => 'invalid_version', 'message' => "Widget '{$type}' version must be an integer >= 1."]]);
        }

        // 3. Label validation
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new StudioValidationException([['path' => '$.label', 'code' => 'empty_label', 'message' => "Widget '{$type}' must specify a non-empty human-readable label."]]);
        }

        // 4. Permission bounds
        $perm = (string) ($data['required_permission'] ?? 'studio-builder.edit');
        if (!in_array($perm, self::ALLOWED_PERMISSIONS, true)) {
            throw new StudioValidationException([['path' => '$.required_permission', 'code' => 'unauthorized_permission', 'message' => "Widget '{$type}' declared unauthorized permission '{$perm}'."]]);
        }

        // 5. Schema validation
        $schema = $data['schema'] ?? null;
        if (is_array($schema)) {
            foreach ($schema as $field) {
                if (is_array($field) && isset($field['key'])) {
                    $key = (string) $field['key'];
                    if (in_array($key, self::FORBIDDEN_PROPERTY_KEYS, true)) {
                        throw new StudioValidationException([['path' => "$.schema.{$key}", 'code' => 'reserved_property_key', 'message' => "Property key '{$key}' in widget '{$type}' is a reserved canonical document key."]]);
                    }
                    $fieldType = (string) ($field['type'] ?? '');
                    if (!in_array($fieldType, FieldSchema::SUPPORTED_TYPES, true)) {
                        throw new StudioValidationException([['path' => "$.schema.{$key}", 'code' => 'unsupported_field_type', 'message' => "Field '{$key}' in widget '{$type}' uses unsupported type '{$fieldType}'."]]);
                    }
                }
            }
        }

        // 6. Renderer presence
        $renderer = $data['renderer'] ?? null;
        if ($renderer === null) {
            throw new StudioValidationException([['path' => '$.renderer', 'code' => 'missing_renderer', 'message' => "Widget '{$type}' must provide a server renderer (BlockRendererInterface or callable)."]]);
        }
    }
}
