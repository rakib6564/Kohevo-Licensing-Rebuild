<?php
/**
 * Kohevo Studio — `membership.plans` renderer (links to the Membership module's own `/membership` route).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class MembershipPlansRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'membership.plans';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->rows('items');
        if ($rows === null) {
            return CatalogFormat::unavailable($scope);
        }

        $heading    = $scope->string('heading');
        $showPrices = $scope->bool('show_prices', true);
        $ctaLabel   = $scope->string('cta_label') !== '' ? $scope->string('cta_label') : Html::t('studio_join_now', 'Join now');

        $items = '';
        foreach ($rows as $row) {
            $price = $showPrices ? CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null) : '';
            $desc  = CatalogFormat::str($row, 'description');
            $items .= '<li class="sb-catalog__item">'
                . '<h3 class="sb-catalog__name">' . Html::e(CatalogFormat::str($row, 'name')) . '</h3>'
                . ($desc !== '' ? '<p class="sb-catalog__description">' . Html::text($desc) . '</p>' : '')
                . ($price !== '' ? '<p class="sb-catalog__meta"><span class="sb-catalog__price">' . Html::e($price) . '</span></p>' : '')
                . '</li>';
        }

        return '<div class="sb-catalog sb-catalog--membership">'
            . ($heading !== '' ? '<h2 class="sb-catalog__title">' . Html::e($heading) . '</h2>' : '')
            . ($items !== ''
                ? '<ul class="sb-catalog__list">' . $items . '</ul>'
                    . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('membership')) . '">' . Html::e($ctaLabel) . '</a></p>'
                : CatalogFormat::empty(Html::t('studio_no_plans', 'No plans are available right now.')))
            . '</div>';
    }
}
