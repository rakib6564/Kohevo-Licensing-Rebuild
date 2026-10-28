<?php
/**
 * Membership — `membership.member_links` Studio widget: Sign in, Join and My membership buttons
 * into the customer portal (the same addresses the membership landing page uses).
 *
 * Public Studio pages are anonymous and cacheable, so the block never shows who is signed in; the
 * portal sends a signed-in visitor on from the sign-in page.
 */

declare(strict_types=1);

namespace Slate\Plugin\Membership\Studio;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class MemberLinksRenderer implements BlockRendererInterface
{
    /** Where a new member goes after signing up or in: the plan list in their portal. */
    private const NEXT = '/member?view=plans';

    public function type(): string
    {
        return 'membership.member_links';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public static function joinUrl(BlockRenderScope $scope): string
    {
        return $scope->site()->absoluteUrl('customer/register.php') . '?next=' . rawurlencode(self::NEXT);
    }

    public function render(BlockRenderScope $scope): string
    {
        $site = $scope->site();
        $label = static fn(string $prop, string $key, string $fallback): string
            => $scope->string($prop) !== '' ? $scope->string($prop) : Html::t($key, $fallback);

        $links = '<a class="sb-button sb-button--secondary" href="' . Html::e($site->absoluteUrl('customer/login.php') . '?next=' . rawurlencode(self::NEXT)) . '">'
            . Html::e($label('signin_label', 'studio_member_signin', 'Sign in')) . '</a>';
        if ($scope->bool('show_join', true)) {
            $links .= ' <a class="sb-button sb-button--primary" href="' . Html::e(self::joinUrl($scope)) . '">'
                . Html::e($label('join_label', 'studio_join_now', 'Join now')) . '</a>';
        }
        if ($scope->bool('show_account', false)) {
            $links .= ' <a class="sb-button sb-button--secondary" href="' . Html::e($site->absoluteUrl('member')) . '">'
                . Html::e($label('account_label', 'studio_member_account', 'My membership')) . '</a>';
        }

        return '<p class="sb-button-row sb-member-links">' . $links . '</p>';
    }
}
