<?php
/**
 * Kohevo Studio — `booking.embed` renderer.
 *
 * Embeds the booking flow — the whole `/book` page or one service — through the
 * Booking module's own safe iframe embed: `/book?embed=1[&service=<id>]` plus its
 * `embed.js` helper, which sizes the frame to the widget and only trusts messages
 * from that frame's own origin. Anything that depends on a session (sign-in,
 * confirm, payment) breaks out of the frame inside Booking itself; Studio never
 * re-implements the flow or talks to booking tables. The `booking.embed_target`
 * provider has already re-checked, for the active tenant, that a chosen service
 * exists and is active.
 *
 * The Editor canvas runs no script and frames nothing, so there the block is a
 * static preview card (service name, duration, price). Preview and Public get
 * the iframe.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class BookingEmbedRenderer implements BlockRendererInterface
{
    public const MIN_HEIGHT_DEFAULT = 560;
    public const MIN_HEIGHT_MIN = 200;
    public const MIN_HEIGHT_MAX = 2400;

    public function type(): string
    {
        return 'booking.embed';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        // No binding at all is the whole booking page; a binding that did not resolve (denied, or a service that is
        // gone or not this tenant's) is "unavailable", never a fall-back to the whole page.
        $rows = $scope->hasBinding('service') ? $scope->rows('service') : [['id' => 0, 'scope' => 'page']];
        if ($rows === null || $rows === []) {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_embed_service_missing', 'This service is no longer available. Choose another in the Inspector.')) . '</div>'
                : '';
        }
        $row = $rows[0];
        $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
        $isService = $id > 0 && ($row['scope'] ?? '') === 'service';

        return $scope->mode() === RenderMode::Editor
            ? $this->previewCard($row, $isService)
            : $this->frame($scope, $isService ? $id : 0);
    }

    /** @param array<string, mixed> $row */
    private function previewCard(array $row, bool $isService): string
    {
        $desc = $isService ? CatalogFormat::str($row, 'description') : '';
        $duration = $row['duration_minutes'] ?? null;
        $price = $isService ? CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null) : '';
        $title = $isService ? CatalogFormat::str($row, 'name') : Html::t('studio_embed_booking_page', 'Your booking page');

        return '<div class="sb-form-card sb-embed-card">'
            . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_embed_booking_kind', 'Booking')) . '</p>'
            . '<h3 class="sb-form-card__title">' . Html::e($title) . '</h3>'
            . ($desc !== '' ? '<p class="sb-form-card__description">' . Html::text($desc) . '</p>' : '')
            . ($isService
                ? '<p class="sb-catalog__meta">'
                    . (is_int($duration) && $duration > 0 ? '<span>' . Html::e($duration . ' min') . '</span>' : '')
                    . ($price !== '' ? ' <span class="sb-catalog__price">' . Html::e($price) . '</span>' : '')
                    . '</p>'
                : '<p class="sb-embed-card__note">' . Html::e(Html::t('studio_embed_booking_all', 'Visitors pick a service, a time and book.')) . '</p>')
            . '<p class="sb-embed-card__note">' . Html::e(Html::t('studio_embed_booking_note', 'Visitors see the working booking flow here. Preview the page to try it.')) . '</p>'
            . '</div>';
    }

    private function frame(BlockRenderScope $scope, int $serviceId): string
    {
        $site = $scope->site();
        $src = $site->absoluteUrl('book') . '?embed=1' . ($serviceId > 0 ? '&service=' . $serviceId : '');
        $min = $scope->prop('min_height');
        $min = is_int($min) ? max(self::MIN_HEIGHT_MIN, min(self::MIN_HEIGHT_MAX, $min)) : self::MIN_HEIGHT_DEFAULT;

        $helper = $scope->claimOnce('booking-embed-js')
            ? '<script src="' . Html::e($site->absoluteUrl('plugins/booking/assets/js/embed.js')) . '" async></script>'
            : '';

        return '<iframe class="sb-embed-frame" src="' . Html::e($src) . '" data-kohevo-booking'
            . ' title="' . Html::e(Html::t('booking_embed_iframe_title', 'Book an appointment')) . '"'
            . ' style="min-height:' . $min . 'px" loading="lazy"></iframe>'
            . $helper;
    }
}
