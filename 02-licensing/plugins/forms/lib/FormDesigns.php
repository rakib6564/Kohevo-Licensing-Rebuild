<?php
/**
 * Forms — design preset library.
 *
 * A curated set of named visual "designs" for the public form. Each is
 * just a bundle of the same appearance settings the Appearance tab
 * already exposes (accent, hover, field_style, shape, density, animate,
 * labels), so picking one is a one-click way to restyle a form — and
 * everything stays editable afterwards.
 */

class FormDesigns {

    /** All design presets, in display order. */
    public static function all(): array {
        return [
            [
                'key' => 'classic', 'name' => 'Classic', 'desc' => 'Clean white fields, blue accent.',
                'accent' => '', // empty = use the site theme accent
                'field_style' => 'outline', 'shape' => 'rounded', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#2563EB',
            ],
            [
                'key' => 'minimal', 'name' => 'Minimal', 'desc' => 'Monochrome, square, label-light.',
                'accent' => '#111827',
                'field_style' => 'outline', 'shape' => 'square', 'density' => 'compact',
                'animate' => false, 'labels' => 'hide', 'swatch' => '#111827',
            ],
            [
                'key' => 'soft', 'name' => 'Soft', 'desc' => 'Filled fields, gentle indigo.',
                'accent' => '#4F46E5',
                'field_style' => 'filled', 'shape' => 'rounded', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#4F46E5',
            ],
            [
                'key' => 'playful', 'name' => 'Playful', 'desc' => 'Pill buttons, vivid pink.',
                'accent' => '#DB2777',
                'field_style' => 'filled', 'shape' => 'pill', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#DB2777',
            ],
            [
                'key' => 'editorial', 'name' => 'Editorial', 'desc' => 'Bold, squared, near-black.',
                'accent' => '#0F172A',
                'field_style' => 'outline', 'shape' => 'square', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#0F172A',
            ],
            [
                'key' => 'sunset', 'name' => 'Sunset', 'desc' => 'Warm amber, rounded.',
                'accent' => '#EA580C',
                'field_style' => 'outline', 'shape' => 'rounded', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#EA580C',
            ],
            [
                'key' => 'forest', 'name' => 'Forest', 'desc' => 'Calm green, filled fields.',
                'accent' => '#059669',
                'field_style' => 'filled', 'shape' => 'rounded', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#059669',
            ],
            [
                'key' => 'ocean', 'name' => 'Ocean', 'desc' => 'Teal, pill buttons.',
                'accent' => '#0891B2',
                'field_style' => 'outline', 'shape' => 'pill', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#0891B2',
            ],
            [
                'key' => 'royal', 'name' => 'Royal', 'desc' => 'Rich violet, soft fields.',
                'accent' => '#7C3AED',
                'field_style' => 'filled', 'shape' => 'rounded', 'density' => 'comfortable',
                'animate' => true, 'labels' => 'show', 'swatch' => '#7C3AED',
            ],
            [
                'key' => 'compacted', 'name' => 'Compact pro', 'desc' => 'Dense, outlined, efficient.',
                'accent' => '#2563EB',
                'field_style' => 'outline', 'shape' => 'rounded', 'density' => 'compact',
                'animate' => true, 'labels' => 'show', 'swatch' => '#2563EB',
            ],
        ];
    }
}
