<?php
/**
 * The language switcher's customize-panel values: what may be saved and what
 * the widget may print. The colours land in an inline `style` attribute, so
 * they are #rgb / #rrggbb hex only; style and position are fixed lists.
 */

class MLT_SwitcherSettings {

    public const STYLES    = ['dropdown', 'flags', 'list'];
    public const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'header'];
    public const DEFAULTS  = [
        'switcher_style'    => 'dropdown',
        'switcher_position' => 'bottom-right',
        'switcher_bg'       => '#111827',
        'switcher_fg'       => '#ffffff',
        'switcher_accent'   => '#111111',
    ];

    /**
     * The posted fields that are present, checked. Returns [values, errors]: values holds only fields that passed,
     * errors names each field that did not. A caller saves nothing when errors is not empty.
     *
     * @param array<string, mixed> $post
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function clean(array $post): array {
        $values = [];
        $errors = [];
        foreach (['switcher_enabled', 'switcher_style', 'switcher_position', 'switcher_bg', 'switcher_fg', 'switcher_accent'] as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $raw = is_scalar($post[$field]) ? trim((string) $post[$field]) : '';
            $ok = match ($field) {
                'switcher_enabled'  => in_array($raw, ['0', '1'], true),
                'switcher_style'    => in_array($raw, self::STYLES, true),
                'switcher_position' => in_array($raw, self::POSITIONS, true),
                default             => self::isHex($raw),
            };
            if ($ok) {
                $values[$field] = $field === 'switcher_enabled' || $field === 'switcher_style' || $field === 'switcher_position' ? $raw : strtolower($raw);
            } else {
                $errors[] = $field;
            }
        }
        return [$values, $errors];
    }

    /** A stored value for `$field` the widget may print, or the field's default when the stored one is not allowed. */
    public static function printable(string $field, mixed $stored): string {
        $value = is_scalar($stored) ? trim((string) $stored) : '';
        $ok = match ($field) {
            'switcher_style'    => in_array($value, self::STYLES, true),
            'switcher_position' => in_array($value, self::POSITIONS, true),
            default             => self::isHex($value),
        };
        return $ok ? $value : self::DEFAULTS[$field];
    }

    public static function isHex(string $value): bool {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1;
    }
}
