<?php
/**
 * Kohevo Studio (studio-builder) — Widget Definition Contract.
 *
 * Defines the first-class contract for all visual Studio widgets:
 * - Extends BlockDefinitionInterface with visual builder metadata:
 *   - description
 *   - structured controls (Content, Style, Advanced, Responsive, etc.)
 *   - assets manifest (CSS, JS, fonts, icons)
 *   - supports flags (typography, dimensions, custom attributes, etc.)
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

interface WidgetDefinitionInterface extends BlockDefinitionInterface
{
    /**
     * User-facing description of what the widget does.
     */
    public function description(): string;

    /**
     * Structured inspector control tabs and sections (Content, Style, Advanced, Responsive).
     *
     * @return array<string, mixed>
     */
    public function controls(): array;

    /**
     * Assets required by this widget when rendered (CSS stylesheets, JS scripts, web fonts).
     *
     * @return array{css?: list<string>, js?: list<string>, fonts?: list<string>, icons?: list<string>}
     */
    public function assets(): array;

    /**
     * Feature flags supported by this widget (e.g. ['responsive_typography', 'spacing', 'dimensions']).
     *
     * @return list<string>
     */
    public function supports(): array;
}
