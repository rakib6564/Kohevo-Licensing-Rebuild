<?php
/**
 * Kohevo Studio — `payment.button` renderer: a pay button that opens Stripe Checkout, for a set amount or one the visitor
 * types within a range (an amount box before the button), once or every month.
 *
 * The page is anonymous and cacheable, so the amount cannot be looked up when the visitor clicks: the form carries its
 * terms and a signature only this server can make (Payment/PaymentOffer), and the pay endpoint creates the Stripe session
 * from those signed terms alone. The authoring canvas never receives a form: it shows a card with what visitors will see.
 * Only a PUBLIC render signs an offer, bound to this block and an expiry: a preview shows the button without a form, so
 * a price that exists only in a draft can never be paid (the endpoint also checks the published block, see PayRequest).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Payment\PaymentOffer;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class PaymentButtonRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'payment.button';
    }

    /** Dynamic: whether the button shows depends on Stripe being set up, which no compiled page would notice. */
    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $label = $scope->string('label', 'Pay now');
        $label = $label !== '' ? $label : Html::t('studio_pay_now', 'Pay now');
        $offer = PaymentOffer::fromProps($scope->props(), $scope->context()->tenantId);

        if ($scope->mode() === RenderMode::Editor) {
            return $this->card($label, $offer);
        }
        if ($offer === null || !self::stripeReady()) {
            return $scope->showsDiagnostics() ? $this->card($label, $offer) : '';
        }
        if ($scope->mode() !== RenderMode::Public) {
            // A preview looks like the page but never carries a payable form.
            return '<div class="sb-pay"><button type="button" class="sb-button sb-button--primary sb-pay__button" disabled title="'
                . Html::e(Html::t('studio_pay_preview_note', 'Payments work on the published page.')) . '">' . Html::e($label) . '</button></div>';
        }
        $offer = PaymentOffer::bind($offer, $scope->nodeId(), time());
        if ($offer === null) {
            return '';
        }
        $signature = PaymentOffer::sign($offer, static fn(string $context, string $value): ?string => function_exists('slate_sign') ? \slate_sign($context, $value) : null);
        if ($signature === null) {
            return '';
        }

        $action = $scope->site()->absoluteUrl('plugins/studio-builder/public/pay.php');
        $hidden = '';
        foreach (['t' => $offer['t'], 'a' => $offer['a'], 'c' => $offer['c'], 'd' => $offer['d'], 's' => $offer['s'], 'f' => $offer['f'], 'h' => $offer['h'], 'n' => $offer['n'], 'x' => $offer['x'], 'g' => $signature] as $name => $value) {
            $hidden .= '<input type="hidden" name="' . $name . '" value="' . Html::e((string) $value) . '">';
        }
        return '<form class="sb-pay' . ($offer['h'] > 0 ? ' sb-pay--amount' : '') . '" method="post" action="' . Html::e($action) . '">' . $hidden
            . $this->amountBox($scope, $offer)
            . '<button type="submit" class="sb-button sb-button--primary sb-pay__button">' . Html::e($label) . '</button>'
            . '</form>';
    }

    /**
     * The amount box of an offer that lets the visitor choose (none for a set amount). The browser keeps the typed amount
     * within the signed range; the endpoint checks it again (PaymentOffer::visitorCents).
     *
     * @param array{a: int, c: string, f: string, h: int, n: string} $offer
     */
    private function amountBox(BlockRenderScope $scope, array $offer): string
    {
        if ($offer['h'] <= 0) {
            return '';
        }
        $id = 'sb-pay-' . $offer['n'];
        $label = $scope->string('amount_label', '');
        if ($label === '') {
            $label = $offer['f'] === 'month'
                ? Html::t('studio_pay_amount_month', 'Amount per month') . ' (' . $offer['c'] . ')'
                : Html::t('studio_pay_amount', 'Amount') . ' (' . $offer['c'] . ')';
        }
        $min = number_format($offer['a'] / 100, 2, '.', '');
        return '<label class="sb-pay__amount" for="' . Html::e($id) . '"><span class="sb-pay__amount-label">' . Html::e($label) . '</span>'
            . '<input class="sb-pay__amount-input" id="' . Html::e($id) . '" name="v" type="number" inputmode="decimal" required'
            . ' min="' . $min . '" max="' . number_format($offer['h'] / 100, 2, '.', '') . '" step="0.01" value="' . rtrim(rtrim($min, '0'), '.') . '">'
            . '</label>';
    }

    /** The Stripe plugin is active and has keys: otherwise a visitor would reach a button that cannot work. */
    private static function stripeReady(): bool
    {
        return class_exists('StripePaymentAPI') && \StripePaymentAPI::isConfigured();
    }

    /** @param array{t: int, a: int, c: string, d: string, s: string, f: string, h: int}|null $offer */
    private function card(string $label, ?array $offer): string
    {
        $summary = $offer === null
            ? Html::t('studio_pay_incomplete', 'Set an amount from 0.50 up and a currency in the Inspector (when visitors choose, a highest amount above it).')
            : ($offer['h'] > 0
                ? Html::t('studio_pay_range', 'Visitor chooses') . ' ' . PaymentOffer::display($offer['a'], $offer['c']) . ' – ' . PaymentOffer::display($offer['h'], $offer['c'])
                : PaymentOffer::display($offer['a'], $offer['c']))
              . ($offer['f'] === 'month' ? ' ' . Html::t('studio_pay_per_month', 'per month') : '')
              . ($offer['d'] !== '' ? ' · ' . $offer['d'] : '');
        $note = self::stripeReady()
            ? Html::t('studio_pay_card_note', 'Visitors who click this go to a Stripe Checkout page. The button takes payments once the page is published.')
            : Html::t('studio_pay_not_ready', 'Stripe is not set up yet, so visitors will not see this button. Add your Stripe keys in the Stripe Payment settings.');
        return '<div class="sb-pay-card sb-embed-card">'
            . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_pay_kind', 'Payment')) . '</p>'
            . '<p class="sb-pay-card__button"><span class="sb-button sb-button--primary">' . Html::e($label) . '</span></p>'
            . '<p class="sb-pay-card__summary">' . Html::e($summary) . '</p>'
            . '<p class="sb-embed-card__note">' . Html::e($note) . '</p>'
            . '</div>';
    }
}
