<?php
/**
 * Slate — customer portal home.
 *
 * An overview, not a form: greeting, the headline numbers plugins contribute,
 * and one summary card per plugin that has something to say about this
 * customer. Profile editing moved to customer/profile.php when the portal
 * gained navigation — mirroring admin, where the dashboard summarises and
 * settings is its own page.
 *
 * Plugins reach this page three ways, all of which predate the shell except
 * the first: `customer_nav_items` (a destination in the shared nav),
 * `customer_dashboard_kpis` (the stat row) and `customer_dashboard_widgets`
 * (a card in the activity column).
 */
require_once dirname(__DIR__) . '/config.php';
require_once SLATE_ROOT . '/includes/portal_shell.php';

Auth::requireCustomer();

$cust   = Auth::customer();
$cid    = (int) ($cust['id'] ?? 0);
$portal = \Slate\Services\Portal\CustomerPortal::forCustomer($cid);
$row    = $portal->customer();

$widgets = $portal->widgets('home');
$kpis    = $portal->stats();

$verified  = !empty($row['email_verified']);
$firstName = trim((string) ($row['name'] ?? ''));
if ($firstName !== '') { $firstName = explode(' ', $firstName)[0]; }

$hour     = (int) date('G');
$greeting = $hour < 12 ? __('good_morning', 'Good morning')
          : ($hour < 18 ? __('good_afternoon', 'Good afternoon') : __('good_evening', 'Good evening'));

$nav   = $portal->navigation('home');
$links = array_values(array_filter(
    $nav['items'],
    static fn (array $i): bool => !in_array($i['slug'], ['home', 'account'], true)
));

$currentPortalNav = 'home';
slate_portal_shell_head(__('portal_your_account', 'Your account'));
slate_portal_shell_open(['active' => 'home', 'area' => 'home']);
?>

<?php
slate_portal_welcome([
    'eyebrow' => __('portal_overview', 'Overview'),
    'title'   => $greeting,
    'name'    => $firstName,
    'sub'     => __('portal_hero_sub', 'Here\'s a quick snapshot of your account today.'),
    'status'  => $verified
        ? ['label' => __('portal_account_active', 'Account active'), 'tone' => 'green']
        : ['label' => __('unverified', 'Email unverified'),          'tone' => 'amber'],
    'clock'   => true,
]);
?>

<?php if (!$verified): ?>
    <div class="mapp-flash mapp-flash--warning" role="status">
        <?= __('portal_verify_email', 'Your email isn\'t verified yet. Check your inbox for the link we sent when you registered.') ?>
    </div>
<?php endif; ?>

<?php if ($kpis): ?>
    <?php
    // Plugin metrics lead. Account facts ("member since") are not KPIs and are
    // deliberately not used as filler — an empty row is better than a fake one,
    // and those facts live on the Account page now.
    //
    // Ordering matters more than it looks: plugins register in load order, so a
    // plugin with a zero ("Upcoming: 0") could otherwise push a plugin's
    // actionable number ("Tuition due: $162") out of the row entirely. Anything
    // flagged with a tone is asking for attention, so it sorts to the front.
    $kpis = array_values(array_filter($kpis, 'is_array'));
    usort($kpis, static fn (array $a, array $b): int =>
        (int) !empty($b['tone']) <=> (int) !empty($a['tone']));
    ?>
    <div>
        <?php slate_portal_stats(array_slice($kpis, 0, 4)); ?>
    </div>
<?php endif; ?>

<?php
// Bento, not a two-column grid: the cards here are independent and of wildly
// different heights (a plugin summary vs a three-row account card), and grid
// rows are as tall as their tallest cell — which strands whitespace beside
// every short card. Column flow packs them and ends the columns level.
?>
<div class="bento">
    <?php if ($widgets): ?>
        <?php foreach ($widgets as $widget): ?>
            <?= is_array($widget) ? ($widget['html'] ?? '') : $widget ?>
        <?php endforeach; ?>
    <?php else: ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_activity', 'Your activity') ?></p>
            <p style="margin:0;font-size:14px;color:var(--m-muted)">
                <?= __('portal_no_activity', 'Nothing here yet. When you book a service, enrol in a class or place an order, it\'ll show up here.') ?>
            </p>
        </section>
    <?php endif; ?>

    <?php if ($links): ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_go_to', 'Go to') ?></p>
            <div class="pnav">
                <?php foreach ($links as $i): ?>
                    <a class="pnav-row" href="<?= e($i['href']) ?>">
                        <span class="pnav-ic"><?= slate_icon($i['icon'], '') ?></span>
                        <span class="pnav-label"><?= e($i['label']) ?></span>
                        <?php if ($i['badge'] !== ''): ?>
                            <span class="pill pill-amber"><?= e($i['badge']) ?></span>
                        <?php endif; ?>
                        <span class="pnav-go"><?= slate_icon('chevron', 'icon-sm') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_account', 'Account') ?></p>
            <div class="kvr">
                <span class="kvr-k"><?= __('email', 'Email') ?></span>
                <span class="kvr-v" style="font-weight:600;font-size:14px"><?= e($row['email']) ?></span>
            </div>
            <div class="kvr">
                <span class="kvr-k"><?= __('portal_joined', 'Joined') ?></span>
                <span class="kvr-v"><?= e(I18n::localDate('M Y', strtotime($row['created_at'] ?? 'now'))) ?></span>
            </div>
            <p style="margin:16px 0 0">
                <a class="mbtn mbtn-ghost mbtn-block" href="<?= e(SLATE_URL) ?>/customer/profile.php">
                    <?= __('portal_manage_account', 'Manage account') ?>
                </a>
            </p>
    </section>
</div>

<?php slate_portal_shell_close(); ?>
