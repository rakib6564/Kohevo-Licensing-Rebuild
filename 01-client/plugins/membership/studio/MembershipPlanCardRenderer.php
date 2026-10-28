<?php
/**
 * Membership — `membership.plan` Studio widget: one plan as a card (name, description, price) with
 * a Join button onto that plan in the member portal. The card links; the portal sells (it owns the
 * sign-in gate, profile completion, insurance add-ons and the Stripe hand-off), like the plan cards
 * of `MembershipAPI::renderPlanCards()`.
 */

declare(strict_types=1);

namespace Slate\Plugin\Membership\Studio;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\CatalogFormat;
use Slate\Module\StudioBuilder\Render\Html;

final class MembershipPlanCardRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'membership.plan';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->hasBinding('plan') ? $scope->rows('plan') : [];
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row) || !is_int($row['id'] ?? null)) {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_plan_card_pick', 'Choose a plan in the Inspector.')) . '</div>'
                : '';
        }

        $desc = CatalogFormat::str($row, 'description');
        $price = $scope->bool('show_price', true) ? CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null) : '';
        $cta = $scope->string('cta_label') !== '' ? $scope->string('cta_label') : Html::t('studio_join_now', 'Join now');

        return '<div class="sb-catalog sb-catalog--plan">'
            . '<h3 class="sb-catalog__name">' . Html::e(CatalogFormat::str($row, 'name')) . '</h3>'
            . ($desc !== '' ? '<p class="sb-catalog__description">' . Html::text($desc) . '</p>' : '')
            . ($price !== '' ? '<p class="sb-catalog__meta"><span class="sb-catalog__price">' . Html::e($price) . '</span></p>' : '')
            . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('member') . '?view=plans&plan=' . $row['id']) . '">' . Html::e($cta) . '</a></p>'
            . '</div>';
    }
}
