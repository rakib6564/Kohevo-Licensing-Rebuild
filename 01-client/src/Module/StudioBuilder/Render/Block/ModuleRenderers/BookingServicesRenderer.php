<?php
/**
 * Kohevo Studio — `booking.services` renderer.
 *
 * Renders the rows the `booking.services` provider returned (already tenant-
 * scoped, entitlement-checked and bounded by `DataProviderRegistry`). Links to
 * the Booking module's own public route (`/book`) — Studio never re-implements
 * the booking flow or talks to booking tables.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class BookingServicesRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'booking.services';
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
        $ctaLabel   = $scope->string('cta_label') !== '' ? $scope->string('cta_label') : Html::t('studio_book_now', 'Book now');

        $items = '';
        foreach ($rows as $row) {
            $duration = $row['duration_minutes'] ?? null;
            $price    = $showPrices ? CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null) : '';
            $desc     = CatalogFormat::str($row, 'description');
            $items   .= '<li class="sb-catalog__item">'
                . '<h3 class="sb-catalog__name">' . Html::e(CatalogFormat::str($row, 'name')) . '</h3>'
                . ($desc !== '' ? '<p class="sb-catalog__description">' . Html::text($desc) . '</p>' : '')
                . '<p class="sb-catalog__meta">'
                . (is_int($duration) && $duration > 0 ? '<span>' . Html::e($duration . ' min') . '</span>' : '')
                . ($price !== '' ? ' <span class="sb-catalog__price">' . Html::e($price) . '</span>' : '')
                . '</p></li>';
        }

        return '<div class="sb-catalog sb-catalog--booking">'
            . ($heading !== '' ? '<h2 class="sb-catalog__title">' . Html::e($heading) . '</h2>' : '')
            . ($items !== ''
                ? '<ul class="sb-catalog__list">' . $items . '</ul>'
                    . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('book')) . '">' . Html::e($ctaLabel) . '</a></p>'
                : CatalogFormat::empty(Html::t('studio_no_services', 'No services are available right now.')))
            . '</div>';
    }
}
