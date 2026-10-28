<?php

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

/**
 * Per-device values of a block's props: `block.responsive.<tablet|mobile>.props`, keyed by the prop's path (`columns`, or
 * `options.per_view` for a field inside an object field).
 *
 * Only a field its block definition marks `per_device` may differ per device, and its value passes the same field rules
 * as the desktop value. Desktop is the block's own props; a device without a value uses the renderer's default for that
 * device (a Gallery shows fewer columns on a phone, say), so an override is only ever what the author chose.
 */
final class DeviceProps
{
    public const DEVICES = ['tablet', 'mobile'];

    /**
     * The `per_device` fields of a schema by path, nested object fields included.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(FieldSchema $schema, string $prefix = ''): array
    {
        $out = [];
        foreach ($schema->fields() as $field) {
            $path = $prefix . $field['key'];
            if (($field['per_device'] ?? false) === true) {
                $out[$path] = $field;
            }
            if (($field['type'] ?? '') === 'object' && ($field['properties'] ?? null) instanceof FieldSchema) {
                $out += self::fields($field['properties'], $path . '.');
            }
        }
        return $out;
    }

    /**
     * Issues for one device's props override.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function issues(BlockDefinitionInterface $definition, string $device, mixed $props, string $path): array
    {
        if (!in_array($device, self::DEVICES, true)) {
            return [self::issue($path, "Only tablet and mobile take their own values; '{$device}' uses the block's props.")];
        }
        if (!is_array($props) || ($props !== [] && array_is_list($props))) {
            return [self::issue($path, 'A device\'s props must be an object.')];
        }
        $fields = self::fields($definition->schema());
        $errors = [];
        foreach ($props as $key => $value) {
            $key = (string) $key;
            if (!isset($fields[$key])) {
                $errors[] = self::issue("{$path}.{$key}", "'{$key}' cannot differ per device on {$definition->type()}.");
                continue;
            }
            $field = $fields[$key];
            $leaf = substr($key, (int) strrpos('.' . $key, '.'));
            $check = (new FieldSchema([['required' => true] + $field]))->validate([$leaf => $value], "{$path}");
            foreach ($check->errors() as $error) {
                $errors[] = self::issue("{$path}.{$key}", $error['message']);
            }
        }
        return $errors;
    }

    /**
     * The value a block sets for `$path` on each device (absent when the device uses the renderer's default).
     *
     * @param array<string, mixed> $block
     * @return array{tablet?: mixed, mobile?: mixed}
     */
    public static function overrides(array $block, string $path): array
    {
        $out = [];
        $responsive = is_array($block['responsive'] ?? null) ? $block['responsive'] : [];
        foreach (self::DEVICES as $device) {
            $props = $responsive[$device]['props'] ?? null;
            if (is_array($props) && array_key_exists($path, $props) && $props[$path] !== null) {
                $out[$device] = $props[$path];
            }
        }
        return $out;
    }

    /** @return array{path: string, code: string, message: string} */
    private static function issue(string $path, string $message): array
    {
        return ['path' => $path, 'code' => 'invalid_device_prop', 'message' => $message];
    }
}
