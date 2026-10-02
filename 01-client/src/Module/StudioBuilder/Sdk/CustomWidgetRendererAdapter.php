<?php
/**
 * Kohevo Studio (studio-builder) — Custom Widget Renderer Adapter.
 *
 * Adapts third-party callable or object renderers to `BlockRendererInterface`.
 * Provides fail-closed execution sandboxing so a failing third-party widget
 * cannot crash the entire public page or expose raw database/stack traces.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Runtime\StudioLog;

final class CustomWidgetRendererAdapter implements BlockRendererInterface
{
    /** @var callable */
    private $renderer;

    /**
     * @param string $type Block type identifier
     * @param callable|BlockRendererInterface $renderer
     * @param bool $isDynamic
     */
    public function __construct(
        private readonly string $type,
        callable|BlockRendererInterface $renderer,
        private readonly bool $isDynamic = false,
    ) {
        if ($renderer instanceof BlockRendererInterface) {
            $this->renderer = [$renderer, 'render'];
        } else {
            $this->renderer = $renderer;
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isDynamic(): bool
    {
        return $this->isDynamic;
    }

    public function render(BlockRenderScope $scope): string
    {
        try {
            $result = ($this->renderer)($scope);
            return is_string($result) ? $result : '';
        } catch (\Throwable $e) {
            StudioLog::failure('custom_widget', 'render', $e);
            // Fail closed: render empty or debug notice in preview mode
            if ($scope->showsDiagnostics()) {
                return '<div class="sb-widget-error" data-sb-widget="' . htmlspecialchars($this->type, ENT_QUOTES, 'UTF-8') . '">'
                    . 'Error rendering widget: ' . htmlspecialchars($this->type, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            return '';
        }
    }
}
