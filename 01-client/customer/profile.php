<?php
/**
 * Slate — customer account page.
 *
 * Split out of customer/index.php when the portal gained navigation. The
 * profile form used to occupy the widest column of the dashboard, which put
 * the least-used thing on the page in the most prominent slot; now the
 * dashboard is an overview and this is its own destination, mirroring admin
 * where the dashboard summarises and Settings is a page of its own.
 */
require_once dirname(__DIR__) . '/config.php';
require_once SLATE_ROOT . '/includes/portal_shell.php';

Auth::requireCustomer();

$cust = Auth::customer();
$row  = Database::row(
    "SELECT * FROM customers WHERE id = ? AND tenant_id = ?",
    [(int) $cust['id'], current_tenant_id()]
);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_profile') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $name  = trim((string) ($_POST['name']  ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        Database::update('customers', [
            'name'  => $name  !== '' ? $name  : null,
            'phone' => $phone !== '' ? $phone : null,
        ], 'id = ? AND tenant_id = ?', [(int) $cust['id'], current_tenant_id()]);

        $_SESSION['slate_customer']['name'] = $name;
        AuditLog::record('customer.profile_updated', (string) $cust['id']);

        $flash = ['type' => 'success', 'msg' => __('portal_profile_saved', 'Profile updated.')];
        $row['name']  = $name;
        $row['phone'] = $phone;
    }
}

$verified = !empty($row['email_verified']);

$portal   = \Slate\Services\Portal\CustomerPortal::forCustomer((int) $cust['id']);
$sections = $portal->accountSections();

$currentPortalNav = 'account';
slate_portal_shell_head(__('portal_account', 'Account'));
slate_portal_shell_open(['active' => 'account', 'area' => 'account']);
?>

<?php /* No page header: the nav already says which page this is, and repeating
         it pushed the first card below the fold for no information gain. */ ?>
<?php if ($flash): ?>
    <div class="mapp-flash mapp-flash--<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="dash">
    <div class="dash-col">
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_your_details', 'Your details') ?></p>
            <form method="post" autocomplete="on" class="mform">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update_profile">

                <div class="field">
                    <label class="field-label" for="name"><?= __('name', 'Name') ?></label>
                    <input type="text" id="name" name="name" maxlength="120" value="<?= e($row['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="phone"><?= __('phone', 'Phone') ?></label>
                    <input type="tel" id="phone" name="phone" maxlength="40" value="<?= e($row['phone'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
                    <input type="email" id="email" value="<?= e($row['email']) ?>" disabled>
                    <div class="field-hint"><?= __('portal_email_locked', 'Email changes aren\'t supported yet — contact support.') ?></div>
                </div>

                <button type="submit" class="mbtn mbtn-primary"><?= __('portal_save', 'Save changes') ?></button>
            </form>
        </section>
    </div>

    <div class="dash-col">
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_account_status', 'Account') ?></p>
            <div class="kvr">
                <span class="kvr-k"><?= __('email', 'Email') ?></span>
                <span class="kvr-v">
                    <span class="pill <?= $verified ? 'pill-green' : 'pill-amber' ?>">
                        <?= $verified ? __('verified', 'Verified') : __('unverified', 'Unverified') ?>
                    </span>
                </span>
            </div>
            <div class="kvr">
                <span class="kvr-k"><?= __('portal_joined', 'Joined') ?></span>
                <span class="kvr-v"><?= e(I18n::localDate('j M Y', strtotime($row['created_at'] ?? 'now'))) ?></span>
            </div>
            <?php if (!empty($row['last_login_at'])): ?>
            <div class="kvr">
                <span class="kvr-k"><?= __('portal_last_sign_in', 'Last sign in') ?></span>
                <span class="kvr-v"><?= e(I18n::localDate('j M Y, H:i', strtotime($row['last_login_at']))) ?></span>
            </div>
            <?php endif; ?>
        </section>

        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('portal_security', 'Security') ?></p>
            <p style="margin:0 0 16px;font-size:14px;color:var(--m-muted)">
                <?= __('portal_password_help', 'To change your password, sign out and use the reset link on the sign-in page.') ?>
            </p>
            <a class="mbtn mbtn-ghost" href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>">
                <?= __('sign_out', 'Sign out') ?>
            </a>
        </section>

        <?php foreach ($sections as $sec): ?>
            <section class="pcard">
                <?php if (!empty($sec['title'])): ?>
                    <p class="pcard-eyebrow"><?= e($sec['title']) ?></p>
                <?php endif; ?>
                <?php
                if (isset($sec['render']) && is_callable($sec['render'])) {
                    echo ($sec['render'])((int) $cust['id'], $row);
                } elseif (!empty($sec['html'])) {
                    echo $sec['html'];
                }
                ?>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<?php slate_portal_shell_close(); ?>
