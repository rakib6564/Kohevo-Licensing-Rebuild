<?php
/**
 * Kohevo Studio — `theme.post_title` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostTitleRenderer implements BlockRendererInterface
{
    private const LEVELS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    private const ALIGN  = ['left', 'center', 'right'];

    public function type(): string
    {
        return 'theme.post_title';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $level = $scope->string('level', $scope->string('tag', 'h1'));
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'h1';
        }

        $classes = ['sb-post-title'];
        $align = $scope->string('align');
        if ($align !== '' && in_array($align, self::ALIGN, true)) {
            $classes[] = 'sb-post-title--align-' . $align;
        }

        // Try bound prop, then context attribute, then static fallback
        $title = (string) $scope->prop('text');
        if ($title === '') {
            $title = (string) $scope->context()->attribute('title', $scope->context()->attribute('post_title', $scope->string('text', 'Single Post Title')));
        }

        $body = Html::e($title);
        $linkToPost = $scope->bool('link_to_post', false);
        $postUrl = (string) $scope->context()->attribute('post_url', '');
        if ($linkToPost && $postUrl !== '') {
            $body = '<a href="' . Html::e($postUrl) . '">' . $body . '</a>';
        }

        return '<' . $level . Html::classAttr($classes) . '>' . $body . '</' . $level . '>';
    }
}
