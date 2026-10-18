<?php
/**
 * Kohevo Studio — `theme.post_content` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;

final class PostContentRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_content';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $content = (string) $scope->prop('content');
        if ($content === '') {
            $content = (string) $scope->context()->attribute('content', $scope->context()->attribute('post_content', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'));
        }

        // Plain text keeps its line breaks. Anything with markup goes through
        // the rich-text allowlist: the fallback is a plain text field an
        // editor types into, and its write-time check does not catch event
        // handler attributes (`<img src=x onerror=…>`), so it must never be
        // emitted raw.
        $body = strip_tags($content) === $content ? nl2br(Html::e($content)) : RichTextSanitizer::sanitize($content);
        return '<div class="sb-post-content">' . $body . '</div>';
    }
}
