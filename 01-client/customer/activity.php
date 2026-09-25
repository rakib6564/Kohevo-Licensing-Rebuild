<?php
/**
 * Slate — Customer Portal Activity Timeline.
 *
 * Displays a combined, chronological activity stream across all active plugins:
 * - Bookings & appointments
 * - Membership plan subscriptions & activations
 * - Coaching check-ins & milestones
 * - Orders & invoices
 *
 * Route: /member/activity
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__) . '/config.php';
}

require_once SLATE_ROOT . '/includes/portal_shell.php';
require_once SLATE_ROOT . '/includes/portal_ui.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Portal\CustomerPortal;

Auth::requireCustomer();

$portal = CustomerPortal::current();
$events = $portal->activity(50);

$currentPortalNav = 'activity';
slate_portal_shell_head(__('portal_activity', 'Activity'));
slate_portal_shell_open(['active' => 'activity', 'area' => 'activity']);
?>

<div class="phero" style="margin-bottom:24px;">
    <div class="phero-main">
        <div class="phero-eyebrow"><?= e(__('portal_overview', 'Overview')) ?></div>
        <h1 class="phero-title"><?= e(__('portal_activity_timeline', 'Your activity')) ?></h1>
        <p class="phero-sub"><?= e(__('portal_activity_sub', 'A combined timeline of your appointments, memberships, and actions.')) ?></p>
    </div>
</div>

<div class="pcard">
    <?php if ($events): ?>
        <div class="timeline" style="display:flex;flex-direction:column;gap:18px;position:relative;padding-left:14px;">
            <?php foreach ($events as $ev): ?>
                <div class="timeline-item" style="display:flex;gap:14px;align-items:flex-start;">
                    <div class="timeline-icon" style="flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent);">
                        <?= slate_icon($ev['icon'] ?? 'clock', 'icon') ?>
                    </div>
                    <div class="timeline-body" style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;">
                            <strong style="font-size:14.5px;color:var(--m-ink,#15181E);"><?= e($ev['label']) ?></strong>
                            <span style="font-size:12px;color:var(--m-muted,#737886);">
                                <?= e(I18n::localDate('j M Y · g:i a', strtotime($ev['occurred_at']))) ?>
                            </span>
                        </div>
                        <?php if (!empty($ev['description'])): ?>
                            <p style="margin:4px 0 0;font-size:13.5px;color:var(--m-muted,#737886);line-height:1.4;">
                                <?= e($ev['description']) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($ev['href'])): ?>
                            <p style="margin:6px 0 0;">
                                <a href="<?= e($ev['href']) ?>" style="font-size:12.5px;font-weight:600;color:var(--accent);">
                                    <?= __('view_details', 'View details') ?> →
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align:center;padding:48px 16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:var(--accent-soft);color:var(--accent);display:inline-grid;place-items:center;margin-bottom:12px;">
                <?= slate_icon('clipboard', 'icon') ?>
            </div>
            <h3 style="margin:0 0 6px;font-size:16px;"><?= __('portal_no_activity', 'No activity recorded yet') ?></h3>
            <p style="margin:0;font-size:13.5px;color:var(--m-muted);">
                <?= __('portal_no_activity_sub', 'When you book appointments, subscribe to plans, or complete goals, your activity will appear here.') ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<?php slate_portal_shell_close(); ?>
