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
     */
    public static function standard(
        array $contentControls = [],
        array $styleControls = [],
        array $advancedControls = [],
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
        if ($advancedControls !== []) {
            $schema->addTab(self::TAB_ADVANCED, 'Advanced', [
                ['id' => 'advanced', 'label' => 'Advanced Settings', 'controls' => $advancedControls],
            ]);
        }
        return $schema;
    }
}
