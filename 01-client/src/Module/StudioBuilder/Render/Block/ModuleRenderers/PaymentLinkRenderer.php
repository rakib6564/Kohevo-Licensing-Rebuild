<?php
/**
 * Kohevo Studio — `payment.link` renderer: a button to a Stripe-hosted Payment Link or Buy Button page.
 *
 * Nothing is processed here. The URL is re-checked against Payment/PaymentLinkUrl at output, so even a document that
 * somehow carried another address renders no link.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Payment\PaymentLinkUrl;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class PaymentLinkRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'payment.link';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $label = $scope->string('label', 'Pay now');
        $label = $label !== '' ? $label : Html::t('studio_pay_now', 'Pay now');
        $url = $scope->string('url');
        $ok = PaymentLinkUrl::isAllowed($url);

        if ($scope->mode() === RenderMode::Editor) {
            return '<div class="sb-pay-card sb-embed-card">'
                . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_pay_link_kind', 'Stripe link')) . '</p>'
                . '<p class="sb-pay-card__button"><span class="sb-button sb-button--primary">' . Html::e($label) . '</span></p>'
                . '<p class="sb-embed-card__note">' . Html::e($ok
                    ? Html::t('studio_pay_link_ok', 'Visitors who click this go to your Stripe page.')
                    : Html::t('studio_pay_link_missing', 'Paste a Stripe Payment Link (https://buy.stripe.com/...) in the Inspector.')) . '</p>'
                . '</div>';
        }
        if (!$ok) {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_pay_link_missing', 'Paste a Stripe Payment Link (https://buy.stripe.com/...) in the Inspector.')) . '</div>'
                : '';
        }
        return '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($url) . '" rel="noopener noreferrer">' . Html::e($label) . '</a></p>';
    }
}
