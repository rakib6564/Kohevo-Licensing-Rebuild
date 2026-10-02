<?php
/**
 * Kohevo Studio (studio-builder) — Structured Control Schema.
 *
 * Defines the Inspector control layout and configuration for Studio widgets:
 * - Groups controls into tabs: Content, Style, Advanced, Responsive, Dynamic, Conditions.
 * - Supports rich control types: text, textarea, select, unit, color, typography,
 *   dimensions, toggle, slider, url, icon_picker, media_picker, repeater, responsive.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Schema;

final class ControlSchema
{
    public const TAB_CONTENT    = 'content';
    public const TAB_STYLE      = 'style';
    public const TAB_LAYOUT     = 'layout';
    public const TAB_ADVANCED   = 'advanced';
    public const TAB_RESPONSIVE = 'responsive';
    public const TAB_DYNAMIC    = 'dynamic';
    public const TAB_CONDITIONS = 'conditions';

    public const ALLOWED_TABS = [
        self::TAB_CONTENT,
        self::TAB_STYLE,
        self::TAB_LAYOUT,
        self::TAB_ADVANCED,
        self::TAB_RESPONSIVE,
        self::TAB_DYNAMIC,
        self::TAB_CONDITIONS,
    ];

    /**
     * @var array<string, array{
     *   label: string,
     *   sections: list<array{
     *     id: string,
     *     label: string,
     *     controls: list<array<string, mixed>>
     *   }>
     * }>
     */
    private array $tabs = [];

    /**
     * @param array<string, array<string, mixed>> $rawTabs
     */
    public function __construct(array $rawTabs = [])
    {
        foreach ($rawTabs as $tabKey => $tabData) {
            $key = (string) $tabKey;
            $label = (string) ($tabData['label'] ?? ucfirst($key));
            $sections = is_array($tabData['sections'] ?? null) ? $tabData['sections'] : [];
            $this->tabs[$key] = [
                'label'    => $label,
                'sections' => $sections,
            ];
        }
    }

    /**
     * Create a new fluent builder.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a control tab with sections.
     *
     * @param list<array{
     *   id: string,
     *   label: string,
     *   controls: list<array<string, mixed>>
     * }> $sections
     */
    public function addTab(string $tab, string $label, array $sections): self
    {
        $this->tabs[$tab] = [
            'label'    => $label,
            'sections' => $sections,
        ];
        return $this;
    }

    /**
     * Return all configured tabs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->tabs;
    }

    /**
     * Define a standard Content/Style/Advanced control schema from a list of controls.
     *
     * @param list<array<string, mixed>> $contentControls
     * @param list<array<string, mixed>> $styleControls
     * @param list<array<string, mixed>> $advancedControls
     * @param list<array<string, mixed>> $responsiveControls
     */
    public static function standard(
        array $contentControls = [],
        array $styleControls = [],
        array $advancedControls = [],
        array $responsiveControls = [],
    ): self {
        $schema = new self();
        if ($contentControls !== []) {
            $schema->addTab(self::TAB_CONTENT, 'Content', [
                ['id' => 'general', 'label' => 'General', 'controls' => $contentControls],
            ]);
        }
        if ($styleControls !== []) {
            $schema->addTab(self::TAB_STYLE, 'Style', [
                ['id' => 'styling', 'label' => 'Appearance', 'controls' => $styleControls],
            ]);
        }
        $advanced = $advancedControls !== [] ? $advancedControls : self::advancedStandardControls();
        $schema->addTab(self::TAB_ADVANCED, 'Advanced', [
            ['id' => 'advanced', 'label' => 'Advanced Settings', 'controls' => $advanced],
        ]);
        if ($responsiveControls !== []) {
            $schema->addTab(self::TAB_RESPONSIVE, 'Responsive', [
                ['id' => 'responsive_overrides', 'label' => 'Device Overrides', 'controls' => $responsiveControls],
            ]);
        }
        return $schema;
    }

    /**
     * Standard advanced controls (CSS classes, custom attributes, Z-index).
     *
     * @return list<array<string, mixed>>
     */
    public static function advancedStandardControls(): array
    {
        return [
            ['key' => 'classNames', 'type' => 'tags', 'label' => 'CSS Classes', 'description' => 'Custom CSS classes for advanced styling.'],
            ['key' => 'attributes', 'type' => 'key_value', 'label' => 'HTML Attributes', 'description' => 'Custom data or ARIA attributes.'],
            ['key' => 'z_index', 'type' => 'number', 'label' => 'Z-Index', 'default' => 0],
        ];
    }

    /**
     * Standard typography style controls.
     *
     * @return list<array<string, mixed>>
     */
    public static function typographyControls(): array
    {
        return [
            ['key' => 'typography.size', 'type' => 'responsive_unit', 'label' => 'Font Size', 'default' => '1rem'],
            ['key' => 'typography.weight', 'type' => 'select', 'label' => 'Font Weight', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_FONT_WEIGHTS, 'default' => 'normal'],
            ['key' => 'typography.transform', 'type' => 'select', 'label' => 'Text Transform', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_TEXT_TRANSFORMS, 'default' => 'none'],
            ['key' => 'typography.line_height', 'type' => 'text', 'label' => 'Line Height', 'default' => '1.5'],
            ['key' => 'typography.letter_spacing', 'type' => 'text', 'label' => 'Letter Spacing', 'default' => 'normal'],
            ['key' => 'typography.color', 'type' => 'color', 'label' => 'Text Color', 'default' => 'inherit'],
        ];
    }

    /**
     * Standard border & radius controls.
     *
     * @return list<array<string, mixed>>
     */
    public static function borderControls(): array
    {
        return [
            ['key' => 'border.style', 'type' => 'select', 'label' => 'Border Style', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_BORDER_STYLES, 'default' => 'none'],
            ['key' => 'border.width', 'type' => 'unit', 'label' => 'Border Width', 'default' => '1px'],
            ['key' => 'border.color', 'type' => 'color', 'label' => 'Border Color', 'default' => '#e2e8f0'],
            ['key' => 'border.radius', 'type' => 'select', 'label' => 'Border Radius', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS, 'default' => 'none'],
        ];
    }

    /**
     * Standard shadow controls.
     *
     * @return list<array<string, mixed>>
     */
    public static function shadowControls(): array
    {
        return [
            ['key' => 'shadow', 'type' => 'select', 'label' => 'Box Shadow', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS, 'default' => 'none'],
        ];
    }

    /**
     * Standard responsive overrides controls.
     *
     * @return list<array<string, mixed>>
     */
    public static function responsiveOverridesControls(): array
    {
        return [
            ['key' => 'responsive.desktop.align', 'type' => 'select', 'label' => 'Desktop Alignment', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_ALIGNMENTS],
            ['key' => 'responsive.tablet.align', 'type' => 'select', 'label' => 'Tablet Alignment', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_ALIGNMENTS],
            ['key' => 'responsive.mobile.align', 'type' => 'select', 'label' => 'Mobile Alignment', 'options' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_ALIGNMENTS],
            ['key' => 'responsive.desktop.hide', 'type' => 'toggle', 'label' => 'Hide on Desktop', 'default' => false],
            ['key' => 'responsive.tablet.hide', 'type' => 'toggle', 'label' => 'Hide on Tablet', 'default' => false],
            ['key' => 'responsive.mobile.hide', 'type' => 'toggle', 'label' => 'Hide on Mobile', 'default' => false],
        ];
    }
}
