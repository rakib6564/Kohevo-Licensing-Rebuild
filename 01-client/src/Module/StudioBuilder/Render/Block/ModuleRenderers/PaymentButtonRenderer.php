<?php
/**
 * Kohevo Studio — `payment.button` renderer: a pay button that opens the payment page of a gateway the site offers
 * (Stripe Checkout, PayPal), for a set amount or one the visitor types within a range (an amount box before the button),
 * once or every month. When more than one gateway is offered, each gets its own submit button (`gw`).
 *
 * The page is anonymous and cacheable, so the amount cannot be looked up when the visitor clicks: the form carries its
 * terms and a signature only this server can make (Payment/PaymentOffer), and the pay endpoint creates the checkout
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
    /**
     * Test seam: stands in for the payment layer's lookup, `fn(list<string> $needs): array<string, string>` (gateway id
     * => label, preferred first). Null (always, outside tests) asks Payments::choices().
     */
    public static ?\Closure $choices = null;

    public function type(): string
    {
        return 'payment.button';
    }

    /** Dynamic: whether (and how) the button shows depends on the gateways set up, which no compiled page would notice. */
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
        if ($offer === null || self::gateways($offer) === []) {
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
        // The first gateway takes the button's own label; any other gets "Pay with <name>" beside it.
        $buttons = '';
        foreach (self::gateways($offer) as $id => $name) {
            $buttons .= $buttons === ''
                ? '<button type="submit" name="gw" value="' . Html::e($id) . '" class="sb-button sb-button--primary sb-pay__button">' . Html::e($label) . '</button>'
                : '<button type="submit" name="gw" value="' . Html::e($id) . '" class="sb-button sb-button--secondary sb-pay__button sb-pay__button--alt">'
                    . Html::e(sprintf(Html::t('studio_pay_with', 'Pay with %s'), $name)) . '</button>';
        }
        $multi = count(self::gateways($offer)) > 1;
        return '<form class="sb-pay' . ($offer['h'] > 0 ? ' sb-pay--amount' : '') . ($multi ? ' sb-pay--choice' : '') . '" method="post" action="' . Html::e($action) . '">' . $hidden
            . $this->amountBox($scope, $offer)
            . $buttons
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

    /**
     * Gateways that can take this offer (id => short name), the site's preferred one first: set up, offered for website
     * payment buttons, and able to charge monthly when the offer is monthly. Empty: visitors would reach a button that
     * cannot work, so none is shown.
     *
     * @param array{f: string} $offer
     * @return array<string, string>
     */
    private static function gateways(array $offer): array
    {
        $needs = $offer['f'] === 'month' ? ['hosted', 'recurring'] : ['hosted'];
        $choices = self::$choices !== null
            ? (self::$choices)($needs)
            : (class_exists('Payments') ? \Payments::choices('studio-builder', $needs) : []);
        $out = [];
        foreach (array_keys($choices) as $id) {
            $out[$id] = match ($id) { 'stripe' => 'Stripe', 'paypal' => 'PayPal', default => ucfirst($id) };
        }
        return $out;
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
        $ways = self::gateways(['f' => $offer === null ? 'once' : $offer['f']]);
        $note = $ways !== []
            ? sprintf(Html::t('studio_pay_card_note_gateways', 'Visitors who click this pay on %s. The button takes payments once the page is published.'), implode(' / ', $ways))
            : Html::t('studio_pay_not_ready_gateways', 'No payment gateway is set up for website buttons yet, so visitors will not see this button. Connect Stripe or PayPal under Payment gateways.');
        return '<div class="sb-pay-card sb-embed-card">'
            . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_pay_kind', 'Payment')) . '</p>'
            . '<p class="sb-pay-card__button"><span class="sb-button sb-button--primary">' . Html::e($label) . '</span></p>'
            . '<p class="sb-pay-card__summary">' . Html::e($summary) . '</p>'
            . '<p class="sb-embed-card__note">' . Html::e($note) . '</p>'
            . '</div>';
    }
}
