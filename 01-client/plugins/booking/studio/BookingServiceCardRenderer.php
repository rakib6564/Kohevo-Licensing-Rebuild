<?php
/**
 * Booking — `booking.service` Studio widget: one service as a card (name, description, length,
 * price) with a Book button that opens the booking page on that service.
 *
 * Bound to `booking.embed_target`, which only answers with an active service of this tenant. A
 * missing or foreign service renders nothing publicly and a notice to authors.
 */

declare(strict_types=1);

namespace Slate\Plugin\Booking\Studio;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\CatalogFormat;
use Slate\Module\StudioBuilder\Render\Html;

final class BookingServiceCardRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'booking.service';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->hasBinding('service') ? $scope->rows('service') : [];
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        $id = is_array($row) && is_int($row['id'] ?? null) ? $row['id'] : 0;
        if ($id < 1 || ($row['scope'] ?? '') !== 'service') {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_service_card_pick', 'Choose a service in the Inspector.')) . '</div>'
                : '';
        }

        $desc = CatalogFormat::str($row, 'description');
        $duration = $row['duration_minutes'] ?? null;
        $price = $scope->bool('show_price', true) ? CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null) : '';
        $length = $scope->bool('show_duration', true) && is_int($duration) && $duration > 0 ? $duration . ' min' : '';
        $cta = $scope->string('cta_label') !== '' ? $scope->string('cta_label') : Html::t('studio_book_now', 'Book now');

        return '<div class="sb-catalog sb-catalog--service">'
            . '<h3 class="sb-catalog__name">' . Html::e(CatalogFormat::str($row, 'name')) . '</h3>'
            . ($desc !== '' ? '<p class="sb-catalog__description">' . Html::text($desc) . '</p>' : '')
            . ($length !== '' || $price !== ''
                ? '<p class="sb-catalog__meta">'
                    . ($length !== '' ? '<span>' . Html::e($length) . '</span>' : '')
                    . ($price !== '' ? ($length !== '' ? ' ' : '') . '<span class="sb-catalog__price">' . Html::e($price) . '</span>' : '')
                    . '</p>'
                : '')
            . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('book') . '?service=' . $id) . '">' . Html::e($cta) . '</a></p>'
            . '</div>';
    }
}
